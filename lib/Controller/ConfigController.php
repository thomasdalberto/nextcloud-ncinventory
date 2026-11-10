<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Controller;

use OCA\InventoryCheck\AppInfo\Application;
use OCA\InventoryCheck\Exception\ValidationException;
use OCA\InventoryCheck\Service\AccessControlService;
use OCA\InventoryCheck\Service\BoolParam;
use OCA\InventoryCheck\Service\LocationAclService;
use OCA\InventoryCheck\Service\LocationScanPolicy;
use OCA\InventoryCheck\Service\LowStockNotifyService;
use OCA\InventoryCheck\Service\LowStockService;
use OCA\InventoryCheck\Service\ProductLookupService;
use OCA\InventoryCheck\Service\QtyScale;
use OCA\InventoryCheck\Service\QtyScaleService;
use OCA\InventoryCheck\Service\ReasonCodes;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCA\InventoryCheck\Service\WorkspaceSettings;
use OCA\InventoryCheck\Service\WorkspaceService;
use OCA\InventoryCheck\Service\WorkspaceContext;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/** Settings for the selected workspace; all writes require its Admin role. */
class ConfigController extends Controller
{
	use PolicyAuditTrait;

	public function __construct(
		IRequest $request,
		private readonly AccessControlService $access,
		private readonly LowStockService $lowStock,
		private readonly QtyScaleService $qtyScaleService,
		private readonly LocationAclService $locationAcl,
		private readonly ProductLookupService $productLookup,
		private readonly WorkspaceSettings $settings,
		private readonly WorkspaceService $workspaces,
		private readonly WorkspaceContext $workspace,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(Application::APP_ID, $request);
	}


	#[NoAdminRequired]
	public function index(): JSONResponse
	{
		$uid = $this->access->currentUserId();
		return new JSONResponse([
			'isWorkspaceAdmin' => $this->access->isWorkspaceAdmin($uid),
			'canWrite' => $this->access->canWrite($uid),
			'allowNegativeStock' => $this->access->allowNegativeStock(),
			'lowStockNotifyUsers' => $this->access->isWorkspaceAdmin($uid) ? $this->settings->getList(LowStockNotifyService::KEY_NOTIFY_USER_IDS) : [],
			'locationReorderHintEnabled' => $this->lowStock->isPerLocationHintEnabled(),
			'qtyScale' => QtyScale::current($this->settings),
			'locationAclEnabled' => $this->locationAcl->isEnabled(),
			'requireAdjustReason' => ReasonCodes::isRequired($this->settings),
			'requireLocationScan' => LocationScanPolicy::isRequired($this->settings),
			'productLookupEnabled' => $this->productLookup->isEnabled(),
			'productLookupAutofire' => $this->productLookup->isAutofire(),
			'autoSku' => $this->settings->get('auto_sku', '0') === '1',
			'skuPrefix' => $this->settings->get('sku_prefix', 'SKU-'),
			'labelElements' => $this->settings->getList('label_elements') ?: \OCA\InventoryCheck\Util\LabelComposer::DEFAULT_ELEMENTS,
		]);
	}

	#[NoAdminRequired]
	public function saveCodes(): JSONResponse
	{
		$uid = $this->access->currentUserId();
		$this->access->requireWorkspaceAdmin($uid);
		$p = $this->request->getParams();
		$auto = $this->parseBool($p['autoSku'] ?? false, 'autoSku');
		$prefix = trim((string)($p['skuPrefix'] ?? 'SKU-'));
		$elements = $p['labelElements'] ?? \OCA\InventoryCheck\Util\LabelComposer::DEFAULT_ELEMENTS;
		if (!is_array($elements) || $elements === [] || array_filter($elements, static fn ($v): bool => !is_string($v)
			|| !in_array($v, \OCA\InventoryCheck\Util\LabelComposer::ELEMENTS, true)) !== []) {
			throw new ValidationException('validation_failed', '', [['field' => 'labelElements', 'code' => 'invalid_value']]);
		}
		if ($prefix === '' || mb_strlen($prefix) > 48 || !preg_match('/^[A-Za-z0-9._-]+$/', $prefix)) {
			throw new ValidationException('validation_failed', '', [['field' => 'skuPrefix', 'code' => 'invalid_code_format']]);
		}
		$this->settings->set('auto_sku', $auto ? '1' : '0');
		$this->settings->set('sku_prefix', $prefix);
		$this->settings->setList('label_elements', $elements);
		return $this->index();
	}

	#[NoAdminRequired]
	public function saveStock(): JSONResponse
	{
		$uid = $this->access->currentUserId();
		$this->access->requireWorkspaceAdmin($uid);
		$this->access->setAllowNegativeStock(BoolParam::parse($this->request->getParam('allowNegativeStock'), 'allowNegativeStock'));
		$this->auditPolicyChange($uid, 'stock_policy_saved', ['workspaceId' => $this->workspace->id()]);
		return $this->index();
	}

	#[NoAdminRequired]
	public function saveNotify(): JSONResponse
	{
		$uid = $this->access->currentUserId();
		$this->access->requireWorkspaceAdmin($uid);
		$p = $this->request->getParams();
		$users = $p['lowStockNotifyUsers'] ?? [];
		if (!is_array($users)) { throw new ValidationException('validation_failed'); }
		$members = array_column(array_filter($this->workspaces->members($uid, $this->workspace->id()),
			static fn (array $m): bool => $m['status'] === 'accepted'), 'userId');
		foreach ($users as $user) {
			if (!is_string($user) || !in_array($user, $members, true)) {
				throw new ValidationException('validation_failed', '', [['field' => 'lowStockNotifyUsers', 'code' => 'unknown_workspace_member']]);
			}
		}
		$hint = $this->parseBool($p['locationReorderHintEnabled'] ?? false, 'locationReorderHintEnabled');
		$this->settings->setList(LowStockNotifyService::KEY_NOTIFY_USER_IDS, $users);
		$this->lowStock->setPerLocationHintEnabled($hint);
		$this->auditPolicyChange($uid, 'notify_policy_saved', ['workspaceId' => $this->workspace->id()]);
		return $this->index();
	}

	private function parseBool(mixed $value, string $field): bool
	{
		return BoolParam::parse($value, $field);
	}
	#[NoAdminRequired]
	public function saveFractional(): JSONResponse
	{
		$uid = $this->access->currentUserId();
		$this->access->requireWorkspaceAdmin($uid);
		$result = $this->qtyScaleService->enableFractional();
		$this->auditPolicyChange($uid, 'qty_scale_fractional_enabled');
		return new JSONResponse(array_merge($result, ['qtyScale' => QtyScale::current($this->settings)]));
	}

	/** Wave D3/D8 policies */
	#[NoAdminRequired]
	public function saveWaveD(): JSONResponse
	{
		$uid = $this->access->currentUserId();
		$this->access->requireWorkspaceAdmin($uid);
		$p = $this->request->getParams();
		if (array_key_exists('requireAdjustReason', $p)) {
			ReasonCodes::setRequired($this->settings, $this->parseBool($p['requireAdjustReason'], 'requireAdjustReason'));
		}
		if (array_key_exists('requireLocationScan', $p)) {
			LocationScanPolicy::setRequired($this->settings, $this->parseBool($p['requireLocationScan'], 'requireLocationScan'));
		}
		if (array_key_exists('requireAdjustReason', $p) || array_key_exists('requireLocationScan', $p)) {
			$this->auditPolicyChange($uid, 'wave_d_policy_saved', [
				'requireAdjustReason' => ReasonCodes::isRequired($this->settings),
				'requireLocationScan' => LocationScanPolicy::isRequired($this->settings),
			]);
		}
		return $this->index();
	}

	/** Wave D3 catalog — any app user (P2) */
	#[NoAdminRequired]
	public function reasonCodes(): JSONResponse
	{
		return new JSONResponse(['data' => ReasonCodes::catalog()]);
	}

	#[NoAdminRequired]
	public function locationAcl(): JSONResponse
	{
		$uid = $this->access->currentUserId();
		$this->access->requireWorkspaceAdmin($uid);
		return new JSONResponse([
			'enabled' => $this->locationAcl->isEnabled(),
			'devicesStrict' => $this->locationAcl->isDevicesStrict(),
			'assignments' => $this->locationAcl->listAll(),
		]);
	}

	#[NoAdminRequired]
	public function saveLocationAcl(): JSONResponse
	{
		$uid = $this->access->currentUserId();
		$this->access->requireWorkspaceAdmin($uid);
		$p = $this->request->getParams();

		// Phase 1 — parse/validate only (no writes). Writing `enabled` before a
		// failing assignment replace left ACL half-applied; commit enabled last.
		$enabled = array_key_exists('enabled', $p)
			? $this->parseBool($p['enabled'], 'enabled')
			: null;
		$devicesStrict = array_key_exists('devicesStrict', $p)
			? $this->parseBool($p['devicesStrict'], 'devicesStrict')
			: null;

		$assignments = null;
		$subjectType = null;
		$subjectId = null;
		$locationIds = null;
		if (array_key_exists('assignments', $p)) {
			if (!is_array($p['assignments'])) {
				throw new ValidationException('validation_failed', '', [
					['field' => 'assignments', 'code' => 'invalid_type'],
				]);
			}
			/** @var list<array<string, mixed>> $assignments */
			$assignments = $p['assignments'];
		} elseif (array_key_exists('subjectType', $p) || array_key_exists('subjectId', $p) || array_key_exists('locationIds', $p)) {
			$subjectType = is_string($p['subjectType'] ?? null) ? $p['subjectType'] : '';
			$subjectId = is_string($p['subjectId'] ?? null) ? trim($p['subjectId']) : '';
			$locationIds = is_array($p['locationIds'] ?? null) ? $p['locationIds'] : [];
		}

		// Phase 2 — assignments first, then flags (fail closed on partial apply).
		if ($assignments !== null) {
			$this->locationAcl->replaceAll($uid, $assignments);
		} elseif ($subjectId !== null && $subjectId !== '') {
			$this->locationAcl->setForSubject((string)$subjectType, $subjectId, $locationIds ?? []);
		}
		// Apply devicesStrict before enabled: enabling both in one save must not
		// briefly leave ACL on while strict is still off (unbound scanners org-wide).
		if ($devicesStrict !== null) {
			$this->locationAcl->setDevicesStrict($devicesStrict);
		}
		if ($enabled !== null) {
			$this->locationAcl->setEnabled($enabled);
		}

		if ($assignments !== null || $subjectId !== null || $devicesStrict !== null || $enabled !== null) {
			$this->auditPolicyChange($uid, 'location_acl_saved', [
				'enabled' => $enabled,
				'devicesStrict' => $devicesStrict,
				'bulkAssignments' => $assignments !== null ? count($assignments) : null,
				'subject' => $subjectId !== null && $subjectId !== '' ? $subjectType . ':' . $subjectId : null,
			]);
		}
		return $this->locationAcl();
	}

}
