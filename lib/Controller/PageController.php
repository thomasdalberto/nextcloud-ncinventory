<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Controller;

use OCA\InventoryCheck\AppInfo\Application;
use OCA\InventoryCheck\Service\AccessControlService;
use OCA\InventoryCheck\Service\WorkspaceSettings;
use OCA\InventoryCheck\Service\LocationAclService;
use OCA\InventoryCheck\Service\LocationScanPolicy;
use OCA\InventoryCheck\Service\LowStockService;
use OCA\InventoryCheck\Service\ProductLookupService;
use OCA\InventoryCheck\Service\QtyScale;
use OCA\InventoryCheck\Service\ReasonCodes;
use OCA\InventoryCheck\Service\SettingsSectionCatalog;
use OCA\InventoryCheck\Service\WorkspaceContext;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\NotFoundResponse;
use OCP\AppFramework\Http\RedirectResponse;
use OCP\AppFramework\Http\Response;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IConfig;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUserSession;
use OCP\L10N\IFactory;
use OCP\Util;

class PageController extends Controller
{
	public function __construct(
		IRequest $request,
		private readonly AccessControlService $access,
		private readonly IUserSession $userSession,
		private readonly IURLGenerator $urlGenerator,
		private readonly IFactory $l10nFactory,
		private readonly IConfig $config,
		private readonly SettingsSectionCatalog $settingsSections,
		private readonly ProductLookupService $productLookup,
		private readonly WorkspaceContext $workspace,
		private readonly WorkspaceSettings $settings,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function workspaces(): TemplateResponse|RedirectResponse
	{
		return $this->page('workspaces', 'Workspaces', 'Your inventories and workspace invitations.');
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function dashboard(): TemplateResponse|RedirectResponse
	{
		return $this->page(
			'dashboard',
			'Dashboard',
			'Receive, issue, and transfer stock — low stock and recent bookings below'
		);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function items(): TemplateResponse|RedirectResponse
	{
		return $this->page(
			'items',
			'Items',
			'Create SKUs, receive stock, then print or scan labels'
		);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function item(int $id): TemplateResponse|RedirectResponse
	{
		return $this->page(
			'item-detail',
			'Item',
			'Balances, receive or issue, and print the label',
			$id
		);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function locations(): TemplateResponse|RedirectResponse
	{
		return $this->page(
			'locations',
			'Locations',
			'Add warehouses and vans, star favourites, then book stock here'
		);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function location(int $id): TemplateResponse|RedirectResponse
	{
		return $this->page(
			'location-detail',
			'Location',
			'Stock here, favourites, and booking actions',
			$id
		);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function movements(): TemplateResponse|RedirectResponse
	{
		return $this->page(
			'movements',
			'Movements',
			'Every booking is listed here — filter, export, or reverse a mistake'
		);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function stocktake(): TemplateResponse|RedirectResponse
	{
		return $this->page(
			'stocktake',
			'Stocktake',
			'Compare shelf quantities with system stock, then close to post adjustments'
		);
	}

	/**
	 * Location-first stocktake start — dedicated page (not a modal).
	 * Long searchable lists belong on a page per design-system chooser rules.
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function stocktakeNew(): TemplateResponse|RedirectResponse
	{
		return $this->page(
			'stocktake-new',
			'New stocktake',
			'Tap a location below to start counting — favourites appear first'
		);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function stocktakeCampaign(int $id): TemplateResponse|RedirectResponse
	{
		return $this->page('stocktake', 'Stocktake', 'Cycle counts and Inventur campaigns', $id);
	}

	/**
	 * Legacy single-page settings URL — redirects to the default sub-page.
	 * Route name inventorycheck.page.settings is kept for bookmarks and cross-app links.
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function settings(): RedirectResponse
	{
		$uid = $this->access->currentUserId();
		$this->access->requireWorkspaceAdmin($uid);
		$url = $this->urlGenerator->linkToRoute(
			'inventorycheck.page.settingsSection',
			['section' => SettingsSectionCatalog::DEFAULT_SECTION, 'workspaceId' => $this->workspace->id()],
		);
		// In some PHPUnit contexts the route map is not populated, so linkToRoute() can return ''.
		// Fall back to the known settings URL structure so redirects are non-empty and deterministic.
		if ($url === '') {
			$url = $this->settingsSectionFallbackUrl(SettingsSectionCatalog::DEFAULT_SECTION);
		}
		return new RedirectResponse($url);
	}

	/**
	 * One settings sub-page per catalog section (design-system SETTINGS-PAGES-STANDARD).
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function settingsSection(string $section): Response
	{
		$uid = $this->access->currentUserId();
		$this->access->requireWorkspaceAdmin($uid);
		$section = strtolower(trim($section));
		if (!$this->settingsSections->isSection($section)) {
			return new NotFoundResponse();
		}

		$l = $this->l10nFactory->get(Application::APP_ID);
		return $this->page(
			'settings',
			$this->settingsSections->label($l, $section),
			$this->settingsSections->help($l, $section),
			null,
			$section,
		);
	}

	private function page(
		string $pageId,
		string $titleKey,
		string $hintKey,
		?int $entityId = null,
		?string $settingsSection = null,
	): TemplateResponse|RedirectResponse {
		$currentWorkspace = null;
		if ($pageId !== 'workspaces') {
			try {
				$currentWorkspace = $this->workspace->current();
			} catch (\OCA\InventoryCheck\Exception\NotFoundException $e) {
				if ($e->getErrorCode() !== 'workspace_required') { throw $e; }
				return new RedirectResponse($this->urlGenerator->linkToRoute('inventorycheck.page.workspaces'));
			}
		}
		Util::addScript(Application::APP_ID, 'vendor/qr-scanner/qr-scanner.legacy.min');
		Util::addScript(Application::APP_ID, 'app');
		Util::addScript(Application::APP_ID, 'common/product-lookup');
		// Soft keyboard: keep focused notes/inputs above the IME on phones.
		Util::addScript(Application::APP_ID, 'common/keep-focused-visible');

		Util::addStyle(Application::APP_ID, 'app');

		$l = $this->l10nFactory->get(Application::APP_ID);
		$uid = $this->userSession->getUser()?->getUID() ?? '';
		$isWorkspaceAdmin = $currentWorkspace !== null && $this->access->isWorkspaceAdmin($uid);
		$isSystemAdmin = $this->access->isSystemAdmin($uid);
		$canWrite = $currentWorkspace !== null && $this->access->canWrite($uid);

		$settingsSectionUrls = [];
		$settingsSectionLabels = [];
		foreach (SettingsSectionCatalog::SECTIONS as $sectionId) {
			$settingsSectionUrls[$sectionId] = $this->urlGenerator->linkToRoute(
				'inventorycheck.page.settingsSection',
				['section' => $sectionId],
			);
			$settingsSectionLabels[$sectionId] = $this->settingsSections->navLabel($l, $sectionId);
		}

		$defaultSettingsUrl = $settingsSectionUrls[SettingsSectionCatalog::DEFAULT_SECTION]
			?? $this->urlGenerator->linkToRoute('inventorycheck.page.settings');

		$urls = [
			'pages' => [
				'workspaces' => $this->urlGenerator->linkToRoute('inventorycheck.page.workspaces'),
				'dashboard' => $this->urlGenerator->linkToRoute('inventorycheck.page.dashboard'),
				'items' => $this->urlGenerator->linkToRoute('inventorycheck.page.items'),
				'locations' => $this->urlGenerator->linkToRoute('inventorycheck.page.locations'),
				'movements' => $this->urlGenerator->linkToRoute('inventorycheck.page.movements'),
				'stocktake' => $this->urlGenerator->linkToRoute('inventorycheck.page.stocktake'),
				'stocktakeNew' => $this->urlGenerator->linkToRoute('inventorycheck.page.stocktakeNew'),
				'stocktakeCampaign' => $this->urlGenerator->linkToRoute('inventorycheck.page.stocktakeCampaign', ['id' => 0]),
				'settings' => $defaultSettingsUrl,
			],
			'settingsSections' => $settingsSectionUrls,
			'api' => [
				'workspaces' => $this->urlGenerator->linkToRoute('inventorycheck.workspace.index'),
				'items' => $this->urlGenerator->linkToRoute('inventorycheck.item.index'),
				'itemByCode' => $this->urlGenerator->linkToRoute('inventorycheck.item.byCode', ['code' => '__CODE__']),
				'itemLabel' => $this->urlGenerator->linkToRoute('inventorycheck.item.label', ['id' => 0]),
				'itemQr' => $this->urlGenerator->linkToRoute('inventorycheck.item.qr', ['id' => 0]),
				'itemLabelPrint' => $this->urlGenerator->linkToRoute('inventorycheck.item.labelPrint', ['id' => 0]),
				'itemBulkLabels' => $this->urlGenerator->linkToRoute('inventorycheck.item.bulkLabels'),
				'itemPhoto' => $this->urlGenerator->linkToRoute('inventorycheck.itemPhoto.show', ['id' => 0]),
				'itemPhotoUpload' => $this->urlGenerator->linkToRoute('inventorycheck.itemPhoto.upload', ['id' => 0]),
				'itemPhotoDelete' => $this->urlGenerator->linkToRoute('inventorycheck.itemPhoto.destroy', ['id' => 0]),
				'locations' => $this->urlGenerator->linkToRoute('inventorycheck.location.index'),
				'balances' => $this->urlGenerator->linkToRoute('inventorycheck.balance.index'),
				'movements' => $this->urlGenerator->linkToRoute('inventorycheck.movement.index'),
				'movementReceive' => $this->urlGenerator->linkToRoute('inventorycheck.movement.receive'),
				'movementIssue' => $this->urlGenerator->linkToRoute('inventorycheck.movement.issue'),
				'movementTransfer' => $this->urlGenerator->linkToRoute('inventorycheck.movement.transfer'),
				'movementAdjust' => $this->urlGenerator->linkToRoute('inventorycheck.movement.adjust'),
				'lowStock' => $this->urlGenerator->linkToRoute('inventorycheck.lowStock.index'),
				'lowStockPerLocation' => $this->urlGenerator->linkToRoute('inventorycheck.lowStock.perLocation'),
				'export' => $this->urlGenerator->linkToRoute('inventorycheck.export.index'),
				'importDryRun' => $this->urlGenerator->linkToRoute('inventorycheck.import.dryRun'),
				'importCommit' => $this->urlGenerator->linkToRoute('inventorycheck.import.commit'),
				'cycleCounts' => $this->urlGenerator->linkToRoute('inventorycheck.cycleCount.index'),
				'cycleCountStart' => $this->urlGenerator->linkToRoute('inventorycheck.cycleCount.start', ['id' => 0]),
				'cycleCountSetCount' => $this->urlGenerator->linkToRoute('inventorycheck.cycleCount.setCount', ['lineId' => 0]),
				'cycleCountClose' => $this->urlGenerator->linkToRoute('inventorycheck.cycleCount.close', ['id' => 0]),
				'favouriteLocations' => $this->urlGenerator->linkToRoute('inventorycheck.favourite.index'),
				'favouriteLocationRemove' => $this->urlGenerator->linkToRoute('inventorycheck.favourite.destroy', ['locationId' => 0]),
				'flangeStatus' => $this->urlGenerator->linkToRoute('inventorycheck.flange.status'),
				'flangeSettings' => $this->urlGenerator->linkToRoute('inventorycheck.flange.saveSettings'),
				'flangeInvoicePushRetry' => $this->urlGenerator->linkToRoute('inventorycheck.flange.retryInvoicePush'),
				'configStock' => $this->urlGenerator->linkToRoute('inventorycheck.config.saveStock'),
				'appConfig' => $this->urlGenerator->linkToRoute('inventorycheck.administration.index'),
				'scanners' => $this->urlGenerator->linkToRoute('inventorycheck.scanner.index'),
				'config' => $this->urlGenerator->linkToRoute('inventorycheck.config.index'),
				'configNotify' => $this->urlGenerator->linkToRoute('inventorycheck.config.saveNotify'),
				'configFractional' => $this->urlGenerator->linkToRoute('inventorycheck.config.saveFractional'),
				'configWaveD' => $this->urlGenerator->linkToRoute('inventorycheck.config.saveWaveD'),
				'configCodes' => $this->urlGenerator->linkToRoute('inventorycheck.config.saveCodes'),
				'reasonCodes' => $this->urlGenerator->linkToRoute('inventorycheck.config.reasonCodes'),
				'configLocationAcl' => $this->urlGenerator->linkToRoute('inventorycheck.config.locationAcl'),
				'productLookup' => $this->urlGenerator->linkToRoute('inventorycheck.productLookup.lookup'),
				'locationLabel' => $this->urlGenerator->linkToRoute('inventorycheck.location.label', ['id' => 0]),
				'locationLabelPrint' => $this->urlGenerator->linkToRoute('inventorycheck.location.labelPrint', ['id' => 0]),
				'locationBulkLabels' => $this->urlGenerator->linkToRoute('inventorycheck.location.bulkLabels'),
				'directorySearchUsers' => $this->urlGenerator->linkToRoute('inventorycheck.directory.searchUsers'),
				'directorySearchGroups' => $this->urlGenerator->linkToRoute('inventorycheck.directory.searchGroups'),
			],
		];

		if ($currentWorkspace !== null) {
			foreach (['pages', 'settingsSections'] as $group) {
				foreach ($urls[$group] as $key => &$url) {
					if ($key !== 'workspaces') {
						$url .= (str_contains($url, '?') ? '&' : '?') . 'workspaceId=' . $currentWorkspace['id'];
					}
				}
				unset($url);
			}
		}

		// Prefer translated title/hint when callers already passed IL10N output
		// (settings sections); otherwise translate the English key.
		$pageTitle = ($pageId === 'settings' && $settingsSection !== null)
			? $titleKey
			: $l->t($titleKey);
		$pageHint = ($pageId === 'settings' && $settingsSection !== null)
			? $hintKey
			: $l->t($hintKey);

		$params = [
			'workspace' => $currentWorkspace,
			'pageId' => $pageId,
			'pageTitle' => $pageTitle,
			'pageHint' => $pageHint,
			'entityId' => $entityId,
			'currentUserId' => $uid,
			'isWorkspaceAdmin' => $isWorkspaceAdmin,
			'isSystemAdmin' => $isSystemAdmin,
			'canWrite' => $canWrite,
			'urlsJson' => json_encode($urls, JSON_UNESCAPED_SLASHES),
			'urls' => $urls,
			// Always expose section labels so the Settings submenu is populated from every page
			// (not only when already on a settings sub-page).
			'settingsSectionLabels' => $settingsSectionLabels,
			'productLookupEnabled' => $this->productLookup->isEnabled(),
			'productLookupAutofire' => $this->productLookup->isAutofire(),
			'allowNegativeStock' => $currentWorkspace !== null && $this->access->allowNegativeStock(),
			'locationReorderHintEnabled' => $currentWorkspace !== null && $this->settings->get(
				LowStockService::KEY_LOCATION_REORDER_HINT_ENABLED,
				'0',
			) === '1',
			'requireAdjustReason' => ($currentWorkspace !== null && ReasonCodes::isRequired($this->settings)),
			'requireLocationScan' => ($currentWorkspace !== null && LocationScanPolicy::isRequired($this->settings)),
			'qtyScale' => ($currentWorkspace === null ? 0 : QtyScale::current($this->settings)),
			'locationAclEnabled' => $currentWorkspace !== null && $this->settings->get(
				LocationAclService::KEY_ENABLED,
				'0',
			) === '1',
			'timezone' => $this->config->getUserValue($uid, 'core', 'timezone', $this->config->getSystemValueString('default_timezone', 'UTC')) ?: 'UTC',
			'roleLabel' => match ($currentWorkspace['role'] ?? '') {
				'owner' => $l->t('Owner'), 'admin' => $l->t('Admin'), 'editor' => $l->t('Write'), 'viewer' => $l->t('Read'), default => $l->t('Workspaces'),
			},
		];

		if ($pageId === 'settings') { $params['settingsSection'] = $settingsSection; }

		$template = match ($pageId) {
			'item-detail' => 'item-detail',
			'location-detail' => 'location-detail',
			'stocktake-new' => 'stocktake',
			default => $pageId,
		};
		$response = new TemplateResponse(Application::APP_ID, $template, $params);
		$response->renderAs(TemplateResponse::RENDER_AS_USER);
		return $response;
	}

	/**
	 * Deterministic settings-section URL fallback for PHPUnit contexts.
	 *
	 * This mirrors the known app route structure:
	 * - htaccess front controller on:  {webroot}/index.php/apps/{appId}/settings/{section}
	 * - front controller ignored:     {webroot}/apps/{appId}/settings/{section}
	 */
	private function settingsSectionFallbackUrl(string $section): string
	{
		$webroot = rtrim((string)\OC::$WEBROOT, '/');
		$frontControllerIgnored = $this->config->getSystemValueBool('htaccess.IgnoreFrontController', false)
			|| getenv('front_controller_active') === 'true';
		$appsPart = $frontControllerIgnored ? '/apps/' : '/index.php/apps/';
		return $webroot . $appsPart . Application::APP_ID . '/settings/' . $section;
	}
}
