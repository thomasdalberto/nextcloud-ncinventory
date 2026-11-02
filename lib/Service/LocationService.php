<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Service;

use OCA\InventoryCheck\Db\CycleCampaignMapper;
use OCA\InventoryCheck\Db\ItemMapper;
use OCA\InventoryCheck\Db\Location;
use OCA\InventoryCheck\Db\LocationFavouriteMapper;
use OCA\InventoryCheck\Db\LocationMapper;
use OCA\InventoryCheck\Db\UniqueViolation;
use OCA\InventoryCheck\Exception\ConflictException;
use OCA\InventoryCheck\Exception\NotFoundException;
use OCA\InventoryCheck\Exception\ValidationException;
use OCP\IDBConnection;
use OCP\Lock\ILockingProvider;
use OCP\Lock\LockedException;

class LocationService
{
	public function __construct(
		private readonly IDBConnection $db,
		private readonly LocationMapper $locations,
		private readonly AccessControlService $access,
		private readonly Clock $clock,
		private readonly LocationAclService $locationAcl,
		private readonly CycleCampaignMapper $cycleCampaigns,
		private readonly ItemMapper $items,
		private readonly ILockingProvider $locking,
		private readonly LocationFavouriteMapper $favourites,
	) {
	}

	/**
	 * C3: field users only ever see locations {@see LocationAclService} grants
	 * them (directly or via group) once the toggle is on — office/admins and
	 * the disabled-by-default case are unrestricted (`$visible === null`).
	 *
	 * @return array{data: list<array<string, mixed>>, total: int, limit: int, offset: int}
	 */
	public function list(string $actorUid, ?bool $active, int $limit, int $offset, string $q = ''): array
	{
		$visible = $this->locationAcl->visibleLocationIds($actorUid);
		$result = $this->locations->search($active, $limit, $offset, $visible, $q);
		return [
			'data' => array_map(static fn (Location $l) => $l->toApi(), $result['data']),
			'total' => $result['total'],
			'limit' => $limit,
			'offset' => $offset,
		];
	}

	/**
	 * Unknown-or-not-visible → the same {@see NotFoundException}, so an IDOR
	 * probe for a location outside a field user's ACL cannot be distinguished
	 * from a location that simply does not exist.
	 *
	 * @return array<string, mixed>
	 */
	public function get(string $actorUid, int $id): array
	{
		if (!$this->locationAcl->canAccessLocation($actorUid, $id)) {
			throw new NotFoundException('unknown_location');
		}
		return $this->locations->findById($id)->toApi();
	}

	/**
	 * Wave D2: resolve by location.code (exact). Inactive / miss / ACL → code_not_found.
	 *
	 * @return array<string, mixed>
	 */
	public function byCode(string $actorUid, string $code): array
	{
		$code = CodeRules::trim($code);
		$loc = $this->locations->findByCode($code);
		if ($loc === null || !$loc->getActive()) {
			throw new NotFoundException('code_not_found');
		}
		if (!$this->locationAcl->canAccessLocation($actorUid, (int)$loc->getId())) {
			throw new NotFoundException('code_not_found');
		}
		return $loc->toApi();
	}

	/**
	 * @param array<string, mixed> $input
	 * @return array<string, mixed>
	 */
	public function create(string $actorUid, array $input): array
	{
		$this->access->requireWrite($actorUid);
		$code = CodeRules::trim((string)($input['code'] ?? ''));
		$name = CodeRules::trim((string)($input['name'] ?? ''));
		$kind = CodeRules::trim((string)($input['kind'] ?? 'other'));
		if ($kind === '') {
			$kind = 'other';
		}
		$notes = isset($input['notes']) ? CodeRules::trim((string)$input['notes']) : null;
		if ($notes === '') {
			$notes = null;
		}
		$this->validateMaster($code, $name, $kind, $notes);

		return $this->withCodesLock(function () use ($actorUid, $code, $name, $kind, $notes): array {
			if ($this->locations->findByCode($code) !== null) {
				throw new ConflictException('code_exists');
			}
			if (CodeRules::locationConflictsWithItems($code, $this->items->allCodePairs())) {
				throw new ConflictException('code_exists');
			}
			$now = $this->clock->now();
			$loc = new Location();
			$loc->setCode($code);
			$loc->setName($name);
			$loc->setKind($kind);
			$loc->setNotes($notes);
			$loc->setActive(true);
			$loc->setCreatedAt($now);
			$loc->setUpdatedAt($now);
			$loc->setCreatedBy($actorUid);
			try {
				return $this->locations->insert($loc)->toApi();
			} catch (\Throwable $e) {
				// Unique-index backstop for two concurrent creates of one code.
				throw UniqueViolation::is($e) ? new ConflictException('code_exists') : $e;
			}
		});
	}

	/**
	 * @param array<string, mixed> $input
	 * @return array<string, mixed>
	 */
	public function update(string $actorUid, int $id, array $input): array
	{
		$this->access->requireWrite($actorUid);
		$touchesCode = array_key_exists('code', $input);
		$run = fn (): array => $this->updateLocked($id, $input);
		return $touchesCode ? $this->withCodesLock($run) : $run();
	}

	/** @param array<string, mixed> $input */
	private function updateLocked(int $id, array $input): array
	{
		$this->db->beginTransaction();
		try {
			// Exclusive lock: serialises against movements' shared location
			// lock, so the zero-balance check cannot race a posting (S5).
			$loc = $this->locations->lockById($id, true);
			if (array_key_exists('code', $input)) {
				$code = CodeRules::trim((string)$input['code']);
				if (!CodeRules::isValidLocationCode($code)) {
					throw new ValidationException('invalid_code_format', '', [
						['field' => 'code', 'code' => 'invalid_code_format'],
					]);
				}
				$existing = $this->locations->findByCode($code);
				if ($existing !== null && (int)$existing->getId() !== $id) {
					throw new ConflictException('code_exists');
				}
				if (CodeRules::locationConflictsWithItems($code, $this->items->allCodePairs())) {
					throw new ConflictException('code_exists');
				}
				$loc->setCode($code);
			}
			if (array_key_exists('name', $input)) {
				$name = CodeRules::trim((string)$input['name']);
				if ($name === '' || mb_strlen($name) > 255) {
					throw new ValidationException('validation_failed', '', [['field' => 'name', 'code' => 'name_required']]);
				}
				$loc->setName($name);
			}
			if (array_key_exists('kind', $input)) {
				$kind = CodeRules::trim((string)$input['kind']);
				if (!CodeRules::isValidLocationKind($kind)) {
					throw new ValidationException('validation_failed', '', [['field' => 'kind', 'code' => 'validation_failed']]);
				}
				$loc->setKind($kind);
			}
			if (array_key_exists('notes', $input)) {
				$notes = $input['notes'] === null ? null : CodeRules::trim((string)$input['notes']);
				if ($notes !== null && mb_strlen($notes) > 10000) {
					throw new ValidationException('validation_failed', '', [['field' => 'notes', 'code' => 'validation_failed']]);
				}
				$loc->setNotes($notes === '' ? null : $notes);
			}
			if (array_key_exists('active', $input)) {
				$active = BoolParam::parse($input['active'], 'active');
				if (!$active && $loc->getActive()) {
					if ($this->locations->hasNonZeroBalance($id)) {
						throw new ConflictException('location_has_stock');
					}
					if ($this->cycleCampaigns->countOpenForLocation($id) > 0) {
						throw new ConflictException('location_in_open_stocktake');
					}
				}
				$loc->setActive($active);
			}
			$loc->setUpdatedAt($this->clock->now());
			$api = $this->locations->update($loc)->toApi();
			$this->db->commit();
			return $api;
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw UniqueViolation::is($e) ? new ConflictException('code_exists') : $e;
		}
	}

	public function delete(string $actorUid, int $id): void
	{
		$this->access->requireWrite($actorUid);
		$this->db->beginTransaction();
		try {
			// Exclusive lock conflicts with the shared lock held by any
			// in-flight movement, so the reference count is authoritative (S6).
			$loc = $this->locations->lockById($id, true);
			if ($this->locations->countMovementsReferencing($id) > 0) {
				throw new ConflictException('location_has_movements');
			}
			if ($this->cycleCampaigns->countOpenForLocation($id) > 0) {
				throw new ConflictException('location_in_open_stocktake');
			}
			// Orphaned grants/favourites would silently reattach to an
			// unrelated location after InnoDB auto-increment id reuse.
			$this->locationAcl->purgeLocation($id);
			$this->favourites->deleteAllForLocation($id);
			$this->locations->delete($loc);
			$this->db->commit();
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}
	}

	private function validateMaster(string $code, string $name, string $kind, ?string $notes): void
	{
		if (!CodeRules::isValidLocationCode($code)) {
			throw new ValidationException('invalid_code_format', '', [
				['field' => 'code', 'code' => 'invalid_code_format'],
			]);
		}
		if ($name === '' || mb_strlen($name) > 255) {
			throw new ValidationException('validation_failed', '', [['field' => 'name', 'code' => 'name_required']]);
		}
		if (!CodeRules::isValidLocationKind($kind)) {
			throw new ValidationException('validation_failed', '', [['field' => 'kind', 'code' => 'validation_failed']]);
		}
		if ($notes !== null && mb_strlen($notes) > 10000) {
			throw new ValidationException('validation_failed', '', [['field' => 'notes', 'code' => 'validation_failed']]);
		}
	}

	/**
	 * @template T
	 * @param callable(): T $fn
	 * @return T
	 */
	private function withCodesLock(callable $fn): mixed
	{
		$attempts = 0;
		while (true) {
			try {
				$this->locking->acquireLock(CodeRules::CODES_LOCK, ILockingProvider::LOCK_EXCLUSIVE);
				break;
			} catch (LockedException) {
				if (++$attempts >= 40) {
					throw new ConflictException('conflict');
				}
				usleep(25_000);
			}
		}
		try {
			return $fn();
		} finally {
			$this->locking->releaseLock(CodeRules::CODES_LOCK, ILockingProvider::LOCK_EXCLUSIVE);
		}
	}
}
