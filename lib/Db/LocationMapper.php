<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Db;

use OCA\InventoryCheck\Exception\NotFoundException;
use OCP\AppFramework\Db\DoesNotExistException;
use OCA\InventoryCheck\Service\WorkspaceContext;
use OCP\IDBConnection;

/**
 * @extends WorkspaceScopedMapper<Location>
 */
class LocationMapper extends WorkspaceScopedMapper
{
	use RowLocking;

	public const TABLE = 'iv_locations';

	public function __construct(IDBConnection $db, WorkspaceContext $workspace)
	{
		parent::__construct($db, self::TABLE, Location::class, $workspace);
	}

	/**
	 * Locking read (see RowLocking protocol). Must run inside a transaction.
	 */
	public function lockById(int $id, bool $exclusive): Location
	{
		$row = $this->selectRowByIdLocked($id, $exclusive);
		if ($row === null) {
			throw new NotFoundException('unknown_location');
		}
		/** @var Location */
		return $this->mapRowToEntity($row);
	}

	public function findById(int $id): Location
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, \PDO::PARAM_INT)));
		try {
			return $this->findEntity($qb);
		} catch (DoesNotExistException) {
			throw new NotFoundException('unknown_location');
		}
	}

	public function findByCode(string $code): ?Location
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('code', $qb->createNamedParameter($code)));
		try {
			return $this->findEntity($qb);
		} catch (DoesNotExistException) {
			return null;
		}
	}

	/**
	 * All location codes for item↔location cross-uniqueness (EXEC A7).
	 *
	 * @return list<string>
	 */
	public function allCodes(): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('code')->from($this->getTableName());
		$this->workspace->apply($qb);
		$result = $qb->executeQuery();
		$codes = [];
		while ($row = $result->fetch()) {
			$codes[] = (string)$row['code'];
		}
		$result->closeCursor();
		return $codes;
	}

	/**
	 * @param list<int>|null $idFilter restrict to these ids (null = no filter, [] = empty result)
	 * @return array{data: list<Location>, total: int}
	 */
	public function search(?bool $active, int $limit, int $offset, ?array $idFilter = null, string $q = ''): array
	{
		if ($idFilter === []) {
			return ['data' => [], 'total' => 0];
		}
		$q = trim($q);
		$apply = function ($qb) use ($active, $idFilter, $q): void {
			$qb->from($this->getTableName());
			$conds = [];
			if ($active !== null) {
				$conds[] = $qb->expr()->eq('active', $qb->createNamedParameter($active, \PDO::PARAM_BOOL));
			}
			if ($idFilter !== null) {
				$conds[] = $qb->expr()->in(
					'id',
					$qb->createNamedParameter($idFilter, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT_ARRAY),
				);
			}
			if ($q !== '') {
				$lower = '%' . $this->db->escapeLikeParameter(mb_strtolower($q)) . '%';
				$prefix = $this->db->escapeLikeParameter($q) . '%';
				$conds[] = $qb->expr()->orX(
					$qb->expr()->like($qb->func()->lower('name'), $qb->createNamedParameter($lower)),
					$qb->expr()->like('code', $qb->createNamedParameter($prefix)),
				);
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
		$qb->orderBy('name', 'ASC')->addOrderBy('id', 'ASC')
			->setMaxResults($limit)->setFirstResult($offset);

		return ['data' => $this->findEntities($qb), 'total' => $total];
	}

	public function countMovementsReferencing(int $locationId): int
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('id', 'cnt'))
			->from('iv_movements')
			->where($qb->expr()->orX(
				$qb->expr()->eq('location_id', $qb->createNamedParameter($locationId, \PDO::PARAM_INT)),
				$qb->expr()->eq('counterparty_loc_id', $qb->createNamedParameter($locationId, \PDO::PARAM_INT)),
			));
		$this->workspace->apply($qb);
		$result = $qb->executeQuery();
		$total = (int)($result->fetchOne() ?: 0);
		$result->closeCursor();
		return $total;
	}

	public function hasNonZeroBalance(int $locationId): bool
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('id')->from('iv_balances')
			->where($qb->expr()->eq('location_id', $qb->createNamedParameter($locationId, \PDO::PARAM_INT)))
			->andWhere($qb->expr()->neq('qty', $qb->createNamedParameter(0, \PDO::PARAM_INT)))
			->setMaxResults(1);
		$this->workspace->apply($qb);
		$result = $qb->executeQuery();
		$row = $result->fetch();
		$result->closeCursor();
		return $row !== false;
	}
}
