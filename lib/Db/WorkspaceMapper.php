<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Db;

use OCA\InventoryCheck\Exception\NotFoundException;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/** Persistence for workspace ownership and invitations. No implicit administrator grants. */
class WorkspaceMapper
{
	public function __construct(private readonly IDBConnection $db) {
	}

	public function find(int $id): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from('iv_workspaces')
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
		$result = $qb->executeQuery();
		$row = $result->fetch();
		$result->closeCursor();
		if ($row === false) {
			throw new NotFoundException('unknown_workspace');
		}
		return $row;
	}

	/** Includes pending invitations so their recipient can accept or decline them. */
	public function forUser(string $uid): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('w.*', 'm.role', 'm.status', 'm.id AS invitation_id')->from('iv_workspaces', 'w')
			->leftJoin('w', 'iv_ws_members', 'm', $qb->expr()->andX(
				$qb->expr()->eq('m.workspace_id', 'w.id'),
				$qb->expr()->eq('m.user_id', $qb->createNamedParameter($uid)),
			))
			->where($qb->expr()->orX(
				$qb->expr()->eq('w.owner_uid', $qb->createNamedParameter($uid)),
				$qb->expr()->eq('m.user_id', $qb->createNamedParameter($uid)),
			))->orderBy('w.id', 'ASC');
		$result = $qb->executeQuery();
		$rows = $result->fetchAll();
		$result->closeCursor();
		return $rows;
	}

	public function members(int $id): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('id', 'user_id', 'role', 'status')->from('iv_ws_members')
			->where($qb->expr()->eq('workspace_id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
			->orderBy('user_id', 'ASC');
		$result = $qb->executeQuery();
		$rows = $result->fetchAll();
		$result->closeCursor();
		return $rows;
	}

	public function create(string $uid, string $name, bool $demo = false): int
	{
		$qb = $this->db->getQueryBuilder();
		$qb->insert('iv_workspaces')->values([
			'owner_uid' => $qb->createNamedParameter($uid),
			'name' => $qb->createNamedParameter($name),
			'created_at' => $qb->createNamedParameter(time(), IQueryBuilder::PARAM_INT),
			'demo_uid' => $qb->createNamedParameter($demo ? $uid : null),
		])->executeStatement();
		return (int)$qb->getLastInsertId();
	}

	public function rename(int $id, string $name): void
	{
		$qb = $this->db->getQueryBuilder();
		$qb->update('iv_workspaces')->set('name', $qb->createNamedParameter($name))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))->executeStatement();
	}

	public function invite(int $id, string $uid, string $role): bool
	{
		return $this->db->insertIgnoreConflict('iv_ws_members', [
			'workspace_id' => $id, 'user_id' => $uid, 'role' => $role, 'status' => 'pending',
		]) > 0;
	}

	public function changeRole(int $id, string $uid, string $role): void
	{
		$qb = $this->db->getQueryBuilder();
		$qb->update('iv_ws_members')->set('role', $qb->createNamedParameter($role))
			->where($qb->expr()->eq('workspace_id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($uid)))
			->andWhere($qb->expr()->eq('status', $qb->createNamedParameter('accepted')))->executeStatement();
	}

	/** A recipient and invitation generation must match, including after revoke/re-invite. */
	public function pendingInvitation(string $uid, int $workspaceId, ?int $invitationId = null): ?array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('m.*', 'w.name', 'w.owner_uid')->from('iv_ws_members', 'm')
			->innerJoin('m', 'iv_workspaces', 'w', $qb->expr()->eq('w.id', 'm.workspace_id'))
			->where($qb->expr()->eq('m.workspace_id', $qb->createNamedParameter($workspaceId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('m.user_id', $qb->createNamedParameter($uid)))
			->andWhere($qb->expr()->eq('m.status', $qb->createNamedParameter('pending')));
		if ($invitationId !== null) {
			$qb->andWhere($qb->expr()->eq('m.id', $qb->createNamedParameter($invitationId, IQueryBuilder::PARAM_INT)));
		}
		$result = $qb->executeQuery();
		$row = $result->fetch();
		$result->closeCursor();
		return $row === false ? null : $row;
	}

	public function respond(int $id, string $uid, bool $accept, int $invitationId): bool
	{
		$qb = $this->db->getQueryBuilder();
		if ($accept) {
			$qb->update('iv_ws_members')->set('status', $qb->createNamedParameter('accepted'));
		} else {
			$qb->delete('iv_ws_members');
		}
		return $qb->where($qb->expr()->eq('workspace_id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('id', $qb->createNamedParameter($invitationId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($uid)))
			->andWhere($qb->expr()->eq('status', $qb->createNamedParameter('pending')))->executeStatement() > 0;
	}

	public function removeMember(int $id, string $uid): bool
	{
		$qb = $this->db->getQueryBuilder();
		return $qb->delete('iv_ws_members')
			->where($qb->expr()->eq('workspace_id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($uid)))->executeStatement() > 0;
	}

	/** Used by trusted CLI/cron callers, never exposed through the workspace API. */
	public function allIds(): array
	{
		$qb = $this->db->getQueryBuilder();
		$result = $qb->select('id')->from('iv_workspaces')->orderBy('id', 'ASC')->executeQuery();
		$ids = array_map(static fn (array $row) => (int)$row['id'], $result->fetchAll());
		$result->closeCursor();
		return $ids;
	}
}
