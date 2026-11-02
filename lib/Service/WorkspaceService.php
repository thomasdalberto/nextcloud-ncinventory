<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Service;

use OCA\InventoryCheck\AppInfo\Application;
use OCA\InventoryCheck\Activity\WorkspaceSubjects;
use OCA\InventoryCheck\Db\WorkspaceMapper;
use OCA\InventoryCheck\Exception\ConflictException;
use OCA\InventoryCheck\Exception\NotFoundException;
use OCA\InventoryCheck\Exception\PermissionDeniedException;
use OCA\InventoryCheck\Exception\ValidationException;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\Files\AppData\IAppDataFactory;
use OCP\Files\NotFoundException as FilesNotFoundException;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IUserManager;
use OCP\Lock\ILockingProvider;

/** The single authorization policy for workspaces, shared by web, services and mobile. */
class WorkspaceService
{
	public const ROLES = ['admin', 'editor', 'viewer'];
	public const DATA_TABLES = [
		'iv_balances', 'iv_cc_line', 'iv_cc_camp', 'iv_inv_push', 'iv_loc_acl',
		'iv_loc_fav', 'iv_movements', 'iv_notif_log', 'iv_scan_idem', 'iv_scan_devices',
		'iv_items', 'iv_locations', 'iv_ws_settings',
	];

	public function __construct(
		private readonly WorkspaceMapper $mapper,
		private readonly IDBConnection $db,
		private readonly IConfig $config,
		private readonly IUserManager $users,
		private readonly ILockingProvider $locks,
		private readonly DemoInventory $demo,
		private readonly IAppDataFactory $appDataFactory,
		private readonly WorkspaceEventService $events,
	) {
	}

	public function list(string $uid): array
	{
		$this->initialize($uid);
		return array_map(fn (array $row) => $this->toApi($row, $uid), $this->mapper->forUser($uid));
	}

	public function requireMember(string $uid, int $id): array
	{
		if ($uid === '') {
			throw new PermissionDeniedException();
		}
		$workspace = $this->mapper->find($id);
		if ($workspace['owner_uid'] === $uid) {
			$workspace['role'] = 'owner';
			$workspace['status'] = 'accepted';
			return $this->toApi($workspace, $uid);
		}
		foreach ($this->mapper->members($id) as $member) {
			if ($member['user_id'] === $uid && $member['status'] === 'accepted') {
				return $this->toApi(array_merge($workspace, ['role' => $member['role'], 'status' => $member['status']]), $uid);
			}
		}
		// Do not reveal the names or existence of other people's workspaces.
		throw new NotFoundException('unknown_workspace');
	}

	public function requireOwner(string $uid, int $id): array
	{
		$workspace = $this->requireMember($uid, $id);
		if ($workspace['role'] !== 'owner') {
			throw new PermissionDeniedException();
		}
		return $workspace;
	}

	public function requireAdmin(string $uid, int $id): array
	{
		$workspace = $this->requireMember($uid, $id);
		if (!in_array($workspace['role'], ['owner', 'admin'], true)) {
			throw new PermissionDeniedException();
		}
		return $workspace;
	}

	public function create(string $uid, string $name): array
	{
		$this->assertUser($uid);
		$id = $this->mapper->create($uid, $this->validName($name));
		$workspace = $this->requireMember($uid, $id);
		$this->events->publish(WorkspaceSubjects::CREATED, $workspace, $uid);
		return $workspace;
	}

	public function rename(string $uid, int $id, string $name): array
	{
		$workspace = $this->requireOwner($uid, $id);
		$name = $this->validName($name);
		if ($name !== $workspace['name']) {
			$this->mapper->rename($id, $name);
			$oldName = $workspace['name'];
			$workspace['name'] = $name;
			$this->events->publish(WorkspaceSubjects::RENAMED, $workspace, $uid, $this->mapper->members($id), '', ['oldName' => $oldName]);
		}
		return $workspace;
	}

	public function members(string $uid, int $id): array
	{
		$workspace = $this->requireAdmin($uid, $id);
		$members = [['user_id' => $workspace['ownerUid'], 'role' => 'owner', 'status' => 'accepted']];
		return array_map(function (array $row): array {
			$user = $this->users->get($row['user_id']);
			return ['userId' => $row['user_id'], 'displayName' => $user?->getDisplayName() ?? $row['user_id'],
				'role' => $row['role'], 'status' => $row['status']];
		}, array_merge($members, $this->mapper->members($id)));
	}

	public function invite(string $actor, int $id, string $uid, string $role): void
	{
		$workspace = $this->requireAdmin($actor, $id);
		$this->assertUser($uid);
		if (!in_array($role, self::ROLES, true)) {
			throw new ValidationException('validation_failed', '', [['field' => 'role', 'code' => 'invalid_role']]);
		}
		if ($workspace['ownerUid'] === $uid || !$this->mapper->invite($id, $uid, $role)) {
			throw new ConflictException('workspace_member_exists');
		}
		$invitation = $this->mapper->pendingInvitation($uid, $id);
		if ($invitation !== null) {
			$this->events->publish(WorkspaceSubjects::INVITED, $workspace, $actor, $this->mapper->members($id), $uid,
				['invitationId' => (int)$invitation['id'], 'role' => $role]);
		}
	}

	public function respond(string $uid, int $id, bool $accept, ?int $invitationId = null): void
	{
		$invitation = $this->mapper->pendingInvitation($uid, $id, $invitationId);
		if ($invitation === null || !$this->mapper->respond($id, $uid, $accept, (int)$invitation['id'])) {
			throw new NotFoundException('unknown_invitation');
		}
		$this->events->clearInvitation((int)$invitation['id'], $uid);
		$this->events->publish($accept ? WorkspaceSubjects::ACCEPTED : WorkspaceSubjects::DECLINED,
			['id' => $id, 'name' => $invitation['name'], 'ownerUid' => $invitation['owner_uid']],
			$uid, $this->mapper->members($id));
	}

	/** Reading a notification dismisses the bell entry without answering its invitation. */
	public function dismissInvitation(string $uid, int $id, int $invitationId): void
	{
		if ($this->mapper->pendingInvitation($uid, $id, $invitationId) === null) {
			throw new NotFoundException('unknown_invitation');
		}
		$this->events->clearInvitation($invitationId, $uid);
	}

	public function removeMember(string $actor, int $id, string $uid): void
	{
		$workspace = $this->requireMember($actor, $id);
		if ($workspace['ownerUid'] === $uid || ($actor !== $uid && !in_array($workspace['role'], ['owner', 'admin'], true))) {
			throw new PermissionDeniedException();
		}
		$members = $this->mapper->members($id);
		foreach ($members as $member) {
			if ($member['user_id'] === $uid && $this->mapper->removeMember($id, $uid)) {
				$this->events->clearInvitation((int)$member['id'], $uid);
				$subject = $member['status'] === 'pending' ? WorkspaceSubjects::REVOKED
					: ($actor === $uid ? WorkspaceSubjects::LEFT : WorkspaceSubjects::REMOVED);
				$this->events->publish($subject, $workspace, $actor, $members, $uid);
				return;
			}
		}
		throw new NotFoundException('unknown_workspace_member');
	}

	public function changeRole(string $actor, int $id, string $uid, string $role): void
	{
		$workspace = $this->requireAdmin($actor, $id);
		if ($uid === $workspace['ownerUid'] || !in_array($role, self::ROLES, true)) {
			throw new ValidationException('validation_failed', '', [['field' => 'role', 'code' => 'invalid_role']]);
		}
		foreach ($this->mapper->members($id) as $member) {
			if ($member['user_id'] === $uid && $member['status'] === 'accepted') {
				$this->mapper->changeRole($id, $uid, $role);
				$this->events->publish(WorkspaceSubjects::ROLE_CHANGED, $workspace, $actor, $this->mapper->members($id), $uid);
				return;
			}
		}
		throw new NotFoundException('unknown_workspace_member');
	}

	/** Permanently delete the workspace and its ledger. Unrelated workspaces stay intact. */
	public function delete(string $uid, int $id): void
	{
		$workspace = $this->requireOwner($uid, $id);
		$members = $this->mapper->members($id);
		try {
			$this->appDataFactory->get(Application::APP_ID)->getFolder(ItemPhotoService::workspaceFolder($id))->delete();
		} catch (FilesNotFoundException) {
			// Workspaces without photos have no app-data folder.
		}
		$this->db->beginTransaction();
		try {
			foreach (array_merge(self::DATA_TABLES, ['iv_ws_members']) as $table) {
				$qb = $this->db->getQueryBuilder();
				$qb->delete($table)->where($qb->expr()->eq('workspace_id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))->executeStatement();
			}
			$qb = $this->db->getQueryBuilder();
			$qb->delete('iv_workspaces')->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))->executeStatement();
			$this->db->commit();
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}
		foreach ($members as $member) {
			$this->events->clearInvitation((int)$member['id'], $member['user_id']);
		}
		$this->events->publish(WorkspaceSubjects::DELETED, $workspace, $uid, $members);
	}

	/** Account deletion revokes live grants; uid reuse must never recover old ownership. */
	public function purgeUser(string $uid): void
	{
		if (!$this->db->tableExists('iv_ws_members') || !$this->db->tableExists('iv_workspaces')) {
			return;
		}
		$qb = $this->db->getQueryBuilder();
		$qb->delete('iv_ws_members')->where($qb->expr()->eq('user_id', $qb->createNamedParameter($uid)))->executeStatement();
		$qb = $this->db->getQueryBuilder();
		$qb->update('iv_workspaces')->set('owner_uid', $qb->createNamedParameter(''))
			->set('demo_uid', $qb->createNamedParameter(null))
			->where($qb->expr()->eq('owner_uid', $qb->createNamedParameter($uid)))->executeStatement();
	}

	private function initialize(string $uid): void
	{
		$this->assertUser($uid);
		$key = 'inventorycheck:workspace-init:' . $uid;
		$this->locks->acquireLock($key, ILockingProvider::LOCK_EXCLUSIVE);
		try {
			if ($this->config->getUserValue($uid, Application::APP_ID, 'workspace_initialized', '0') === '1') {
				return;
			}
			$this->db->beginTransaction();
			try {
				$id = $this->mapper->create($uid, 'Demo workspace', true);
				$this->demo->seed($id, $uid);
				$this->config->setUserValue($uid, Application::APP_ID, 'workspace_initialized', '1');
				$this->db->commit();
			} catch (\Throwable $e) {
				$this->db->rollBack();
				throw $e;
			}
		} finally {
			$this->locks->releaseLock($key, ILockingProvider::LOCK_EXCLUSIVE);
		}
	}

	private function assertUser(string $uid): void
	{
		if ($uid === '' || !$this->users->userExists($uid)) {
			throw new ValidationException('unknown_user');
		}
	}

	private function validName(string $name): string
	{
		$name = trim($name);
		if ($name === '' || mb_strlen($name) > 255) {
			throw new ValidationException('validation_failed', '', [['field' => 'name', 'code' => 'invalid_name']]);
		}
		return $name;
	}

	private function toApi(array $row, string $uid): array
	{
		return ['id' => (int)$row['id'], 'name' => (string)$row['name'], 'ownerUid' => (string)$row['owner_uid'],
			'role' => $row['owner_uid'] === $uid ? 'owner' : (string)$row['role'],
			'status' => $row['owner_uid'] === $uid ? 'accepted' : (string)$row['status'],
			'isDemo' => $row['demo_uid'] !== null,
			'invitationId' => isset($row['invitation_id']) ? (int)$row['invitation_id'] : null];
	}
}
