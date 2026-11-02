<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Service;

use OCA\InventoryCheck\AppInfo\Application;
use OCA\InventoryCheck\Db\ItemMapper;
use OCA\InventoryCheck\Db\LocationMapper;
use OCA\InventoryCheck\Exception\ValidationException;
use OCA\InventoryCheck\Public\StockIssueFacade;
use OCA\InventoryCheck\Public\StockIssueRequest;
use OCP\App\IAppManager;
use OCA\InventoryCheck\Service\WorkspaceSettings;

/**
 * Soft flanges to sibling Check apps (Wave B2 / C5).
 *
 * Failures never invent peer-app state; callers map insufficient_stock → sync failed.
 * Stock posts go through {@see StockIssueFacade} (FC-IV-ISSUE).
 */
class FlangeService
{
	public const KEY_MAINT_ENABLED = 'maint_flange_enabled';
	public const KEY_PROJECT_ENABLED = 'project_flange_enabled';
	public const KEY_INVOICE_ENABLED = 'invoice_flange_enabled';
	public const KEY_DEFAULT_ISSUE_LOCATION = 'flange_default_location_id';
	public const REF_MAINT_WO = 'maint_wo';
	public const REF_PROJECT = 'project';

	public function __construct(
		private readonly WorkspaceSettings $settings,
		private readonly IAppManager $appManager,
		private readonly LocationMapper $locations,
		private readonly AccessControlService $access,
		private readonly StockIssueFacade $stockIssue,
		private readonly InvoicePushService $invoicePush,
		private readonly ItemMapper $items,
	) {
	}

	/** @return array<string, mixed> */
	public function status(): array
	{
		return [
			'maintenanceCheckEnabled' => $this->appManager->isEnabledForUser('maintenancecheck'),
			'maintFlangeEnabled' => $this->isMaintEnabled(),
			'projectCheckEnabled' => $this->appManager->isEnabledForUser('projectcheck'),
			'projectFlangeEnabled' => $this->isProjectEnabled(),
			'invoiceCheckEnabled' => $this->appManager->isEnabledForUser('invoicecheck'),
			'invoiceFlangeEnabled' => $this->isInvoiceEnabled(),
			'invoicePushPending' => $this->invoicePush->pendingCount(),
			'defaultIssueLocationId' => $this->defaultLocationId(),
		];
	}

	public function setMaintEnabled(bool $enabled): void
	{
		$this->settings->set(self::KEY_MAINT_ENABLED, $enabled ? '1' : '0');
	}

	public function setProjectEnabled(bool $enabled): void
	{
		$this->settings->set(self::KEY_PROJECT_ENABLED, $enabled ? '1' : '0');
	}

	public function setInvoiceEnabled(bool $enabled): void
	{
		$this->settings->set(self::KEY_INVOICE_ENABLED, $enabled ? '1' : '0');
	}

	public function setDefaultLocationId(?int $locationId): void
	{
		if ($locationId === null || $locationId <= 0) {
			$this->settings->delete(self::KEY_DEFAULT_ISSUE_LOCATION);
			return;
		}
		$loc = $this->locations->findById($locationId);
		if (!$loc->getActive()) {
			throw new ValidationException('inactive_location');
		}
		$this->settings->set(self::KEY_DEFAULT_ISSUE_LOCATION, (string)$locationId);
	}

	/**
	 * @param list<array{sku: string, qty: int}> $lines
	 * @return array{ok: bool, inventory_sync: string, movements: list<array<string, mixed>>, errors: list<array{sku: string, code: string}>}
	 */
	public function issueForMaintWo(string $actorUid, int $woId, array $lines, ?int $locationId = null): array
	{
		$this->access->requireWrite($actorUid);
		if (!$this->isMaintEnabled()) {
			return ['ok' => false, 'inventory_sync' => 'disabled', 'movements' => [], 'errors' => [
				['sku' => '', 'code' => 'flange_disabled'],
			]];
		}
		if (!$this->appManager->isEnabledForUser('maintenancecheck')) {
			return ['ok' => false, 'inventory_sync' => 'peer_absent', 'movements' => [], 'errors' => [
				['sku' => '', 'code' => 'peer_absent'],
			]];
		}
		if ($woId <= 0) {
			throw new ValidationException('validation_failed', '', [['field' => 'woId', 'code' => 'validation_failed']]);
		}
		return $this->runIssue($actorUid, $lines, StockIssueRequest::REF_MAINT_WO, $woId, $locationId, self::REF_MAINT_WO);
	}

	/**
	 * @param list<array{sku: string, qty: int}> $lines
	 * @return array{ok: bool, inventory_sync: string, movements: list<array<string, mixed>>, errors: list<array{sku: string, code: string}>}
	 */
	public function issueForProject(string $actorUid, int $projectId, array $lines, ?int $locationId = null): array
	{
		$this->access->requireWrite($actorUid);
		if (!$this->isProjectEnabled()) {
			return ['ok' => false, 'inventory_sync' => 'disabled', 'movements' => [], 'errors' => [
				['sku' => '', 'code' => 'flange_disabled'],
			]];
		}
		if (!$this->appManager->isEnabledForUser('projectcheck')) {
			return ['ok' => false, 'inventory_sync' => 'peer_absent', 'movements' => [], 'errors' => [
				['sku' => '', 'code' => 'peer_absent'],
			]];
		}
		if ($projectId <= 0) {
			throw new ValidationException('validation_failed', '', [['field' => 'projectId', 'code' => 'validation_failed']]);
		}
		$result = $this->runIssue($actorUid, $lines, StockIssueRequest::REF_PROJECT, $projectId, $locationId, self::REF_PROJECT);
		// F8: mirror issued materials onto the customer's InvoiceCheck
		// draft. Fail-soft — the committed issue is the business fact;
		// the push rides the outbox flag+retry path.
		if ($result['ok']) {
			$result['invoice_sync'] = $this->pushToInvoice($actorUid, $projectId, $result['movements']);
		}
		return $result;
	}

	/**
	 * F8 push after a committed project issue. Returns the
	 * `invoice_sync` verdict: ok | skipped | failed.
	 *
	 * @param list<array<string, mixed>> $movements
	 */
	private function pushToInvoice(string $actorUid, int $projectId, array $movements): string
	{
		if (!$this->isInvoiceEnabled() || $movements === []) {
			return 'skipped';
		}
		if (!$this->appManager->isEnabledForUser('invoicecheck')) {
			return 'skipped';
		}
		$lines = [];
		foreach ($movements as $m) {
			$sku = (string) ($m['sku'] ?? '');
			$qty = $m['qty'] ?? 0;
			if ($sku === '' || !is_numeric($qty) || (float) $qty <= 0) {
				continue;
			}
			$item = $this->items->findBySku($sku);
			$lines[] = [
				'sku' => $sku,
				'text' => $item !== null ? (string) $item->getName() : $sku,
				'qty' => (float) $qty,
				'unit' => 'items',
			];
		}
		if ($lines === []) {
			return 'skipped';
		}
		return $this->invoicePush->stageAndPush($actorUid, $projectId, $movements, $lines);
	}

	/**
	 * @param list<array{sku: string, qty: int}> $lines
	 * @return array{ok: bool, inventory_sync: string, movements: list<array<string, mixed>>, errors: list<array{sku: string, code: string}>}
	 */
	private function runIssue(
		string $actorUid,
		array $lines,
		string $refType,
		int $refId,
		?int $locationId,
		string $apiRefType,
	): array {
		$result = $this->stockIssue->issueBySkuBundle(new StockIssueRequest(
			actorUid: $actorUid,
			lines: $lines,
			locationPolicy: StockIssueRequest::POLICY_EQUIPMENT_DEFAULT,
			refType: $refType,
			refId: $refId,
			locationId: $locationId,
		));

		if ($result->ok) {
			$movements = [];
			foreach (($result->data['movements'] ?? []) as $row) {
				$movements[] = [
					'id' => (int)($row['movementId'] ?? 0),
					'sku' => (string)($row['sku'] ?? ''),
					'qty' => $row['qty'] ?? 0,
					'locationId' => (int)($row['locationId'] ?? 0),
					'refType' => $apiRefType,
					'refId' => $refId,
				];
			}
			return [
				'ok' => true,
				'inventory_sync' => 'ok',
				'movements' => $movements,
				'errors' => [],
			];
		}

		$code = $result->code ?? 'inventory_sync_failed';
		$sku = (string)(($result->data['sku'] ?? '') ?: '');
		return [
			'ok' => false,
			'inventory_sync' => 'failed',
			'movements' => [],
			'errors' => [['sku' => $sku, 'code' => $code]],
		];
	}

	private function isMaintEnabled(): bool
	{
		return $this->settings->get(self::KEY_MAINT_ENABLED, '0') === '1';
	}

	private function isProjectEnabled(): bool
	{
		return $this->settings->get(self::KEY_PROJECT_ENABLED, '0') === '1';
	}

	private function isInvoiceEnabled(): bool
	{
		return $this->settings->get(self::KEY_INVOICE_ENABLED, '0') === '1';
	}

	/**
	 * F8 flag+retry: drain the outbox with the retrying actor's uid —
	 * InvoiceCheck authorizes it against the project. Attempt budget
	 * (bounded, see InvoicePush::findRetryable) is shared across this
	 * manual drain and the cron InvoicePushRetryJob — an office user
	 * under-authorized on a project burns attempts either way; the row
	 * keeps last_error for the settings surface.
	 */
	public function retryInvoicePushes(string $actorUid): int
	{
		if (!$this->isInvoiceEnabled()) {
			return 0;
		}
		return $this->invoicePush->drain($actorUid);
	}

	private function defaultLocationId(): ?int
	{
		$raw = (string)$this->settings->get(self::KEY_DEFAULT_ISSUE_LOCATION, '');
		if ($raw === '' || !ctype_digit($raw)) {
			return null;
		}
		return (int)$raw;
	}
}
