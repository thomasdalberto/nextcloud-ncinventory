<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Db;

use OCA\InventoryCheck\Exception\NotFoundException;
use OCP\AppFramework\Db\DoesNotExistException;
use OCA\InventoryCheck\Service\WorkspaceContext;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @extends WorkspaceScopedMapper<CycleLine>
 */
class CycleLineMapper extends WorkspaceScopedMapper
{
	use RowLocking;

	public const TABLE = 'iv_cc_line';

	public function __construct(IDBConnection $db, WorkspaceContext $workspace)
	{
		parent::__construct($db, self::TABLE, CycleLine::class, $workspace);
	}

	public function findById(int $id): CycleLine
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
		try {
			return $this->findEntity($qb);
		} catch (DoesNotExistException) {
			throw new NotFoundException('unknown_count_line');
		}
	}

	public function lockById(int $id, bool $exclusive): CycleLine
	{
		$row = $this->selectRowByIdLocked($id, $exclusive);
		if ($row === null) {
			throw new NotFoundException('unknown_count_line');
		}
		/** @var CycleLine */
		return $this->mapRowToEntity($row);
	}

	/** @return list<CycleLine> */
	public function forCampaign(int $campaignId): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('campaign_id', $qb->createNamedParameter($campaignId, IQueryBuilder::PARAM_INT)))
			->orderBy('item_id', 'ASC');
		return $this->findEntities($qb);
	}

	/** @return list<CycleLine> */
	public function forCampaignPage(int $campaignId, int $limit, int $offset): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('campaign_id', $qb->createNamedParameter($campaignId, IQueryBuilder::PARAM_INT)))
			->orderBy('item_id', 'ASC')
			->setMaxResults($limit)
			->setFirstResult($offset);
		return $this->findEntities($qb);
	}

	public function countForCampaign(int $campaignId): int
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('id', 'cnt'))->from($this->getTableName())
			->where($qb->expr()->eq('campaign_id', $qb->createNamedParameter($campaignId, IQueryBuilder::PARAM_INT)));
		$this->workspace->apply($qb);
		$res = $qb->executeQuery();
		$cnt = (int)($res->fetchOne() ?: 0);
		$res->closeCursor();
		return $cnt;
	}

	/** Lines that already have a counted quantity (for close progress UI). */
	public function countCountedForCampaign(int $campaignId): int
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('id', 'cnt'))->from($this->getTableName())
			->where($qb->expr()->eq('campaign_id', $qb->createNamedParameter($campaignId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->isNotNull('qty_counted'));
		$this->workspace->apply($qb);
		$res = $qb->executeQuery();
		$cnt = (int)($res->fetchOne() ?: 0);
		$res->closeCursor();
		return $cnt;
	}

	/**
	 * True when any line's frozen system_qty differs from the live balance
	 * at $locationId (UC-C2). Uses a single join — not an N+1 hydrate.
	 * Missing balance row ⇒ current qty 0 (COALESCE).
	 */
	public function campaignHasConflicts(int $campaignId, int $locationId): bool
	{
		return $this->countConflictsForCampaign($campaignId, $locationId) > 0;
	}

	public function countConflictsForCampaign(int $campaignId, int $locationId): int
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('l.id', 'cnt'))
			->from($this->getTableName(), 'l')
			->leftJoin(
				'l',
				BalanceMapper::TABLE,
				'b',
				$qb->expr()->andX(
					$qb->expr()->eq('b.item_id', 'l.item_id'),
					$qb->expr()->eq('b.location_id', $qb->createNamedParameter($locationId, IQueryBuilder::PARAM_INT)),
				),
			)
			->where($qb->expr()->eq('l.campaign_id', $qb->createNamedParameter($campaignId, IQueryBuilder::PARAM_INT)))
			->andWhere(
				$qb->expr()->neq(
					'l.system_qty',
					$qb->createFunction('COALESCE(b.qty, 0)'),
				),
			);
		$this->workspace->apply($qb, 'l');
		$res = $qb->executeQuery();
		$cnt = (int)($res->fetchOne() ?: 0);
		$res->closeCursor();
		return $cnt;
	}

	/**
	 * Open or counting inventur lines for an item (B1: block delete/deactivate).
	 */
	public function countOpenCampaignsForItem(int $itemId): int
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('l.id', 'cnt'))
			->from($this->getTableName(), 'l')
			->innerJoin('l', CycleCampaignMapper::TABLE, 'c', $qb->expr()->eq('l.campaign_id', 'c.id'))
			->where($qb->expr()->eq('l.item_id', $qb->createNamedParameter($itemId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->neq('c.status', $qb->createNamedParameter('closed')));
		$this->workspace->apply($qb, 'l');
		$res = $qb->executeQuery();
		$cnt = (int)($res->fetchOne() ?: 0);
		$res->closeCursor();
		return $cnt;
	}
}
