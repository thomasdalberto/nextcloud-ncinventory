<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Db;

use OCA\InventoryCheck\Exception\NotFoundException;
use OCP\AppFramework\Db\DoesNotExistException;
use OCA\InventoryCheck\Service\WorkspaceContext;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @extends WorkspaceScopedMapper<CycleCampaign>
 */
class CycleCampaignMapper extends WorkspaceScopedMapper
{
	use RowLocking;

	public const TABLE = 'iv_cc_camp';

	public function __construct(IDBConnection $db, WorkspaceContext $workspace)
	{
		parent::__construct($db, self::TABLE, CycleCampaign::class, $workspace);
	}

	public function findById(int $id): CycleCampaign
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
		try {
			return $this->findEntity($qb);
		} catch (DoesNotExistException) {
			throw new NotFoundException('unknown_campaign');
		}
	}

	public function lockById(int $id, bool $exclusive): CycleCampaign
	{
		$row = $this->selectRowByIdLocked($id, $exclusive);
		if ($row === null) {
			throw new NotFoundException('unknown_campaign');
		}
		/** @var CycleCampaign */
		return $this->mapRowToEntity($row);
	}

	/**
	 * @param list<int>|null $locationIds null = all locations; empty = no rows
	 * @return array{data: list<CycleCampaign>, total: int}
	 */
	public function search(?string $status, int $limit, int $offset, ?array $locationIds = null): array
	{
		if ($locationIds !== null && $locationIds === []) {
			return ['data' => [], 'total' => 0];
		}

		$applyFilter = static function ($qb) use ($status, $locationIds): void {
			$conds = [];
			if ($status !== null && $status !== '') {
				$conds[] = $qb->expr()->eq('status', $qb->createNamedParameter($status));
			}
			if ($locationIds !== null) {
				$conds[] = $qb->expr()->in(
					'location_id',
					$qb->createNamedParameter($locationIds, IQueryBuilder::PARAM_INT_ARRAY),
				);
			}
			if ($conds !== []) {
				$qb->where(...$conds);
			}
		};

		$countQb = $this->db->getQueryBuilder();
		$countQb->select($countQb->func()->count('id', 'cnt'))->from($this->getTableName());
		$applyFilter($countQb);
		$this->workspace->apply($countQb);
		$res = $countQb->executeQuery();
		$total = (int)($res->fetchOne() ?: 0);
		$res->closeCursor();

		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName());
		$applyFilter($qb);
		$qb->orderBy('created_at', 'DESC')->addOrderBy('id', 'DESC')
			->setMaxResults($limit)->setFirstResult($offset);
		return ['data' => $this->findEntities($qb), 'total' => $total];
	}

	/**
	 * Open or counting inventur campaigns for a location (B1: block delete/deactivate).
	 */
	public function countOpenForLocation(int $locationId): int
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('id', 'cnt'))
			->from($this->getTableName())
			->where($qb->expr()->eq('location_id', $qb->createNamedParameter($locationId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->neq('status', $qb->createNamedParameter('closed')));
		$this->workspace->apply($qb);
		$res = $qb->executeQuery();
		$cnt = (int)($res->fetchOne() ?: 0);
		$res->closeCursor();
		return $cnt;
	}
}
