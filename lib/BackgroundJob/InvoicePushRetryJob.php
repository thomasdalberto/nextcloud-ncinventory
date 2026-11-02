<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\BackgroundJob;

use OCA\InventoryCheck\AppInfo\Application;
use OCA\InventoryCheck\Db\WorkspaceMapper;
use OCA\InventoryCheck\Service\FlangeService;
use OCA\InventoryCheck\Service\InvoicePushService;
use OCA\InventoryCheck\Service\WorkspaceContext;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use OCP\IConfig;

/**
 * F8 retry drain — replays flagged invoice pushes. Each row carries the
 * issuing actor's uid; InvoiceCheck re-authorizes that actor on every
 * attempt, so the job grants nothing a user could not do directly.
 * IC's per-line ext_ref dedupe makes replays idempotent.
 */
class InvoicePushRetryJob extends TimedJob
{
	public function __construct(
		ITimeFactory $time,
		private readonly InvoicePushService $pushes,
		private readonly IConfig $config,
		private readonly WorkspaceContext $workspace,
		private readonly WorkspaceMapper $workspaces,
	) {
		parent::__construct($time);
		$this->setInterval(15 * 60);
		$this->setTimeSensitivity(self::TIME_INSENSITIVE);
	}

	protected function run($argument): void
	{
		// Honor the same admin opt-in as the issue path and manual retry:
		// disabling the toggle pauses the drain; rows stay retryable.
		if ($this->config->getAppValue(Application::APP_ID, FlangeService::KEY_INVOICE_ENABLED, '0') !== '1') {
			return;
		}
		foreach ($this->workspaces->allIds() as $id) {
			$this->workspace->runAsSystem($id, fn () => $this->pushes->drain());
		}
	}
}
