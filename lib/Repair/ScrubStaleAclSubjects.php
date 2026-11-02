<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Scrub iv_loc_acl grants whose subject no longer exists. Before the
 * GroupDeletedListener existed, deleting a user/group left stale grant rows
 * that silently re-activated if the same uid/gid was recreated. User rows are
 * covered defensively too (older installs predate the user-delete purge);
 * device rows are scrubbed against iv_scan_devices.
 *
 * Idempotent: only deletes rows whose subject fails the existence check.
 */
namespace OCA\InventoryCheck\Repair;

use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IUserManager;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;

final class ScrubStaleAclSubjects implements IRepairStep
{
	/** Tables holding a location_id foreign reference (no DB-level FK). */
	private const LOCATION_REF_TABLES = ['iv_loc_acl', 'iv_loc_fav'];

	public function __construct(
		private readonly IDBConnection $connection,
		private readonly IUserManager $userManager,
		private readonly IGroupManager $groupManager,
		private readonly IConfig $config,
	) {
	}

	public function getName(): string
	{
		return 'Remove location ACL grants and config entries for deleted users, groups, devices, and locations';
	}

	public function run(IOutput $output): void
	{
		// Independent of iv_loc_acl: config lists and location refs get their
		// own table guards, and must still run when the ACL table is absent.
		$this->scrubDanglingLocationRefs($output);

		if (!$this->connection->tableExists('iv_loc_acl')) {
			return;
		}

		$deviceIds = [];
		if ($this->connection->tableExists('iv_scan_devices')) {
			$res = $this->connection->getQueryBuilder()
				->select('id')->from('iv_scan_devices')->executeQuery();
			while (($row = $res->fetch()) !== false) {
				$deviceIds[(int)$row['id']] = true;
			}
			$res->closeCursor();
		}

		$qb = $this->connection->getQueryBuilder();
		$qb->select('subject_type', 'subject_id')
			->from('iv_loc_acl')
			->groupBy('subject_type')
			->addGroupBy('subject_id');
		$res = $qb->executeQuery();

		$stale = [];
		while (($row = $res->fetch()) !== false) {
			$type = (string)$row['subject_type'];
			$id = (string)$row['subject_id'];
			$dead = match ($type) {
				'user' => !$this->userManager->userExists($id),
				'group' => !$this->groupManager->groupExists($id),
				'device' => !isset($deviceIds[(int)$id]),
				default => true,
			};
			if ($dead) {
				$stale[] = [$type, $id];
			}
		}
		$res->closeCursor();

		$removed = 0;
		foreach ($stale as [$type, $id]) {
			$del = $this->connection->getQueryBuilder();
			$del->delete('iv_loc_acl')
				->where($del->expr()->eq('subject_type', $del->createNamedParameter($type)))
				->andWhere($del->expr()->eq('subject_id', $del->createNamedParameter($id)));
			$removed += $del->executeStatement();
		}

		if ($removed > 0) {
			$output->info(sprintf('inventorycheck: removed %d stale location ACL grant(s).', $removed));
		}
	}

	private function scrubDanglingLocationRefs(IOutput $output): void
	{
		if (!$this->connection->tableExists('iv_locations')) {
			return;
		}

		$live = [];
		$res = $this->connection->getQueryBuilder()
			->select('id')->from('iv_locations')->executeQuery();
		while (($row = $res->fetch()) !== false) {
			$live[(int)$row['id']] = true;
		}
		$res->closeCursor();

		$removed = 0;
		foreach (self::LOCATION_REF_TABLES as $table) {
			if (!$this->connection->tableExists($table)) {
				continue;
			}
			$qb = $this->connection->getQueryBuilder();
			$qb->select('location_id')->from($table)->groupBy('location_id');
			$res = $qb->executeQuery();
			$dangling = [];
			while (($row = $res->fetch()) !== false) {
				$locId = (int)$row['location_id'];
				if (!isset($live[$locId])) {
					$dangling[] = $locId;
				}
			}
			$res->closeCursor();

			foreach ($dangling as $locId) {
				$del = $this->connection->getQueryBuilder();
				$del->delete($table)
					->where($del->expr()->eq('location_id', $del->createNamedParameter($locId)));
				$removed += $del->executeStatement();
			}
		}

		if ($removed > 0) {
			$output->info(sprintf('inventorycheck: removed %d row(s) referencing deleted location(s).', $removed));
		}
	}
}
