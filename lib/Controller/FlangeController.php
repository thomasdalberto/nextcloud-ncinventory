<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Controller;

use OCA\InventoryCheck\AppInfo\Application;
use OCA\InventoryCheck\Service\AccessControlService;
use OCA\InventoryCheck\Service\BoolParam;
use OCA\InventoryCheck\Service\FlangeService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * Soft flanges to sibling Check apps (Wave B2 / C5). Status/settings are
 * app-admin only; the maintenance-issue action is office-only (enforced
 * inside {@see FlangeService}).
 *
 * Line qty stays in display units here; {@see \OCA\InventoryCheck\Public\StockIssueFacade}
 * owns display→storage conversion (C1).
 */
class FlangeController extends Controller
{
	use PolicyAuditTrait;

	public function __construct(
		IRequest $request,
		private readonly FlangeService $flange,
		private readonly AccessControlService $access,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	#[NoAdminRequired]
	public function status(): JSONResponse
	{
		$this->access->requireWorkspaceAdmin($this->access->currentUserId());
		return new JSONResponse($this->flange->status());
	}

	#[NoAdminRequired]
	public function issueMaintWo(): JSONResponse
	{
		$p = $this->request->getParams();
		$lines = $this->normalizeLines(is_array($p['lines'] ?? null) ? $p['lines'] : []);
		return new JSONResponse($this->flange->issueForMaintWo(
			$this->access->currentUserId(),
			(int)($p['woId'] ?? 0),
			$lines,
			isset($p['locationId']) && $p['locationId'] !== '' ? (int)$p['locationId'] : null,
		));
	}

	#[NoAdminRequired]
	public function issueProject(): JSONResponse
	{
		$p = $this->request->getParams();
		$lines = $this->normalizeLines(is_array($p['lines'] ?? null) ? $p['lines'] : []);
		return new JSONResponse($this->flange->issueForProject(
			$this->access->currentUserId(),
			(int)($p['projectId'] ?? 0),
			$lines,
			isset($p['locationId']) && $p['locationId'] !== '' ? (int)$p['locationId'] : null,
		));
	}

	#[NoAdminRequired]
	public function saveSettings(): JSONResponse
	{
		$uid = $this->access->currentUserId();
		$this->access->requireWorkspaceAdmin($uid);
		$p = $this->request->getParams();
		if (array_key_exists('maintFlangeEnabled', $p)) {
			$this->flange->setMaintEnabled(BoolParam::parse($p['maintFlangeEnabled'], 'maintFlangeEnabled'));
		}
		if (array_key_exists('projectFlangeEnabled', $p)) {
			$this->flange->setProjectEnabled(BoolParam::parse($p['projectFlangeEnabled'], 'projectFlangeEnabled'));
		}
		if (array_key_exists('invoiceFlangeEnabled', $p)) {
			$this->flange->setInvoiceEnabled(BoolParam::parse($p['invoiceFlangeEnabled'], 'invoiceFlangeEnabled'));
		}
		if (array_key_exists('defaultIssueLocationId', $p)) {
			$raw = $p['defaultIssueLocationId'];
			$this->flange->setDefaultLocationId($raw === null || $raw === '' ? null : (int)$raw);
		}
		$changed = array_values(array_intersect(
			['maintFlangeEnabled', 'projectFlangeEnabled', 'invoiceFlangeEnabled', 'defaultIssueLocationId'],
			array_keys($p),
		));
		if ($changed !== []) {
			$this->auditPolicyChange($uid, 'flange_settings_saved', ['keys' => $changed]);
		}
		return new JSONResponse($this->flange->status());
	}

	/**
	 * F8: retry queued invoice pushes. The retrying actor's uid drives the
	 * push — InvoiceCheck authorizes it against the project, so a manager
	 * can push what the issuing warehouse user could not.
	 */
	#[NoAdminRequired]
	public function retryInvoicePush(): JSONResponse
	{
		$uid = $this->access->currentUserId();
		$this->access->requireWrite($uid);
		$done = $this->flange->retryInvoicePushes($uid);
		return new JSONResponse(['ok' => true, 'pushed' => $done, 'pending' => (int) ($this->flange->status()['invoicePushPending'] ?? 0)]);
	}

	/**
	 * @param list<mixed> $lines
	 * @return list<array{sku: string, qty: mixed}>
	 */
	private function normalizeLines(array $lines): array
	{
		$out = [];
		foreach ($lines as $line) {
			if (!is_array($line)) {
				continue;
			}
			$sku = (string)($line['sku'] ?? '');
			if ($sku === '' || !array_key_exists('qty', $line)) {
				continue;
			}
			$out[] = [
				'sku' => $sku,
				// Display qty — StockIssueFacade owns display→storage (C1).
				'qty' => $line['qty'],
			];
		}
		return $out;
	}
}
