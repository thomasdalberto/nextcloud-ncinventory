<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Db;

use OCA\InventoryCheck\Service\WorkspaceContext;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @extends WorkspaceScopedMapper<NotifyLog>
 */
class NotifyLogMapper extends WorkspaceScopedMapper
{
	public const TABLE = 'iv_notif_log';

	public function __construct(IDBConnection $db, WorkspaceContext $workspace)
	{
		parent::__construct($db, self::TABLE, NotifyLog::class, $workspace);
	}

	public function findRecentForItem(int $itemId, int $sinceUnix): ?NotifyLog
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('item_id', $qb->createNamedParameter($itemId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->gte('created_at', $qb->createNamedParameter($sinceUnix, IQueryBuilder::PARAM_INT)))
			->orderBy('created_at', 'DESC')
			->setMaxResults(1);
		$entities = $this->findEntities($qb);
		return $entities[0] ?? null;
	}

	public function tryInsert(string $dedupeKey, int $itemId, int $now): bool
	{
		try {
			$row = new NotifyLog();
			$row->setDedupeKey($dedupeKey);
			$row->setItemId($itemId);
			$row->setCreatedAt($now);
			$this->insert($row);
			return true;
		} catch (\Throwable $e) {
			if (UniqueViolation::is($e)) {
				return false;
			}
			throw $e;
		}
	}

	public function deleteByDedupeKey(string $dedupeKey): void
	{
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('dedupe_key', $qb->createNamedParameter($dedupeKey)));
		$this->workspace->apply($qb);
		$qb->executeStatement();
	}

	public function findByDedupeKey(string $dedupeKey): ?NotifyLog
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('dedupe_key', $qb->createNamedParameter($dedupeKey)))
			->setMaxResults(1);
		$entities = $this->findEntities($qb);
		return $entities[0] ?? null;
	}
}
