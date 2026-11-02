<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Service;

use OCA\InventoryCheck\Exception\PermissionDeniedException;
use OCP\IGroupManager;
use OCP\IUserSession;

/** Workspace membership is the sole inventory authorization policy. */
class AccessControlService
{
	public const KEY_ALLOW_NEGATIVE = 'allow_negative_stock';

	public function __construct(
		private readonly IGroupManager $groupManager,
		private readonly IUserSession $userSession,
		private readonly WorkspaceContext $workspace,
		private readonly WorkspaceSettings $settings,
	) {
	}

	public function currentUserId(): string
	{
		$user = $this->userSession->getUser();
		if ($user === null) { throw new PermissionDeniedException(); }
		return $user->getUID();
	}

	public function isSystemAdmin(string $uid): bool
	{
		return $uid !== '' && $this->groupManager->isAdmin($uid);
	}

	public function requireSystemAdmin(string $uid): void
	{
		if (!$this->isSystemAdmin($uid)) { throw new PermissionDeniedException(); }
	}

	public function isWorkspaceAdmin(string $uid): bool
	{
		return $uid !== '' && $this->workspace->canAdmin($uid);
	}

	public function requireWorkspaceAdmin(string $uid): void
	{
		if (!$this->isWorkspaceAdmin($uid)) { throw new PermissionDeniedException(); }
	}

	public function canWrite(string $uid): bool
	{
		return $uid !== '' && $this->workspace->canEdit($uid);
	}

	public function requireWrite(string $uid): void
	{
		if (!$this->canWrite($uid)) { throw new PermissionDeniedException(); }
	}

	public function allowNegativeStock(): bool
	{
		return $this->settings->get(self::KEY_ALLOW_NEGATIVE, '0') === '1';
	}

	public function setAllowNegativeStock(bool $enabled): void
	{
		$this->settings->set(self::KEY_ALLOW_NEGATIVE, $enabled ? '1' : '0');
	}
}
