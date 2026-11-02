<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Service;

use OCA\InventoryCheck\AppInfo\Application;
use OCA\InventoryCheck\Db\LocationMapper;
use OCA\InventoryCheck\Db\ScanDeviceMapper;
use OCA\InventoryCheck\Exception\NotFoundException;
use OCA\InventoryCheck\Exception\ValidationException;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCA\InventoryCheck\Service\WorkspaceSettings;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IUserManager;

/**
 * Wave C3 / device binding: optional per-location ACL.
 *
 * When disabled (default), all canUseApp users and scanners see every location.
 * When enabled:
 * - office/app-admin/system-admin still see all
 * - field users only see grants (uid or groups); zero grants → none
 * - device actors (`device:N`) with ≥1 grants → those locations only;
 *   with zero grants → none (fail closed — never org-wide)
 */
class LocationAclService
{
	public const KEY_ENABLED = 'location_acl_enabled';
	/** Retained for bootstrap/UI; empty device grants always deny when ACL is on. */
	public const KEY_DEVICES_STRICT = 'location_acl_devices_strict';
	public const TYPE_USER = 'user';
	public const TYPE_GROUP = 'group';
	public const TYPE_DEVICE = 'device';

	public function __construct(
		private readonly IDBConnection $db,
		private readonly WorkspaceSettings $settings,
		private readonly AccessControlService $access,
		private readonly IGroupManager $groupManager,
		private readonly IUserManager $userManager,
		private readonly LocationMapper $locations,
		private readonly ScanDeviceMapper $devices,
		private readonly WorkspaceContext $workspace,
	) {
	}

	public function isEnabled(): bool
	{
		return $this->settings->get(self::KEY_ENABLED, '0') === '1';
	}

	public function setEnabled(bool $enabled): void
	{
		$this->settings->set(self::KEY_ENABLED, $enabled ? '1' : '0');
	}

	public function isDevicesStrict(): bool
	{
		return $this->settings->get(self::KEY_DEVICES_STRICT, '0') === '1';
	}

	public function setDevicesStrict(bool $strict): void
	{
		$this->settings->set(self::KEY_DEVICES_STRICT, $strict ? '1' : '0');
	}

	/**
	 * null = unrestricted (caller may see all locations).
	 *
	 * @return list<int>|null
	 */
	public function visibleLocationIds(string $uid): ?array
	{
		if ($uid === '' || !$this->isEnabled()) {
			return null;
		}
		if (str_starts_with($uid, 'device:')) {
			return $this->visibleLocationIdsForDevice($uid);
		}
		if ($this->access->canWrite($uid)) {
			return null;
		}
		$ids = [];
		$qb = $this->db->getQueryBuilder();
		$qb->select('location_id')->from('iv_loc_acl')
			->where($qb->expr()->eq('subject_type', $qb->createNamedParameter(self::TYPE_USER)))
			->andWhere($qb->expr()->eq('subject_id', $qb->createNamedParameter($uid)));
		$this->workspace->apply($qb);
		$res = $qb->executeQuery();
		while (($row = $res->fetch()) !== false) {
			$ids[] = (int)$row['location_id'];
		}
		$res->closeCursor();

		$gids = [];
		$gQb = $this->db->getQueryBuilder();
		$gQb->selectDistinct('subject_id')->from('iv_loc_acl')
			->where($gQb->expr()->eq('subject_type', $gQb->createNamedParameter(self::TYPE_GROUP)));
		$this->workspace->apply($gQb);
		$gRes = $gQb->executeQuery();
		while (($grow = $gRes->fetch()) !== false) {
			$gid = (string)$grow['subject_id'];
			if ($gid !== '' && $this->groupManager->isInGroup($uid, $gid)) {
				$gids[] = $gid;
			}
		}
		$gRes->closeCursor();
		if ($gids !== []) {
			$qb2 = $this->db->getQueryBuilder();
			$qb2->select('location_id')->from('iv_loc_acl')
				->where($qb2->expr()->eq('subject_type', $qb2->createNamedParameter(self::TYPE_GROUP)))
				->andWhere($qb2->expr()->in('subject_id', $qb2->createNamedParameter($gids, IQueryBuilder::PARAM_STR_ARRAY)));
			$this->workspace->apply($qb2);
			$r2 = $qb2->executeQuery();
			while (($row = $r2->fetch()) !== false) {
				$ids[] = (int)$row['location_id'];
			}
			$r2->closeCursor();
		}
		return array_values(array_unique($ids));
	}

	/**
	 * Device grants: empty → [] (fail closed). Unbound scanners must never
	 * inherit org-wide access when location ACL is enabled — bind each device
	 * or leave ACL off. (Legacy “unrestricted BC” when strict was off is gone.)
	 *
	 * @return list<int>|null
	 */
	private function visibleLocationIdsForDevice(string $uid): ?array
	{
		$raw = substr($uid, strlen('device:'));
		if ($raw === '' || !ctype_digit($raw) || (int)$raw <= 0) {
			return [];
		}
		$subjectId = (string)(int)$raw;
		$ids = [];
		$qb = $this->db->getQueryBuilder();
		$qb->select('location_id')->from('iv_loc_acl')
			->where($qb->expr()->eq('subject_type', $qb->createNamedParameter(self::TYPE_DEVICE)))
			->andWhere($qb->expr()->eq('subject_id', $qb->createNamedParameter($subjectId)));
		$this->workspace->apply($qb);
		$res = $qb->executeQuery();
		while (($row = $res->fetch()) !== false) {
			$ids[] = (int)$row['location_id'];
		}
		$res->closeCursor();
		if ($ids === []) {
			return [];
		}
		return array_values(array_unique($ids));
	}

	public function canAccessLocation(string $uid, int $locationId): bool
	{
		$visible = $this->visibleLocationIds($uid);
		if ($visible === null) {
			return true;
		}
		return in_array($locationId, $visible, true);
	}

	public function assertCanAccess(string $uid, int $locationId): void
	{
		if (!$this->canAccessLocation($uid, $locationId)) {
			throw new NotFoundException('unknown_location');
		}
	}

	/** GDPR / user-delete: drop every per-user location ACL grant for a deleted UID. */
	public function purgeUser(string $userId): void
	{
		if ($userId === '') {
			return;
		}
		if (!$this->db->tableExists('iv_loc_acl')) {
			return;
		}
		try {
			$del = $this->db->getQueryBuilder();
			$del->delete('iv_loc_acl')
				->where($del->expr()->eq('subject_type', $del->createNamedParameter(self::TYPE_USER)))
				->andWhere($del->expr()->eq('subject_id', $del->createNamedParameter($userId)))
				->executeStatement();
		} catch (\Throwable $e) {
			$msg = $e->getMessage();
			if (stripos($msg, 'iv_loc_acl') !== false
				|| stripos($msg, 'Base table or view not found') !== false
				|| stripos($msg, 'no such table') !== false) {
				return;
			}
			throw $e;
		}
	}

	/**
	 * Drop every per-group location ACL grant for a deleted GID. Without this a
	 * stale grant silently re-activates if the same gid is ever recreated.
	 */
	public function purgeGroup(string $groupId): void
	{
		if ($groupId === '') {
			return;
		}
		if (!$this->db->tableExists('iv_loc_acl')) {
			return;
		}
		try {
			$del = $this->db->getQueryBuilder();
			$del->delete('iv_loc_acl')
				->where($del->expr()->eq('subject_type', $del->createNamedParameter(self::TYPE_GROUP)))
				->andWhere($del->expr()->eq('subject_id', $del->createNamedParameter($groupId)))
				->executeStatement();
		} catch (\Throwable $e) {
			$msg = $e->getMessage();
			if (stripos($msg, 'iv_loc_acl') !== false
				|| stripos($msg, 'Base table or view not found') !== false
				|| stripos($msg, 'no such table') !== false) {
				return;
			}
			throw $e;
		}
	}

	/**
	 * Drop every grant pointing at a deleted location. Stale location_ids would
	 * silently reattach to an unrelated location after InnoDB id reuse.
	 */
	public function purgeLocation(int $locationId): void
	{
		if ($locationId <= 0) {
			return;
		}
		if (!$this->db->tableExists('iv_loc_acl')) {
			return;
		}
		$del = $this->db->getQueryBuilder();
		$del->delete('iv_loc_acl')
			->where($del->expr()->eq('location_id', $del->createNamedParameter($locationId, IQueryBuilder::PARAM_INT)));
		$this->workspace->apply($del);
		$del->executeStatement();
	}

	/** Drop grants when a scanner slot is deleted. */
	public function purgeDevice(int $deviceId): void
	{
		if ($deviceId <= 0) {
			return;
		}
		$del = $this->db->getQueryBuilder();
		$del->delete('iv_loc_acl')
			->where($del->expr()->eq('subject_type', $del->createNamedParameter(self::TYPE_DEVICE)))
			->andWhere($del->expr()->eq('subject_id', $del->createNamedParameter((string)$deviceId)));
		$this->workspace->apply($del);
		$del->executeStatement();
	}

	/**
	 * Validate and dedupe location ids (throws if any id is invalid / missing).
	 * Used before createDevice bind so bad payloads fail before a slot exists.
	 *
	 * @param list<mixed> $locationIds
	 * @return list<int>
	 */
	public function normalizeLocationIds(array $locationIds): array
	{
		$clean = [];
		foreach ($locationIds as $locId) {
			$n = (int)$locId;
			if ($n <= 0) {
				throw new ValidationException('validation_failed', '', [
					['field' => 'locationIds', 'code' => 'validation_failed'],
				]);
			}
			$this->locations->findById($n);
			$clean[] = $n;
		}
		return array_values(array_unique($clean));
	}

	/**
	 * Replace every location grant for one subject (validate-then-commit).
	 * Empty $locationIds clears that subject's grants.
	 *
	 * @param list<mixed> $locationIds
	 */
	public function setForSubject(string $subjectType, string $subjectId, array $locationIds): void
	{
		$type = strtolower(trim($subjectType));
		$id = trim($subjectId);
		if (!in_array($type, [self::TYPE_USER, self::TYPE_GROUP, self::TYPE_DEVICE], true) || $id === '') {
			throw new ValidationException('validation_failed', '', [
				['field' => 'subjectType', 'code' => 'validation_failed'],
			]);
		}
		$this->assertSubjectExists($type, $id);
		$clean = $this->normalizeLocationIds($locationIds);
		if ($type === self::TYPE_DEVICE) {
			$id = (string)(int)$id;
		}

		$this->db->beginTransaction();
		try {
			$del = $this->db->getQueryBuilder();
			$del->delete('iv_loc_acl')
				->where($del->expr()->eq('subject_type', $del->createNamedParameter($type)))
				->andWhere($del->expr()->eq('subject_id', $del->createNamedParameter($id)));
			$this->workspace->apply($del);
			$del->executeStatement();
			foreach ($clean as $locId) {
				$ins = $this->db->getQueryBuilder();
				$ins->insert('iv_loc_acl')->values([
					'workspace_id' => $ins->createNamedParameter($this->workspace->id(), IQueryBuilder::PARAM_INT),
					'subject_type' => $ins->createNamedParameter($type),
					'subject_id' => $ins->createNamedParameter($id),
					'location_id' => $ins->createNamedParameter($locId, IQueryBuilder::PARAM_INT),
				])->executeStatement();
			}
			$this->db->commit();
		} catch (\Throwable $e) {
			if ($this->db->inTransaction()) {
				$this->db->rollBack();
			}
			throw $e;
		}
	}

	/**
	 * @return list<array{subjectType: string, subjectId: string, locationId: int}>
	 */
	public function listAll(): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from('iv_loc_acl')
			->orderBy('subject_type', 'ASC')
			->addOrderBy('subject_id', 'ASC')
			->addOrderBy('location_id', 'ASC');
		$this->workspace->apply($qb);
		$res = $qb->executeQuery();
		$out = [];
		while (($row = $res->fetch()) !== false) {
			$out[] = [
				'subjectType' => (string)$row['subject_type'],
				'subjectId' => (string)$row['subject_id'],
				'locationId' => (int)$row['location_id'],
			];
		}
		$res->closeCursor();
		return $out;
	}

	/**
	 * @param list<array<string, mixed>> $rows
	 * @return list<array{subjectType: string, subjectId: string, locationId: int}>
	 */
	public function replaceAll(string $actorUid, array $rows): array
	{
		$this->access->requireWorkspaceAdmin($actorUid);
		$clean = [];
		foreach ($rows as $row) {
			$type = strtolower(trim((string)($row['subjectType'] ?? $row['subject_type'] ?? '')));
			$id = trim((string)($row['subjectId'] ?? $row['subject_id'] ?? ''));
			$locId = (int)($row['locationId'] ?? $row['location_id'] ?? 0);
			if (!in_array($type, [self::TYPE_USER, self::TYPE_GROUP, self::TYPE_DEVICE], true) || $id === '' || $locId <= 0) {
				throw new ValidationException('validation_failed', '', [
					['field' => 'acl', 'code' => 'validation_failed'],
				]);
			}
			$this->assertSubjectExists($type, $id);
			if ($type === self::TYPE_DEVICE) {
				$id = (string)(int)$id;
			}
			$this->locations->findById($locId);
			$clean[] = [$type, $id, $locId];
		}
		$this->db->beginTransaction();
		try {
			$del = $this->db->getQueryBuilder();
			$del->delete('iv_loc_acl');
			$this->workspace->apply($del);
			$del->executeStatement();
			foreach ($clean as [$type, $id, $locId]) {
				$ins = $this->db->getQueryBuilder();
				$ins->insert('iv_loc_acl')->values([
					'workspace_id' => $ins->createNamedParameter($this->workspace->id(), IQueryBuilder::PARAM_INT),
					'subject_type' => $ins->createNamedParameter($type),
					'subject_id' => $ins->createNamedParameter($id),
					'location_id' => $ins->createNamedParameter($locId, IQueryBuilder::PARAM_INT),
				])->executeStatement();
			}
			$this->db->commit();
		} catch (\Throwable $e) {
			if ($this->db->inTransaction()) {
				$this->db->rollBack();
			}
			throw $e;
		}
		return $this->listAll();
	}

	private function assertSubjectExists(string $type, string $id): void
	{
		if ($type === self::TYPE_USER && !$this->userManager->userExists($id)) {
			throw new ValidationException('unknown_user', 'Unknown user: ' . $id, [
				['field' => 'subjectId', 'code' => 'unknown_user'],
			]);
		}
		if ($type === self::TYPE_GROUP && !$this->groupManager->groupExists($id)) {
			throw new ValidationException('unknown_group', 'Unknown group: ' . $id, [
				['field' => 'subjectId', 'code' => 'unknown_group'],
			]);
		}
		if ($type === self::TYPE_DEVICE) {
			if (!ctype_digit($id) || (int)$id <= 0) {
				throw new ValidationException('validation_failed', '', [
					['field' => 'subjectId', 'code' => 'validation_failed'],
				]);
			}
			try {
				$device = $this->devices->findById((int)$id);
				if ($device->getWorkspaceId() !== $this->workspace->id()) {
					throw new NotFoundException();
				}
			} catch (NotFoundException) {
				throw new ValidationException('unknown_device', 'Unknown device: ' . $id, [
					['field' => 'subjectId', 'code' => 'unknown_device'],
				]);
			}
		}
	}
}
