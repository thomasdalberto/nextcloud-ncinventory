<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Db;

use OCA\InventoryCheck\Service\WorkspaceContext;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @extends WorkspaceScopedMapper<LocationAcl>
 */
class LocationAclMapper extends WorkspaceScopedMapper
{
	public const TABLE = 'iv_loc_acl';

	public function __construct(IDBConnection $db, WorkspaceContext $workspace)
	{
		parent::__construct($db, self::TABLE, LocationAcl::class, $workspace);
	}

	/** @return list<LocationAcl> */
	public function listAll(): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->orderBy('subject_type', 'ASC')
			->addOrderBy('subject_id', 'ASC')
			->addOrderBy('location_id', 'ASC');
		return $this->findEntities($qb);
	}

	/** @return list<LocationAcl> */
	public function listForSubject(string $subjectType, string $subjectId): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('subject_type', $qb->createNamedParameter($subjectType)))
			->andWhere($qb->expr()->eq('subject_id', $qb->createNamedParameter($subjectId)))
			->orderBy('location_id', 'ASC');
		return $this->findEntities($qb);
	}

	/**
	 * Every location id visible to a user, either assigned directly or via
	 * one of their groups.
	 *
	 * @param list<string> $groupIds
	 * @return list<int>
	 */
	public function locationIdsForSubjects(string $userSubjectType, string $userId, array $groupIds): array
	{
		$qb = $this->db->getQueryBuilder();
		$conds = [
			$qb->expr()->andX(
				$qb->expr()->eq('subject_type', $qb->createNamedParameter($userSubjectType)),
				$qb->expr()->eq('subject_id', $qb->createNamedParameter($userId)),
			),
		];
		if ($groupIds !== []) {
			$conds[] = $qb->expr()->andX(
				$qb->expr()->eq('subject_type', $qb->createNamedParameter('group')),
				$qb->expr()->in(
					'subject_id',
					$qb->createNamedParameter($groupIds, IQueryBuilder::PARAM_STR_ARRAY),
				),
			);
		}
		$qb->selectDistinct('location_id')->from($this->getTableName())
			->where($qb->expr()->orX(...$conds));
		$this->workspace->apply($qb);
		$result = $qb->executeQuery();
		$out = [];
		while (($row = $result->fetch()) !== false) {
			$out[] = (int)$row['location_id'];
		}
		$result->closeCursor();
		sort($out);
		return array_values(array_unique($out));
	}

	/**
	 * Replace-all write for one subject (delete then re-insert) inside a
	 * transaction, so a partial write can never leave a subject half-updated.
	 *
	 * @param list<int> $locationIds
	 */
	public function replaceForSubject(string $subjectType, string $subjectId, array $locationIds): void
	{
		$this->db->beginTransaction();
		try {
			$del = $this->db->getQueryBuilder();
			$del->delete($this->getTableName())
				->where($del->expr()->eq('subject_type', $del->createNamedParameter($subjectType)))
				->andWhere($del->expr()->eq('subject_id', $del->createNamedParameter($subjectId)));
			$this->workspace->apply($del);
			$del->executeStatement();

			foreach (array_unique($locationIds) as $locationId) {
				$this->db->insertIgnoreConflict($this->getTableName(), [
					'workspace_id' => $this->workspace->id(),
					'subject_type' => $subjectType,
					'subject_id' => $subjectId,
					'location_id' => $locationId,
				]);
			}
			$this->db->commit();
		} catch (\Throwable $e) {
			if ($this->db->inTransaction()) {
				$this->db->rollBack();
			}
			throw $e;
		}
	}
}
