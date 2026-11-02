<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Db;

use OCA\InventoryCheck\Service\WorkspaceContext;
use OCP\IDBConnection;

/**
 * @extends WorkspaceScopedMapper<Movement>
 */
class MovementMapper extends WorkspaceScopedMapper
{
	public const TABLE = 'iv_movements';

	public function __construct(IDBConnection $db, WorkspaceContext $workspace)
	{
		parent::__construct($db, self::TABLE, Movement::class, $workspace);
	}

	/**
	 * @param list<int>|null $locationIdFilter null = unrestricted; empty = none visible
	 * @return array{data: list<Movement>, total: int}
	 */
	public function search(
		?string $kind,
		?int $itemId,
		?int $locationId,
		?int $from,
		?int $to,
		?string $transferGroup,
		int $limit,
		int $offset,
		?array $locationIdFilter = null,
		?string $reasonCode = null,
	): array {
		$apply = function ($qb) use ($kind, $itemId, $locationId, $from, $to, $transferGroup, $locationIdFilter, $reasonCode): void {
			$qb->from($this->getTableName());
			$conds = [];
			if ($kind !== null && $kind !== '') {
				$conds[] = $qb->expr()->eq('kind', $qb->createNamedParameter($kind));
			}
			if ($itemId !== null) {
				$conds[] = $qb->expr()->eq('item_id', $qb->createNamedParameter($itemId, \PDO::PARAM_INT));
			}
			if ($locationIdFilter !== null) {
				if ($locationIdFilter === []) {
					$conds[] = $qb->expr()->eq('location_id', $qb->createNamedParameter(-1, \PDO::PARAM_INT));
				} elseif ($locationId !== null) {
					$conds[] = $qb->expr()->eq('location_id', $qb->createNamedParameter($locationId, \PDO::PARAM_INT));
				} else {
					$conds[] = $qb->expr()->in(
						'location_id',
						$qb->createNamedParameter($locationIdFilter, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT_ARRAY),
					);
				}
			} elseif ($locationId !== null) {
				$conds[] = $qb->expr()->eq('location_id', $qb->createNamedParameter($locationId, \PDO::PARAM_INT));
			}
			if ($from !== null) {
				$conds[] = $qb->expr()->gte('created_at', $qb->createNamedParameter($from, \PDO::PARAM_INT));
			}
			if ($to !== null) {
				$conds[] = $qb->expr()->lte('created_at', $qb->createNamedParameter($to, \PDO::PARAM_INT));
			}
			if ($transferGroup !== null && $transferGroup !== '') {
				$conds[] = $qb->expr()->eq('transfer_group', $qb->createNamedParameter($transferGroup));
			}
			if ($reasonCode !== null && $reasonCode !== '') {
				$conds[] = $qb->expr()->eq('reason_code', $qb->createNamedParameter($reasonCode));
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
		$qb->orderBy('created_at', 'DESC')->addOrderBy('id', 'DESC')
			->setMaxResults($limit)->setFirstResult($offset);

		return ['data' => $this->findEntities($qb), 'total' => $total];
	}

	/**
	 * Find issue movements for a flange ref (idempotency key base: ref_type + ref_id).
	 *
	 * @return list<Movement>
	 */
	public function findByRef(string $refType, int $refId): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('ref_type', $qb->createNamedParameter($refType)))
			->andWhere($qb->expr()->eq('ref_id', $qb->createNamedParameter($refId, \PDO::PARAM_INT)))
			->andWhere($qb->expr()->eq('kind', $qb->createNamedParameter('issue')))
			->orderBy('id', 'ASC');

		return $this->findEntities($qb);
	}

	/**
	 * Idempotency lookup for (ref_type, ref_id, item_id).
	 */
	public function findByRefAndItemId(string $refType, int $refId, int $itemId): ?Movement
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('ref_type', $qb->createNamedParameter($refType)))
			->andWhere($qb->expr()->eq('ref_id', $qb->createNamedParameter($refId, \PDO::PARAM_INT)))
			->andWhere($qb->expr()->eq('item_id', $qb->createNamedParameter($itemId, \PDO::PARAM_INT)))
			->andWhere($qb->expr()->eq('kind', $qb->createNamedParameter('issue')))
			->orderBy('id', 'ASC')
			->setMaxResults(1);
		$entities = $this->findEntities($qb);
		return $entities[0] ?? null;
	}

	/**
	 * Wave C2: net signed quantity across every location for a (item, lot)
	 * pair — the serial-uniqueness invariant is "this must never exceed 1".
	 * Transfers cancel out (equal and opposite legs), so this only moves on
	 * receive / issue / adjust, which is exactly the C2 threat model.
	 */
	public function sumQtyDeltaByItemAndLot(int $itemId, string $lotCode): int
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->sum('qty_delta'))
			->from($this->getTableName())
			->where($qb->expr()->eq('item_id', $qb->createNamedParameter($itemId, \PDO::PARAM_INT)))
			->andWhere($qb->expr()->eq('lot_code', $qb->createNamedParameter($lotCode)));
		$this->workspace->apply($qb);
		$result = $qb->executeQuery();
		$sum = $result->fetchOne();
		$result->closeCursor();
		return $sum === false || $sum === null ? 0 : (int)$sum;
	}

	/**
	 * @return array<string, int> "itemId:locationId" → SUM(qty_delta)
	 */
	public function sumDeltasByPair(): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('item_id', 'location_id')
			->selectAlias($qb->func()->sum('qty_delta'), 'total')
			->from($this->getTableName())
			->groupBy('item_id')
			->addGroupBy('location_id');
		$this->workspace->apply($qb);
		$result = $qb->executeQuery();
		$out = [];
		while ($row = $result->fetch()) {
			$key = (int)$row['item_id'] . ':' . (int)$row['location_id'];
			$out[$key] = (int)$row['total'];
		}
		$result->closeCursor();
		return $out;
	}
}
