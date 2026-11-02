<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Service;

use OCA\InventoryCheck\Db\BalanceMapper;
use OCA\InventoryCheck\Db\CycleLineMapper;
use OCA\InventoryCheck\Db\Item;
use OCA\InventoryCheck\Db\ItemMapper;
use OCA\InventoryCheck\Db\LocationMapper;
use OCA\InventoryCheck\Db\UniqueViolation;
use OCA\InventoryCheck\Exception\ConflictException;
use OCA\InventoryCheck\Exception\NotFoundException;
use OCA\InventoryCheck\Exception\ValidationException;
use OCA\InventoryCheck\Service\WorkspaceSettings;
use OCP\IDBConnection;
use OCP\Lock\ILockingProvider;
use OCP\Lock\LockedException;

class ItemService
{
	public function __construct(
		private readonly IDBConnection $db,
		private readonly ItemMapper $items,
		private readonly BalanceMapper $balances,
		private readonly AccessControlService $access,
		private readonly Clock $clock,
		private readonly ILockingProvider $locking,
		private readonly LocationAclService $locationAcl,
		private readonly WorkspaceSettings $settings,
		private readonly CycleLineMapper $cycleLines,
		private readonly LocationMapper $locations,
		private readonly ?ItemPhotoService $photos = null,
	) {
	}

	/** @return array{data: list<array<string, mixed>>, total: int, limit: int, offset: int} */
	public function list(string $actorUid, string $q, ?bool $active, bool $lowStock, int $limit, int $offset): array
	{
		$idFilter = null;
		if ($lowStock) {
			$idFilter = $this->lowStockItemIds($actorUid);
			if ($idFilter === []) {
				return ['data' => [], 'total' => 0, 'limit' => $limit, 'offset' => $offset];
			}
		}
		$result = $this->items->search($q, $active, $limit, $offset, $idFilter);
		return [
			'data' => array_map(
				fn (Item $i) => $this->stripConfidentialItemFields($i->toApi(), $actorUid),
				$result['data'],
			),
			'total' => $result['total'],
			'limit' => $limit,
			'offset' => $offset,
		];
	}

	/**
	 * S10 predicate over active items — ids currently below reorder level.
	 * Wave C3: sum only over locations the actor may see.
	 *
	 * @return list<int>
	 */
	private function lowStockItemIds(string $actorUid): array
	{
		$visible = $this->locationAcl->visibleLocationIds($actorUid);
		$sums = $this->balances->sumQtyByItem($visible);
		$ids = [];
		foreach ($this->items->iterateActive() as $item) {
			$total = $sums[(int)$item->getId()] ?? 0;
			if (LowStockQuery::isLowStock(true, $item->getReorderLevel(), $total)) {
				$ids[] = (int)$item->getId();
			}
		}
		return $ids;
	}

	/**
	 * Field users/scanning devices may read the shared item catalog, but must
	 * not see cost-ish properties.
	 *
	 * @param array<string, mixed> $item
	 * @return array<string, mixed>
	 */
	private function stripConfidentialItemFields(array $item, string $actorUid): array
	{
		if ($this->access->canWrite($actorUid)) {
			return $item;
		}
		unset($item['supplierNote'], $item['lastPriceMinor']);
		return $item;
	}

	/** @return array<string, mixed> */
	public function get(string $actorUid, int $id): array
	{
		$item = $this->items->findById($id)->toApi();
		return $this->stripConfidentialItemFields($item, $actorUid);
	}

	/**
	 * S8 by-code with per-location balances (Wave C3: balances filtered to
	 * locations the actor may see — never leak hidden van stock via scan).
	 *
	 * @return array<string, mixed>
	 */
	public function byCode(string $actorUid, string $code): array
	{
		$code = CodeRules::trim($code);
		$item = $this->items->resolveByCode($code);
		if ($item === null) {
			throw new NotFoundException('code_not_found');
		}
		$api = $this->stripConfidentialItemFields($item->toApi(), $actorUid);
		$visible = $this->locationAcl->visibleLocationIds($actorUid);
		$bal = $this->balances->search((int)$item->getId(), null, false, 200, 0, false, $visible);
		$api['balances'] = array_map(static fn ($b) => $b->toApi(), $bal['data']);
		return $api;
	}

	/**
	 * @param array<string, mixed> $input
	 * @return array<string, mixed>
	 */
	public function create(string $actorUid, array $input): array
	{
		$this->access->requireWrite($actorUid);
		$sku = CodeRules::trim((string)($input['sku'] ?? ''));
		$scan = CodeRules::trim((string)($input['scanCode'] ?? $input['scan_code'] ?? ''));
		if ($scan === '') {
			$scan = $sku;
		}
		$name = CodeRules::trim((string)($input['name'] ?? ''));
		$uom = CodeRules::trim((string)($input['uom'] ?? 'pcs'));
		if ($uom === '') {
			$uom = 'pcs';
		}
		$desc = isset($input['description']) ? CodeRules::trim((string)$input['description']) : null;
		if ($desc === '') {
			$desc = null;
		}
		$reorder = isset($input['reorderLevel']) ? (int)$input['reorderLevel'] : (isset($input['reorder_level']) ? (int)$input['reorder_level'] : 0);
		$this->validateItemFields($sku, $scan, $name, $uom, $desc, $reorder);
		$trackMode = $this->parseTrackMode($input, 'none');
		$supplierNote = $this->parseSupplierNote($input);
		$lastPriceMinor = $this->parseLastPriceMinor($input);
		$targetStock = $this->parseTargetStock($input, $reorder);
		$defaultLocationId = $this->parseDefaultLocationId($input);

		return $this->withCodesLock(function () use ($actorUid, $sku, $scan, $name, $uom, $desc, $reorder, $trackMode, $supplierNote, $lastPriceMinor, $targetStock, $defaultLocationId): array {
			if (CodeRules::conflictsWithOthers(null, $sku, $scan, $this->items->allCodePairs())) {
				throw new ConflictException('code_exists');
			}
			if (CodeRules::conflictsWithLocationCodes($sku, $scan, $this->locations->allCodes())) {
				throw new ConflictException('code_exists');
			}
			$now = $this->clock->now();
			$item = new Item();
			$item->setSku($sku);
			$item->setScanCode($scan);
			$item->setName($name);
			$item->setDescription($desc);
			$item->setUom($uom);
			$item->setReorderLevel($reorder);
			$item->setTargetStock($targetStock);
			$item->setDefaultLocationId($defaultLocationId);
			$item->setActive(true);
			$item->setCreatedAt($now);
			$item->setUpdatedAt($now);
			$item->setCreatedBy($actorUid);
			$item->setTrackMode($trackMode);
			$item->setSupplierNote($supplierNote);
			$item->setLastPriceMinor($lastPriceMinor);
			try {
				return $this->items->insert($item)->toApi();
			} catch (\Throwable $e) {
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
		$touchesCodes = array_key_exists('sku', $input)
			|| array_key_exists('scanCode', $input)
			|| array_key_exists('scan_code', $input);

		$run = fn (): array => $this->updateLocked($actorUid, $id, $input);
		return $touchesCodes ? $this->withCodesLock($run) : $run();
	}

	public function delete(string $actorUid, int $id): void
	{
		$this->access->requireWrite($actorUid);
		$photoName = null;
		$this->db->beginTransaction();
		try {
			// Exclusive lock conflicts with the shared lock held by any
			// in-flight movement, so the reference count is authoritative (S6).
			$item = $this->items->lockById($id, true);
			if ($this->items->countMovementsReferencing($id) > 0) {
				throw new ConflictException('item_has_movements');
			}
			if ($this->cycleLines->countOpenCampaignsForItem($id) > 0) {
				throw new ConflictException('item_in_open_stocktake');
			}
			$photoName = $item->getPhotoName();
			$this->items->delete($item);
			$this->db->commit();
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}
		// Post-commit: the AppData blob must not outlive the item row.
		if ($photoName !== null && $photoName !== '') {
			$this->photos?->purgeFile($photoName);
		}
	}

	/** @return array<string, mixed> */
	private function updateLocked(string $actorUid, int $id, array $input): array
	{
		unset($actorUid);
		$this->db->beginTransaction();
		try {
			// Exclusive lock: serialises against movements' shared item lock,
			// so the zero-balance check below cannot race a posting (S5).
			$item = $this->items->lockById($id, true);
			$sku = $item->getSku();
			$scan = $item->getScanCode();
			if (array_key_exists('sku', $input)) {
				$sku = CodeRules::trim((string)$input['sku']);
			}
			if (array_key_exists('scanCode', $input) || array_key_exists('scan_code', $input)) {
				$scan = CodeRules::trim((string)($input['scanCode'] ?? $input['scan_code']));
			}
			if (array_key_exists('name', $input)) {
				$name = CodeRules::trim((string)$input['name']);
				if ($name === '' || mb_strlen($name) > 255) {
					throw new ValidationException('validation_failed', '', [['field' => 'name', 'code' => 'name_required']]);
				}
				$item->setName($name);
			}
			if (array_key_exists('description', $input)) {
				$desc = $input['description'] === null ? null : CodeRules::trim((string)$input['description']);
				if ($desc !== null && mb_strlen($desc) > 10000) {
					throw new ValidationException('validation_failed', '', [['field' => 'description', 'code' => 'validation_failed']]);
				}
				$item->setDescription($desc === '' ? null : $desc);
			}
			if (array_key_exists('uom', $input)) {
				$uom = CodeRules::trim((string)$input['uom']);
				if ($uom === '' || mb_strlen($uom) > 32) {
					throw new ValidationException('validation_failed', '', [['field' => 'uom', 'code' => 'validation_failed']]);
				}
				$item->setUom($uom);
			}
			if (array_key_exists('reorderLevel', $input) || array_key_exists('reorder_level', $input)) {
				$reorder = (int)($input['reorderLevel'] ?? $input['reorder_level']);
				if ($reorder < 0 || $reorder > QtyScale::maxStorage($this->settings)) {
					throw new ValidationException('validation_failed', '', [['field' => 'reorderLevel', 'code' => 'validation_failed']]);
				}
				$item->setReorderLevel($reorder);
			}
			if (array_key_exists('targetStock', $input) || array_key_exists('target_stock', $input)) {
				$item->setTargetStock($this->parseTargetStock($input, $item->getReorderLevel()));
			}
			if (array_key_exists('defaultLocationId', $input) || array_key_exists('default_location_id', $input)) {
				$item->setDefaultLocationId($this->parseDefaultLocationId($input));
			}
			if ($sku !== $item->getSku() || $scan !== $item->getScanCode()) {
				if (!CodeRules::isValidSku($sku) || !CodeRules::isValidScanCode($scan)) {
					$bad = [];
					if (!CodeRules::isValidSku($sku)) {
						$bad[] = ['field' => 'sku', 'code' => 'invalid_code_format'];
					}
					if (!CodeRules::isValidScanCode($scan)) {
						$bad[] = ['field' => 'scanCode', 'code' => 'invalid_code_format'];
					}
					throw new ValidationException('invalid_code_format', '', $bad);
				}
				if (CodeRules::conflictsWithOthers($id, $sku, $scan, $this->items->allCodePairs())) {
					throw new ConflictException('code_exists');
				}
				if (CodeRules::conflictsWithLocationCodes($sku, $scan, $this->locations->allCodes())) {
					throw new ConflictException('code_exists');
				}
				$item->setSku($sku);
				$item->setScanCode($scan);
			}
			if (array_key_exists('active', $input)) {
				$active = BoolParam::parse($input['active'], 'active');
				if (!$active && $item->getActive()) {
					if ($this->items->hasNonZeroBalance($id)) {
						throw new ConflictException('item_has_stock');
					}
					if ($this->cycleLines->countOpenCampaignsForItem($id) > 0) {
						throw new ConflictException('item_in_open_stocktake');
					}
				}
				$item->setActive($active);
			}
			if (array_key_exists('trackMode', $input) || array_key_exists('track_mode', $input)) {
				$nextMode = $this->parseTrackMode($input, $item->getTrackMode());
				$this->assertTrackModeChangeAllowed($id, $item->getTrackMode(), $nextMode);
				$item->setTrackMode($nextMode);
			}
			if (array_key_exists('supplierNote', $input) || array_key_exists('supplier_note', $input)) {
				$item->setSupplierNote($this->parseSupplierNote($input));
			}
			if (array_key_exists('lastPriceMinor', $input) || array_key_exists('last_price_minor', $input)) {
				$item->setLastPriceMinor($this->parseLastPriceMinor($input));
			}
			$item->setUpdatedAt($this->clock->now());
			$api = $this->items->update($item)->toApi();
			$this->db->commit();
			return $api;
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw UniqueViolation::is($e) ? new ConflictException('code_exists') : $e;
		}
	}

	/**
	 * @param array<string, mixed> $input
	 */
	private function parseSupplierNote(array $input): ?string
	{
		if (!array_key_exists('supplierNote', $input) && !array_key_exists('supplier_note', $input)) {
			return null;
		}
		$raw = $input['supplierNote'] ?? $input['supplier_note'];
		if ($raw === null) {
			return null;
		}
		$note = CodeRules::trim((string)$raw);
		if ($note === '') {
			return null;
		}
		if (mb_strlen($note) > 255) {
			throw new ValidationException('validation_failed', '', [['field' => 'supplierNote', 'code' => 'validation_failed']]);
		}
		return $note;
	}

	/**
	 * @param array<string, mixed> $input
	 */
	private function parseLastPriceMinor(array $input): ?int
	{
		if (!array_key_exists('lastPriceMinor', $input) && !array_key_exists('last_price_minor', $input)) {
			return null;
		}
		$raw = $input['lastPriceMinor'] ?? $input['last_price_minor'];
		if ($raw === null || $raw === '') {
			return null;
		}
		if (!is_numeric($raw)) {
			throw new ValidationException('validation_failed', '', [['field' => 'lastPriceMinor', 'code' => 'validation_failed']]);
		}
		$value = (int)$raw;
		if ($value < 0 || $value > 100_000_000) {
			throw new ValidationException('validation_failed', '', [['field' => 'lastPriceMinor', 'code' => 'validation_failed']]);
		}
		return $value;
	}

	/**
	 * @param array<string, mixed> $input
	 */
	private function parseTrackMode(array $input, string $default): string
	{
		if (!array_key_exists('trackMode', $input) && !array_key_exists('track_mode', $input)) {
			return $default;
		}
		$raw = CodeRules::trim((string)($input['trackMode'] ?? $input['track_mode']));
		if ($raw === '') {
			$raw = 'none';
		}
		if (!CodeRules::isValidTrackMode($raw)) {
			throw new ValidationException('validation_failed', '', [['field' => 'trackMode', 'code' => 'validation_failed']]);
		}
		return $raw;
	}

	/**
	 * Upgrading trackMode (none→lot|serial, lot→serial) while anonymous stock
	 * exists lets capacity checks ignore pre-flip qty and corrupt the ledger.
	 * Downgrades and same-mode writes stay allowed; inventur mid-campaign flips
	 * require zeroing stock first (then close still detects track_mode_changed).
	 */
	private function assertTrackModeChangeAllowed(int $itemId, string $from, string $to): void
	{
		if ($from === $to) {
			return;
		}
		$rank = ['none' => 0, 'lot' => 1, 'serial' => 2];
		$fromRank = $rank[$from] ?? 0;
		$toRank = $rank[$to] ?? 0;
		if ($toRank <= $fromRank) {
			return;
		}
		if ($this->items->hasNonZeroBalance($itemId)) {
			throw new ConflictException('track_mode_requires_zero_stock');
		}
	}

	/**
	 * Wave D4: optional order-up-to qty (storage units). Null clears.
	 *
	 * @param array<string, mixed> $input
	 */
	private function parseTargetStock(array $input, int $reorderLevel): ?int
	{
		if (!array_key_exists('targetStock', $input) && !array_key_exists('target_stock', $input)) {
			return null;
		}
		$raw = $input['targetStock'] ?? $input['target_stock'];
		if ($raw === null || $raw === '') {
			return null;
		}
		if (!is_numeric($raw)) {
			throw new ValidationException('validation_failed', '', [['field' => 'targetStock', 'code' => 'validation_failed']]);
		}
		$value = (int)$raw;
		$max = QtyScale::maxStorage($this->settings);
		if ($value < 0 || $value > $max) {
			throw new ValidationException('validation_failed', '', [['field' => 'targetStock', 'code' => 'validation_failed']]);
		}
		if ($value < $reorderLevel) {
			throw new ValidationException('validation_failed', '', [['field' => 'targetStock', 'code' => 'target_below_reorder']]);
		}
		return $value;
	}

	/**
	 * Wave D7: optional default putaway location. Null clears.
	 *
	 * @param array<string, mixed> $input
	 */
	private function parseDefaultLocationId(array $input): ?int
	{
		if (!array_key_exists('defaultLocationId', $input) && !array_key_exists('default_location_id', $input)) {
			return null;
		}
		$raw = $input['defaultLocationId'] ?? $input['default_location_id'];
		if ($raw === null || $raw === '' || (int)$raw === 0) {
			return null;
		}
		$id = (int)$raw;
		if ($id < 1) {
			throw new ValidationException('validation_failed', '', [['field' => 'defaultLocationId', 'code' => 'validation_failed']]);
		}
		try {
			$loc = $this->locations->findById($id);
		} catch (NotFoundException) {
			throw new ValidationException('validation_failed', '', [['field' => 'defaultLocationId', 'code' => 'unknown_location']]);
		}
		if (!$loc->getActive()) {
			throw new ValidationException('inactive_location', '', [['field' => 'defaultLocationId', 'code' => 'inactive_location']]);
		}
		return $id;
	}

	private function validateItemFields(string $sku, string $scan, string $name, string $uom, ?string $desc, int $reorder): void
	{
		if (!CodeRules::isValidSku($sku) || !CodeRules::isValidScanCode($scan)) {
			$bad = [];
			if (!CodeRules::isValidSku($sku)) {
				$bad[] = ['field' => 'sku', 'code' => 'invalid_code_format'];
			}
			if (!CodeRules::isValidScanCode($scan)) {
				$bad[] = ['field' => 'scanCode', 'code' => 'invalid_code_format'];
			}
			throw new ValidationException('invalid_code_format', '', $bad);
		}
		if ($name === '' || mb_strlen($name) > 255) {
			throw new ValidationException('validation_failed', '', [['field' => 'name', 'code' => 'name_required']]);
		}
		if (mb_strlen($uom) < 1 || mb_strlen($uom) > 32) {
			throw new ValidationException('validation_failed', '', [['field' => 'uom', 'code' => 'validation_failed']]);
		}
		if ($desc !== null && mb_strlen($desc) > 10000) {
			throw new ValidationException('validation_failed', '', [['field' => 'description', 'code' => 'validation_failed']]);
		}
		if ($reorder < 0 || $reorder > QtyScale::maxStorage($this->settings)) {
			throw new ValidationException('validation_failed', '', [['field' => 'reorderLevel', 'code' => 'validation_failed']]);
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
