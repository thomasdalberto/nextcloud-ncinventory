<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Service;

use OCA\InventoryCheck\Db\WorkspaceMapper;
use OCA\InventoryCheck\Exception\NotFoundException;
use OCA\InventoryCheck\Exception\PermissionDeniedException;
use OCA\InventoryCheck\Exception\ValidationException;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IRequest;
use OCP\IUserSession;

/** Request-local scope. Missing scope fails closed; there is no unscoped mode. */
class WorkspaceContext
{
	private ?array $current = null;
	private ?string $actor = null;

	public function __construct(
		private readonly WorkspaceService $workspaces,
		private readonly WorkspaceMapper $mapper,
		private readonly IUserSession $session,
		private readonly IRequest $request,
	) {
	}

	public function current(): array
	{
		if ($this->current === null) {
			$uid = $this->session->getUser()?->getUID() ?? '';
			if ($uid === '') {
				throw new PermissionDeniedException();
			}
			$raw = $this->request->getHeader('X-IV-Workspace') ?: $this->request->getParam('workspaceId');
			if ($raw === null || $raw === '') {
				foreach ($this->workspaces->list($uid) as $workspace) {
					if ($workspace['status'] === 'accepted') {
						$this->current = $workspace;
						$this->actor = $uid;
						break;
					}
				}
				if ($this->current === null) {
					throw new NotFoundException('workspace_required');
				}
			} else {
				if ((!is_string($raw) && !is_int($raw)) || !ctype_digit((string)$raw) || (int)$raw < 1) {
					throw new ValidationException('validation_failed', '', [['field' => 'workspaceId', 'code' => 'invalid_id']]);
				}
				$this->current = $this->workspaces->requireMember($uid, (int)$raw);
				$this->actor = $uid;
			}
		}
		return $this->current;
	}

	public function id(): int
	{
		return $this->current()['id'];
	}

	public function select(int $id): array
	{
		$uid = $this->session->getUser()?->getUID() ?? '';
		$this->current = null;
		$this->actor = null;
		$this->current = $this->workspaces->requireMember($uid, $id);
		$this->actor = $uid;
		return $this->current;
	}

	public function canEdit(string $uid): bool
	{
		$current = $this->current();
		return $uid === $this->actor && in_array($current['role'], ['owner', 'admin', 'editor'], true);
	}

	public function canAdmin(string $uid): bool
	{
		$current = $this->current();
		return $uid === $this->actor && in_array($current['role'], ['owner', 'admin'], true);
	}

	public function requireAdmin(): void
	{
		if (!in_array($this->current()['role'], ['owner', 'admin'], true)) {
			throw new PermissionDeniedException();
		}
	}

	public function canRead(string $uid): bool
	{
		try {
			$this->workspaces->requireMember($uid, $this->id());
			return true;
		} catch (NotFoundException|PermissionDeniedException) {
			return false;
		}
	}

	public function requireWrite(): void
	{
		if (!in_array($this->current()['role'], ['owner', 'admin', 'editor', 'device', 'system'], true)) {
			throw new PermissionDeniedException();
		}
	}

	public function apply(IQueryBuilder $qb, string $alias = ''): void
	{
		$qb->andWhere($qb->expr()->eq(($alias === '' ? '' : $alias . '.') . 'workspace_id',
			$qb->createNamedParameter($this->id(), IQueryBuilder::PARAM_INT)));
	}

	/** Device authentication determines scope; client-supplied workspace ids cannot override it. */
	public function forDevice(int $workspaceId): void
	{
		$this->mapper->find($workspaceId);
		$this->current = ['id' => $workspaceId, 'role' => 'device'];
		$this->actor = null;
	}

	/** Only trusted maintenance/cron entry points call this, one workspace at a time. */
	public function runAsSystem(int $id, callable $callback): mixed
	{
		$this->mapper->find($id);
		$previous = $this->current;
		$actor = $this->actor;
		$this->current = ['id' => $id, 'role' => 'system'];
		$this->actor = null;
		try {
			return $callback();
		} finally {
			$this->current = $previous;
			$this->actor = $actor;
		}
	}
}
