<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCA\InventoryCheck\Service\WorkspaceContext;
use OCP\IDBConnection;

/**
 * @extends WorkspaceScopedMapper<Balance>
 */
class BalanceMapper extends WorkspaceScopedMapper
{
	public const TABLE = 'iv_balances';

	public function __construct(IDBConnection $db, WorkspaceContext $workspace)
	{
		parent::__construct($db, self::TABLE, Balance::class, $workspace);
	}

	public function findPair(int $itemId, int $locationId): ?Balance
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('item_id', $qb->createNamedParameter($itemId, \PDO::PARAM_INT)))
			->andWhere($qb->expr()->eq('location_id', $qb->createNamedParameter($locationId, \PDO::PARAM_INT)));
		try {
			return $this->findEntity($qb);
		} catch (DoesNotExistException) {
			return null;
		}
	}

	/**
	 * Ensure a zero-qty row exists (ignore duplicate races on unique key).
	 *
	 * Uses insertIgnoreConflict (INSERT IGNORE / ON CONFLICT DO NOTHING):
	 * a raw unique-violation inside the movement transaction would abort
	 * the whole transaction on PostgreSQL, so it must never be raised.
	 */
	public function ensureZeroRow(int $itemId, int $locationId, int $now): void
	{
		$this->workspace->requireWrite();
		if ($this->findPair($itemId, $locationId) !== null) {
			return;
		}
		$this->db->insertIgnoreConflict($this->getTableName(), [
			'workspace_id' => $this->workspace->id(),
			'item_id' => $itemId,
			'location_id' => $locationId,
			'qty' => 0,
			'updated_at' => $now,
		]);
	}

	/**
	 * Lock balance rows FOR UPDATE in ascending (item_id, location_id) order (S2).
	 *
	 * @param list<array{itemId: int, locationId: int}> $pairs
	 * @return array<string, Balance> keyed by "itemId:locationId"
	 */
	public function lockPairs(array $pairs): array
	{
		$unique = [];
		foreach ($pairs as $p) {
			$key = $p['itemId'] . ':' . $p['locationId'];
			$unique[$key] = $p;
		}
		$sorted = array_values($unique);
		usort($sorted, static function (array $a, array $b): int {
			if ($a['itemId'] !== $b['itemId']) {
				return $a['itemId'] <=> $b['itemId'];
			}
			return $a['locationId'] <=> $b['locationId'];
		});

		$out = [];
		foreach ($sorted as $p) {
			$qb = $this->db->getQueryBuilder();
			$qb->select('*')->from($this->getTableName())
				->where($qb->expr()->eq('item_id', $qb->createNamedParameter($p['itemId'], \PDO::PARAM_INT)))
				->andWhere($qb->expr()->eq('location_id', $qb->createNamedParameter($p['locationId'], \PDO::PARAM_INT)));

			$this->workspace->apply($qb);
			$sql = $qb->getSQL();
			// SQLite (rare in prod) ignores FOR UPDATE; MySQL/Pg honour it.
			if ($this->db->getDatabaseProvider() !== IDBConnection::PLATFORM_SQLITE) {
				$sql .= ' FOR UPDATE';
			}

			$result = $this->db->executeQuery($sql, $qb->getParameters(), $qb->getParameterTypes());
			$row = $result->fetch();
			$result->closeCursor();
			if ($row === false) {
				throw new \RuntimeException('balance_row_missing_after_ensure');
			}
			$out[$p['itemId'] . ':' . $p['locationId']] = $this->mapToBalance($row);
		}
		return $out;
	}

	/**
	 * @param array<string, mixed> $row
	 */
	private function mapToBalance(array $row): Balance
	{
		$b = new Balance();
		$b->setId((int)$row['id']);
		$b->setWorkspaceId((int)$row['workspace_id']);
		$b->setItemId((int)$row['item_id']);
		$b->setLocationId((int)$row['location_id']);
		$b->setQty((int)$row['qty']);
		$b->setUpdatedAt((int)$row['updated_at']);
		$b->resetUpdatedFields();
		return $b;
	}

	/**
	 * @param list<int>|null $locationIdFilter restrict to these locations (null = no filter, [] = empty result)
	 * @return array{data: list<Balance>, total: int}
	 */
	public function search(
		?int $itemId,
		?int $locationId,
		bool $nonZero,
		int $limit,
		int $offset,
		bool $negativeOnly = false,
		?array $locationIdFilter = null,
	): array {
		if ($locationIdFilter === []) {
			return ['data' => [], 'total' => 0];
		}
		$apply = function ($qb) use ($itemId, $locationId, $nonZero, $negativeOnly, $locationIdFilter): void {
			$qb->from($this->getTableName());
			$conds = [];
			if ($itemId !== null) {
				$conds[] = $qb->expr()->eq('item_id', $qb->createNamedParameter($itemId, \PDO::PARAM_INT));
			}
			if ($locationId !== null) {
				$conds[] = $qb->expr()->eq('location_id', $qb->createNamedParameter($locationId, \PDO::PARAM_INT));
			}
			if ($locationIdFilter !== null) {
				$conds[] = $qb->expr()->in(
					'location_id',
					$qb->createNamedParameter($locationIdFilter, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT_ARRAY),
				);
			}
			if ($negativeOnly) {
				$conds[] = $qb->expr()->lt('qty', $qb->createNamedParameter(0, \PDO::PARAM_INT));
			} elseif ($nonZero) {
				$conds[] = $qb->expr()->neq('qty', $qb->createNamedParameter(0, \PDO::PARAM_INT));
			}
			if ($conds !== []) {
				$qb->where(...$conds);
			}
		};

		$countQb = $this->db->getQueryBuilder();
		$countQb->select($countQb->func()->count('id', 'cnt'));
		$apply($countQb);
		$this->workspace->apply($countQb);
		$result = $countQb->executeQuery();
		$total = (int)($result->fetchOne() ?: 0);
		$result->closeCursor();

		$qb = $this->db->getQueryBuilder();
		$qb->select('*');
		$apply($qb);
		if ($negativeOnly) {
			$qb->orderBy('qty', 'ASC')->addOrderBy('item_id', 'ASC')->addOrderBy('location_id', 'ASC');
		} else {
			$qb->orderBy('item_id', 'ASC')->addOrderBy('location_id', 'ASC');
		}
		$qb->setMaxResults($limit)->setFirstResult($offset);

		return ['data' => $this->findEntities($qb), 'total' => $total];
	}

	/**
	 * @return array<int, int> item_id → SUM(qty)
	 * @param list<int>|null $locationIds null = all locations; empty = no rows
	 */
	public function sumQtyByItem(?array $locationIds = null): array
	{
		if ($locationIds !== null && $locationIds === []) {
			return [];
		}
		$qb = $this->db->getQueryBuilder();
		$qb->select('item_id')
			->selectAlias($qb->func()->sum('qty'), 'total')
			->from($this->getTableName())
			->groupBy('item_id');
		if ($locationIds !== null) {
			$qb->where($qb->expr()->in(
				'location_id',
				$qb->createNamedParameter($locationIds, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT_ARRAY),
			));
		}
		$this->workspace->apply($qb);
		$result = $qb->executeQuery();
		$out = [];
		while ($row = $result->fetch()) {
			$out[(int)$row['item_id']] = (int)$row['total'];
		}
		$result->closeCursor();
		return $out;
	}

	/**
	 * Wave B3 per-location reorder hints (S10 evaluated per location instead
	 * of the item-wide sum).
	 *
	 * @return array<int, array<int, int>> item_id → (location_id → qty)
	 */
	public function sumQtyByItemAndLocation(): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('item_id', 'location_id', 'qty')->from($this->getTableName());
		$this->workspace->apply($qb);
		$result = $qb->executeQuery();
		$out = [];
		while ($row = $result->fetch()) {
			$out[(int)$row['item_id']][(int)$row['location_id']] = (int)$row['qty'];
		}
		$result->closeCursor();
		return $out;
	}
}
