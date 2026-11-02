<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Activity;

use OCA\InventoryCheck\AppInfo\Application;
use OCA\InventoryCheck\Service\LowStockNotifyService;
use OCA\InventoryCheck\Service\WorkspaceEventService;
use OCP\Activity\IFilter;
use OCP\IURLGenerator;

/** Native Activity navigation entry; Activity still restricts results to the current user. */
class Filter implements IFilter
{
	public function __construct(private readonly IURLGenerator $url) {
	}

	public function getIdentifier(): string { return Application::APP_ID; }
	public function getName(): string { return 'InventoryCheck'; }
	public function getPriority(): int { return 90; }

	public function getIcon(): string
	{
		return $this->url->getAbsoluteURL($this->url->imagePath(Application::APP_ID, 'app-dark.svg'));
	}

	public function filterTypes(array $types): array
	{
		// Include the legacy low-stock type, which predates Activity settings registration.
		return array_values(array_unique(array_merge($types, [LowStockNotifyService::TYPE, WorkspaceEventService::TYPE])));
	}

	public function allowedApps(): array { return [Application::APP_ID]; }
}
