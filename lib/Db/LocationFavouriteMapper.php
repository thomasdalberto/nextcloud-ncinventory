<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Db;

use OCA\InventoryCheck\Service\WorkspaceContext;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @extends WorkspaceScopedMapper<LocationFavourite>
 */
class LocationFavouriteMapper extends WorkspaceScopedMapper
{
	public const TABLE = 'iv_loc_fav';

	public function __construct(IDBConnection $db, WorkspaceContext $workspace)
	{
		parent::__construct($db, self::TABLE, LocationFavourite::class, $workspace);
	}

	/** @return list<int> */
	public function locationIdsForUser(string $userId): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('location_id')->from($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->orderBy('created_at', 'ASC')
			->addOrderBy('id', 'ASC');
		$this->workspace->apply($qb);
		$res = $qb->executeQuery();
		$out = [];
		while (($row = $res->fetch()) !== false) {
			$out[] = (int)$row['location_id'];
		}
		$res->closeCursor();
		return $out;
	}

	public function countForUser(string $userId): int
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('id', 'cnt'))->from($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
		$this->workspace->apply($qb);
		$res = $qb->executeQuery();
		$total = (int)($res->fetchOne() ?: 0);
		$res->closeCursor();
		return $total;
	}

	public function findPair(string $userId, int $locationId): ?LocationFavourite
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->eq('location_id', $qb->createNamedParameter($locationId, IQueryBuilder::PARAM_INT)));
		$entities = $this->findEntities($qb);
		return $entities[0] ?? null;
	}

	public function deletePair(string $userId, int $locationId): void
	{
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->eq('location_id', $qb->createNamedParameter($locationId, IQueryBuilder::PARAM_INT)));
		$this->workspace->apply($qb);
		$qb->executeStatement();
	}

	/**
	 * Location-delete: drop every favourite row pointing at a deleted
	 * location id — InnoDB id reuse would otherwise reattach them.
	 */
	public function deleteAllForLocation(int $locationId): void
	{
		if ($locationId <= 0) {
			return;
		}
		if (!$this->db->tableExists(self::TABLE)) {
			return;
		}
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('location_id', $qb->createNamedParameter($locationId, IQueryBuilder::PARAM_INT)));
		$this->workspace->apply($qb);
		$qb->executeStatement();
	}

	/** GDPR / user-delete: drop every favourite row for a deleted UID. */
	public function deleteAllForUser(string $userId): void
	{
		if ($userId === '') {
			return;
		}
		// Sibling-app PHPUnit / LiveUninstallDrop on the shared farm DB may have
		// dropped this table mid-suite. Never fail cross-app user-delete listeners.
		if (!$this->db->tableExists(self::TABLE)) {
			return;
		}
		try {
			$qb = $this->db->getQueryBuilder();
			$qb->delete($this->getTableName())
				->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
			$qb->executeStatement();
		} catch (\Throwable $e) {
			$msg = $e->getMessage();
			if (stripos($msg, 'iv_loc_fav') !== false
				|| stripos($msg, 'Base table or view not found') !== false
				|| stripos($msg, 'no such table') !== false) {
				return;
			}
			throw $e;
		}
	}
}
