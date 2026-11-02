<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Activity;

use OCA\InventoryCheck\Service\WorkspaceEventService;
use OCP\Activity\ActivitySettings;
use OCP\IL10N;

class WorkspaceSetting extends ActivitySettings
{
	public function __construct(private readonly IL10N $l) {
	}

	public function getIdentifier(): string { return WorkspaceEventService::TYPE; }
	public function getName(): string { return $this->l->t('Workspace membership and changes'); }
	public function getGroupIdentifier(): string { return 'inventorycheck'; }
	public function getGroupName(): string { return 'InventoryCheck'; }
	public function getPriority(): int { return 50; }
	// Invitations have their own actionable notification; Activity remains a history feed.
	public function canChangeNotification(): bool { return false; }
}
