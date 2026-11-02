<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Db;

use OCP\IDBConnection;

/**
 * Provider-aware row locking for master-data rows.
 *
 * Lock protocol (deadlock-free by global ordering):
 *   1. item row      — shared for movements, exclusive for deactivate/delete
 *   2. location rows — shared for movements (ascending id), exclusive for deactivate/delete
 *   3. balance rows  — exclusive, ascending (item_id, location_id) (S2)
 *
 * Shared locks let concurrent movements on one item proceed in parallel while
 * still conflicting with an exclusive deactivation/deletion — closing the
 * TOCTOU window between the zero-balance check (S5) / movement-reference
 * check (S6) and the entity flip.
 */
trait RowLocking
{
	/**
	 * SQL suffix for a locking read. SQLite has no row locks (single-writer);
	 * MySQL/MariaDB use LOCK IN SHARE MODE, PostgreSQL FOR SHARE. Any other
	 * platform falls back to the universally supported FOR UPDATE.
	 */
	private function rowLockSuffix(bool $exclusive): string
	{
		$provider = $this->db->getDatabaseProvider();
		if ($provider === IDBConnection::PLATFORM_SQLITE) {
			return '';
		}
		if ($exclusive) {
			return ' FOR UPDATE';
		}
		// NC 30+ exposes MariaDB as its own provider ('mariadb'); shared-lock
		// syntax is identical to MySQL. Treating it as default FOR UPDATE would
		// over-serialize concurrent movements on typical docker/MariaDB installs.
		if ($provider === IDBConnection::PLATFORM_MYSQL || $provider === 'mariadb') {
			return ' LOCK IN SHARE MODE';
		}
		if ($provider === IDBConnection::PLATFORM_POSTGRES) {
			return ' FOR SHARE';
		}
		return ' FOR UPDATE';
	}

	/**
	 * Locking read of a single row by id. Must run inside a transaction.
	 *
	 * @return array<string, mixed>|null
	 */
	private function selectRowByIdLocked(int $id, bool $exclusive): ?array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, \PDO::PARAM_INT)));
		$this->workspace->apply($qb);
		$sql = $qb->getSQL() . $this->rowLockSuffix($exclusive);
		$result = $this->db->executeQuery($sql, $qb->getParameters(), $qb->getParameterTypes());
		$row = $result->fetch();
		$result->closeCursor();
		return $row === false ? null : $row;
	}
}
