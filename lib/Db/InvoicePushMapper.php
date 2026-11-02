<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Db;

use OCA\InventoryCheck\Service\WorkspaceContext;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @extends WorkspaceScopedMapper<InvoicePush>
 */
class InvoicePushMapper extends WorkspaceScopedMapper
{
	public const TABLE = 'iv_inv_push';

	public function __construct(IDBConnection $db, WorkspaceContext $workspace)
	{
		parent::__construct($db, self::TABLE, InvoicePush::class, $workspace);
	}

	public function findByIssueRef(string $issueRef): ?InvoicePush
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('issue_ref', $qb->createNamedParameter($issueRef)))
			->setMaxResults(1);
		$entities = $this->findEntities($qb);
		return $entities[0] ?? null;
	}

	/**
	 * Insert a pending outbox row; returns the stored row (ours or the
	 * winner's when the issue_ref race is lost).
	 */
	public function insertPending(string $issueRef, int $projectId, string $actorUid, string $payloadJson, int $now): InvoicePush
	{
		$row = new InvoicePush();
		$row->setIssueRef($issueRef);
		$row->setProjectId($projectId);
		$row->setActorUid($actorUid);
		$row->setPayloadJson($payloadJson);
		$row->setStatus(InvoicePush::STATUS_PENDING);
		$row->setAttempts(0);
		$row->setCreatedAt($now);
		$row->setUpdatedAt($now);
		try {
			return $this->insert($row);
		} catch (\Throwable $e) {
			if (UniqueViolation::is($e)) {
				$existing = $this->findByIssueRef($issueRef);
				if ($existing !== null) {
					return $existing;
				}
			}
			throw $e;
		}
	}

	/**
	 * Bounded drain batch for cron/manual retry — pending or failed rows,
	 * oldest first. `done` rows are kept for audit but never returned.
	 *
	 * @return list<InvoicePush>
	 */
	public function findRetryable(int $limit = 20, int $maxAttempts = 8): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->in('status', $qb->createNamedParameter(
				[InvoicePush::STATUS_PENDING, InvoicePush::STATUS_FAILED],
				IQueryBuilder::PARAM_STR_ARRAY
			)))
			->andWhere($qb->expr()->lt('attempts', $qb->createNamedParameter($maxAttempts, IQueryBuilder::PARAM_INT)))
			->orderBy('id', 'ASC')
			->setMaxResults($limit);
		return $this->findEntities($qb);
	}

	public function markDone(InvoicePush $row, int $now): void
	{
		$row->setStatus(InvoicePush::STATUS_DONE);
		$row->setLastError(null);
		$row->setUpdatedAt($now);
		$this->update($row);
	}

	public function markFailed(InvoicePush $row, string $error, int $now): void
	{
		$row->setStatus(InvoicePush::STATUS_FAILED);
		$row->setLastError(mb_substr($error, 0, 255));
		$row->setUpdatedAt($now);
		$this->update($row);
	}

	public function bumpAttempt(InvoicePush $row, int $now): void
	{
		$row->setAttempts($row->getAttempts() + 1);
		$row->setUpdatedAt($now);
		$this->update($row);
	}

	/** Pending/failed count for the settings status surface. */
	public function countRetryable(int $maxAttempts = 8): int
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('id'))
			->from($this->getTableName())
			->where($qb->expr()->in('status', $qb->createNamedParameter(
				[InvoicePush::STATUS_PENDING, InvoicePush::STATUS_FAILED],
				IQueryBuilder::PARAM_STR_ARRAY
			)))
			->andWhere($qb->expr()->lt('attempts', $qb->createNamedParameter($maxAttempts, IQueryBuilder::PARAM_INT)));
		$this->workspace->apply($qb);
		return (int) $qb->executeQuery()->fetchOne();
	}
}
