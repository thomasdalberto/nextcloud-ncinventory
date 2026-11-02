<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;

/**
 * Bachus UX simplify — dead-end recovery, honest reverse, progressive chrome,
 * stocktake autosave / blind count.
 */
final class BachusSimplicityUxContractTest extends TestCase
{
	private string $js;
	private string $css;
	private string $pageController;

	protected function setUp(): void
	{
		$root = dirname(__DIR__, 3);
		$this->js = (string)file_get_contents($root . '/js/app.js');
		$this->css = (string)file_get_contents($root . '/css/app.css');
		$this->pageController = (string)file_get_contents($root . '/lib/Controller/PageController.php');
	}

	public function testLoadFailuresOfferRetryEmptyState(): void
	{
		self::assertStringContainsString('function loadErrorPanel', $this->js);
		self::assertStringContainsString("tr('Try again')", $this->js);
		self::assertStringContainsString("tr('Could not load this page')", $this->js);
		self::assertGreaterThanOrEqual(
			6,
			substr_count($this->js, 'loadErrorPanel('),
			'list/detail/settings/stocktake must use retry panels',
		);
	}

	public function testItemsClearsAriaBusyAfterLoad(): void
	{
		self::assertMatchesRegularExpression(
			'/function renderItems[\s\S]*?setBusy\(mount, false\)/',
			$this->js,
		);
	}

	public function testAdjustReverseShowsFixedDeltaNotEditableQtyLie(): void
	{
		self::assertStringContainsString('isDeltaReverse', $this->js);
		self::assertStringContainsString('data-iv-delta-reverse', $this->js);
		self::assertStringContainsString(
			'This reverses the earlier adjustment by {qty}',
			$this->js,
		);
	}

	public function testLotFieldsAreProgressiveAndReasonNoteAdjustOnly(): void
	{
		self::assertStringContainsString('data-iv-lot-fields', $this->js);
		self::assertStringContainsString('syncLotVisibility', $this->js);
		self::assertStringContainsString("tr('Reason note (optional)')", $this->js);
		// Issue/receive/transfer must not push a reason note field (adjust-only).
		self::assertMatchesRegularExpression(
			'/if \(opts\.adjust\) \{\s*fields\.push\(field\(tr\(\'Reason note \(optional\)\'\)/',
			$this->js,
		);
	}

	public function testVarianceUsesDisclosureAndSettingsAreMultipage(): void
	{
		self::assertStringContainsString('function disclosureSection', $this->js);
		self::assertStringContainsString('iv-variance-disclosure', $this->js);
		self::assertStringContainsString('iv-disclosure__summary', $this->css);
		self::assertMatchesRegularExpression(
			'/\.iv-disclosure__summary[\s\S]*min-height:\s*var\(--iv-touch/',
			$this->css,
		);
		// Design-system: settings are one-topic pages — not collapsed disclosures.
		self::assertStringContainsString('iv-settings-page', $this->js);
		self::assertStringContainsString("ctx.settingsSection", $this->js);
	}

	public function testStocktakeAutosavesAndSupportsBlindColumns(): void
	{
		self::assertStringContainsString('data-iv-autosave', $this->js);
		self::assertStringContainsString('Blind count — hide system quantities', $this->js);
		self::assertStringContainsString('renderCountTable', $this->js);
		self::assertStringNotContainsString(
			"btn(tr('Save'), {\n\t\t\t\t\t\tonclick: function () {\n\t\t\t\t\t\t\tif (input.value.trim() === '')",
			$this->js,
		);
	}

	public function testLiveSearchAndDashboardOneTapActions(): void
	{
		self::assertStringContainsString('function bindLiveSearch', $this->js);
		self::assertGreaterThanOrEqual(2, substr_count($this->js, 'bindLiveSearch(q,'));
		self::assertStringContainsString('data-iv-low-stock-actions', $this->js);
		self::assertStringContainsString('data-iv-neg-actions', $this->js);
		self::assertStringContainsString(
			'Filters update as you choose.',
			$this->js,
		);
	}

	public function testConfirmOnlyBookingAndResponsiveTables(): void
	{
		self::assertStringContainsString('data-iv-quick-confirm', $this->js);
		self::assertStringContainsString('Looking good — confirm this booking.', $this->js);
		self::assertStringContainsString('data-iv-quick-change', $this->js);
		self::assertStringContainsString('Change item or location', $this->js);
		self::assertStringContainsString('forceFullForm', $this->js);
		self::assertStringNotContainsString(
			'Close and open Receive / Issue from the toolbar',
			$this->js,
		);
		self::assertStringContainsString('Set to zero', $this->js);
		self::assertStringContainsString('Set each line to zero to clear it', $this->js);
		self::assertStringNotContainsString('Adjust or receive to clear these', $this->js);
		self::assertStringContainsString("className: 'table iv-table iv-table--responsive'", $this->js);
		self::assertStringContainsString('Stocktake ready to count.', $this->js);
		self::assertStringContainsString("wrap.id = 'iv-app-admins'", $this->js);
		self::assertStringContainsString("licenseSection.id = 'iv-license'", $this->js);
		self::assertStringContainsString('iv-actions-more', $this->js);
		self::assertMatchesRegularExpression('/\bfunction actionsMoreMenu\s*\(/', $this->js);
		self::assertMatchesRegularExpression(
			'/#content\.app-inventorycheck #app-content\.iv-app \.iv-actions-more__body[\s\S]{0,120}?position:\s*absolute\s*!important/s',
			$this->css,
			'More menu body must be a popover — inline expansion breaks the header row'
		);
		self::assertMatchesRegularExpression(
			'/#content\.app-inventorycheck #app-content\.iv-app \.iv-actions-more__body[\s\S]{0,200}?flex-direction:\s*column\s*!important/s',
			$this->css,
		);
		self::assertStringContainsString('resolveCodePaste', $this->js);
	}

	public function testEmptyBalancesOfferReceiveCta(): void
	{
		self::assertStringContainsString('Receive stock at a location to start tracking this item.', $this->js);
		self::assertStringContainsString('Receive stock here, or transfer from another location.', $this->js);
		self::assertStringContainsString('data-iv-perloc-actions', $this->js);
	}

	public function testItemCreateCsvPairingAclSimplified(): void
	{
		self::assertStringContainsString('More item options', $this->js);
		self::assertStringContainsString('iv-item-more', $this->js);
		self::assertStringContainsString('function createLocalOptionPicker', $this->js);
		self::assertStringContainsString('Add locations as chips', $this->js);
		self::assertStringContainsString('data-iv-acl-clear', $this->js);
		self::assertStringContainsString('Clear grants', $this->js);
		self::assertStringNotContainsString('Native scanner app: coming soon', $this->js);
		self::assertStringNotContainsString('Hold Ctrl (or Cmd on Mac)', $this->js);
		self::assertStringContainsString('iv-pair-code-panel', $this->js);
		self::assertStringContainsString('We check your file automatically', $this->js);
		self::assertStringContainsString('iv-import-columns', $this->js);
		self::assertStringContainsString('scheduleDryRun', $this->js);
	}

	public function testPass3ScopedSettingsSavesCleanCloseAndAutoFilters(): void
	{
		// Design-system: each settings page saves only its own topic.
		self::assertStringContainsString("tr('Save access')", $this->js);
		self::assertStringContainsString("tr('Save office settings')", $this->js);
		self::assertStringContainsString("tr('Save notification settings')", $this->js);
		self::assertStringContainsString('function withSaveBusy', $this->js);
		self::assertStringNotContainsString('Save these settings', $this->js);
		self::assertStringNotContainsString('function saveCoreSettings', $this->js);
		self::assertStringContainsString('function closeStocktake', $this->js);
		self::assertStringContainsString('tryAutoApplyFilters', $this->js);
		self::assertStringContainsString('Filters update as you choose.', $this->js);
		self::assertStringContainsString('iv-movements-results', $this->js);
		self::assertStringContainsString('_reuseShell', $this->js);
		self::assertStringContainsString('iv-btn--sr-fallback', $this->js);
		self::assertStringContainsString('data-iv-access-allowlists', $this->js);
		self::assertStringContainsString('Or browse items', $this->js);
	}

	public function testPass5AutoStartConfirmConsentAndLessChrome(): void
	{
		self::assertStringContainsString('stocktakeAutoStarting', $this->js);
		self::assertStringContainsString('Starting count…', $this->js);
		self::assertStringContainsString('Accept conflicts and close', $this->js);
		self::assertStringContainsString('Close and leave uncounted', $this->js);
		self::assertStringContainsString('Confirming means you reviewed the conflicts', $this->js);
		self::assertStringNotContainsString('iv-ack-conflicts', $this->js);
		self::assertStringNotContainsString('iv-abandon-uncounted', $this->js);
		self::assertStringNotContainsString('function syncFilterActive', $this->js);
		self::assertStringContainsString('Showing transfer group', $this->js);
		self::assertStringContainsString('Export DATEV-style CSV', $this->js);
		self::assertStringContainsString('perLoc.data && perLoc.data.length > 0', $this->js);
		self::assertStringContainsString("disclosureSection(tr('Mobile seats')", $this->js);
		self::assertStringContainsString("disclosureSection(tr('Scanner device slots')", $this->js);
	}

	public function testPass6TransferConfirmStocktakeImportFavourites(): void
	{
		self::assertStringContainsString('(!opts.transfer || !!toSelect.value)', $this->js);
		self::assertStringContainsString('iv-stocktake-more', $this->js);
		self::assertStringContainsString('More stocktake options', $this->js);
		self::assertStringContainsString('iv-stocktake-options', $this->js);
		self::assertStringContainsString('Counting options', $this->js);
		self::assertStringContainsString('Import will skip rows with errors and keep the good ones.', $this->js);
		self::assertStringContainsString('Nothing to import — fix the errors first.', $this->js);
		self::assertStringNotContainsString('Skip rows with errors instead of stopping', $this->js);
		self::assertStringContainsString('Add favourite', $this->js);
		self::assertStringContainsString('Remove favourite', $this->js);
		self::assertStringNotContainsString('★ Remove from favourites', $this->js);
		self::assertStringContainsString('primary: !ctx.canWrite', $this->js);
		self::assertStringContainsString('locs.length === 1', $this->js);
		self::assertStringContainsString('aria-pressed', $this->js);
		self::assertStringContainsString('function createLocationChooser', $this->js);
		self::assertStringContainsString('function renderStocktakeNewPage', $this->js);
		self::assertStringContainsString('stocktakeNew', $this->js);
		self::assertStringContainsString('Where are you counting?', $this->js);
		self::assertStringContainsString('data-iv-loc-chooser', $this->js);
		self::assertStringContainsString('iv-stocktake-new', $this->js);
		self::assertStringNotContainsString('fillSelect(locSelect, locs', $this->js);
		self::assertStringContainsString("active=1&q=' + encodeURIComponent(term)", $this->js);
	}

	public function testAristotelesNavHintsAndAccessQuickstart(): void
	{
		self::assertStringContainsString('function accessQuickstartCard', $this->js);
		self::assertStringContainsString('function wireDismissibleHint', $this->js);
		self::assertStringContainsString('settings_access_quickstart_v1', $this->js);
		self::assertStringContainsString('This list controls the door, not the stock.', $this->js);
		self::assertStringContainsString('Add allowlist entries before turning restriction on', $this->js);
		self::assertStringContainsString('Otherwise you can lock yourself out until a system admin re-opens the app.', $this->js);
		self::assertStringContainsString('iv:hint:', $this->js);
		self::assertStringContainsString('.iv-empty--quickstart', $this->css);
		self::assertStringContainsString('.iv-quickstart', $this->css);
		self::assertStringContainsString('.iv-hint-dismiss', $this->css);
		self::assertStringContainsString('.iv-tip__title', $this->css);
		self::assertStringNotContainsString('1. Choose the audience', $this->js);
		self::assertStringNotContainsString('3. Delegate app-admin powers carefully', $this->js);
		self::assertStringNotContainsString("tr('Quick start')", $this->js);
		self::assertStringContainsString('function settingsSrTitle', $this->js);
		self::assertStringContainsString('function settingsSwitchField', $this->js);
		self::assertStringContainsString('function settingsFormActions', $this->js);
		self::assertStringContainsString('function settingsPageSection', $this->js);
		self::assertStringContainsString('iv-switch-field', $this->js);
		self::assertStringContainsString('.iv-form-actions', $this->css);
		self::assertStringContainsString('iv-sr-only', $this->js);
		self::assertStringContainsString('People who may change settings (in addition to system admins).', $this->js);
	}

	public function testStocktakeDutyCheckStyleHowTo(): void
	{
		self::assertMatchesRegularExpression('/\bfunction stocktakeHowToCard\s*\(/', $this->js);
		self::assertMatchesRegularExpression('/\bfunction howToCard\s*\(/', $this->js);
		self::assertMatchesRegularExpression('/\bfunction pageHowToCard\s*\(/', $this->js);
		self::assertStringContainsString('stocktake_create_howto_v1', $this->js);
		self::assertStringContainsString('stocktake_list_howto_v1', $this->js);
		self::assertStringContainsString('stocktake_count_howto_v1', $this->js);
		self::assertStringContainsString('stocktake_detail_howto_v1', $this->js);
		self::assertStringContainsString('item_detail_howto_v1', $this->js);
		self::assertStringContainsString('location_detail_howto_v1', $this->js);
		self::assertStringContainsString('settings_office_howto_v1', $this->js);
		self::assertStringContainsString('settings_license_howto_v1', $this->js);
		self::assertMatchesRegularExpression('/\bfunction withSettingsHowTo\s*\(/', $this->js);
		self::assertStringContainsString("pageHowToCard(ctx, 'item-detail')", $this->js);
		self::assertStringContainsString("pageHowToCard(ctx, 'location-detail')", $this->js);
		self::assertStringContainsString("withSettingsHowTo(ctx, 'office'", $this->js);
		self::assertStringContainsString('text: tr(\'Hide tips\')', $this->js);
		self::assertStringContainsString('How this item page works', $this->js);
		self::assertStringContainsString('Stock policy', $this->js);
		self::assertStringContainsString('Balances, receive or issue, and print the label', $this->pageController);
		self::assertStringContainsString('How a stocktake works', $this->js);
		self::assertStringContainsString('How booking works', $this->js);
		self::assertStringContainsString('How items work', $this->js);
		self::assertStringContainsString('How locations work', $this->js);
		self::assertStringContainsString('How movements work', $this->js);
		self::assertStringContainsString('How to count', $this->js);
		self::assertStringContainsString('1. Choose where you are counting', $this->js);
		self::assertStringContainsString('3. Close when you are finished', $this->js);
		self::assertStringContainsString('iv-stocktake-howto', $this->js);
		self::assertStringContainsString('iv-howto', $this->js);
		self::assertStringContainsString("variant: 'create'", $this->js);
		self::assertStringContainsString("pageHowToCard(ctx, 'dashboard')", $this->js);
		self::assertStringContainsString("pageHowToCard(ctx, 'items')", $this->js);
		self::assertStringContainsString("pageHowToCard(ctx, 'locations')", $this->js);
		self::assertStringContainsString("pageHowToCard(ctx, 'movements')", $this->js);
		self::assertStringContainsString("Tap a location below to start counting — favourites appear first", $this->pageController);
		self::assertStringContainsString('Receive, issue, and transfer stock — low stock and recent bookings below', $this->pageController);
		self::assertStringContainsString('className: \'iv-card iv-howto\'', $this->js);
		self::assertStringNotContainsString('iv-card iv-empty iv-empty--quickstart iv-howto', $this->js);
		self::assertStringContainsString('Never use .iv-empty here', $this->js);
		self::assertMatchesRegularExpression(
			'/\.iv-howto[\s\S]{0,120}?display:\s*flex/s',
			$this->css,
		);
		self::assertMatchesRegularExpression(
			'/\.iv-howto[\s\S]{0,200}?flex-direction:\s*column/s',
			$this->css,
		);
		self::assertDoesNotMatchRegularExpression(
			'/\.iv-howto[^{]*\{[^}]*grid-template-columns:\s*auto/s',
			$this->css,
		);
		self::assertMatchesRegularExpression(
			'/@media \(min-width: 520px\)[\s\S]*?grid-template-columns:\s*repeat\(2, 1fr\)/s',
			$this->css,
		);
		self::assertMatchesRegularExpression(
			'/@media \(min-width: 900px\)[\s\S]*?grid-template-columns:\s*repeat\(3, 1fr\)/s',
			$this->css,
		);
		self::assertStringContainsString('container-type: inline-size', $this->css);
		self::assertStringContainsString('container-name: iv-howto', $this->css);
		self::assertMatchesRegularExpression(
			'/@container iv-howto \(max-width: 519.98px\)[\s\S]*?grid-template-columns:\s*1fr/s',
			$this->css,
		);
		self::assertMatchesRegularExpression(
			'/\.iv-quickstart__cta\s*\{[^}]*justify-content:\s*center/s',
			$this->css,
		);
	}

	public function testPass7OneClickStocktakeAndQuieterChrome(): void
	{
		self::assertStringContainsString('function createAndStartStocktake', $this->js);
		self::assertStringContainsString('skip the chooser entirely', $this->js);
		self::assertStringContainsString('Add a location first, then start a stocktake.', $this->js);
		self::assertStringContainsString('function createLocationChooser', $this->js);
		self::assertStringContainsString('function renderStocktakeNewPage', $this->js);
		self::assertStringContainsString('stocktakeNew', $this->js);
		self::assertStringContainsString('iv-stocktake-new', $this->js);
		self::assertStringNotContainsString('iv-dialog--stocktake-chooser', $this->js);
		self::assertStringContainsString('Where are you counting?', $this->js);
		self::assertStringContainsString('labelSrOnly: true', $this->js);
		self::assertStringContainsString('Office access required', $this->js);
		self::assertStringContainsString('Only office users and app admins can start a stocktake.', $this->js);
		self::assertStringContainsString('if (!locId || locId < 1 || locId !== Math.floor(locId))', $this->js);
		self::assertStringContainsString('Arm busy before the async create path', $this->js);
		self::assertMatchesRegularExpression(
			'/setBusy\(true\);\s*opts\.onPick\(loc\);/',
			$this->js,
		);
		self::assertStringContainsString('.iv-loc-chooser__item', $this->css);
		self::assertStringContainsString('.iv-stocktake-new', $this->css);
		self::assertStringContainsString("className: 'iv-sr-only', text: tr('Filter movements')", $this->js);
		self::assertStringNotContainsString('QR and Code 128 encode the scan code', $this->js);
		self::assertStringNotContainsString(
			'By default every logged-in user can open InventoryCheck',
			$this->js,
		);
		self::assertStringContainsString('Workspace owners manage editing permissions in Workspaces.', $this->js);
		self::assertStringContainsString('Download SVG', $this->js);
	}

	public function testAristotelesRaceAndSecurityHardening(): void
	{
		self::assertStringContainsString('DIALOG_INERT_IDS', $this->js);
		self::assertStringContainsString('iv-page-actions', $this->js);
		self::assertStringContainsString('return { close: close, overlay: overlay, dialogEl: dialogEl, bodyEl: bodyEl }', $this->js);
		self::assertStringContainsString('dlg.close()', $this->js);
		self::assertStringNotContainsString(
			"querySelector('.iv-dialog-overlay .iv-dialog__close')",
			$this->js,
		);
		self::assertStringContainsString("if (nextPrefill.mode === 'delta')", $this->js);
		self::assertStringContainsString('reuseShell && resultsHost', $this->js);
		self::assertStringContainsString('_ivPendingSaves', $this->js);
		self::assertStringContainsString('Still saving counts', $this->js);
		self::assertStringContainsString('function stocktakeUncounted', $this->js);
		self::assertStringContainsString('campaign.linesUncounted != null', $this->js);
		self::assertStringNotContainsString(
			'campaign.lines && campaign.lines.length)',
			$this->js,
		);
		self::assertStringContainsString("subjectId !== ''", $this->js);
		self::assertStringContainsString('dryRunSeq', $this->js);
		self::assertStringContainsString('lastDry.csv === text', $this->js);
		self::assertStringContainsString('movementDialogOpening', $this->js);
		self::assertStringContainsString('stocktakeCreating', $this->js);
		self::assertStringContainsString('stocktakeOpenBusy', $this->js);
		self::assertStringContainsString('r.itemName || r.sku', $this->js);
	}

	public function testPass9InventurSpeedAndQuieterChrome(): void
	{
		self::assertStringContainsString('Count this page as system', $this->js);
		self::assertStringContainsString('function countRemainingAsSystem', $this->js);
		self::assertStringContainsString('data-iv-balance-actions', $this->js);
		self::assertStringContainsString('iv-label-preview-disclosure', $this->js);
		self::assertStringContainsString("className: 'iv-sr-only', text: tr('OK')", $this->js);
		self::assertStringNotContainsString('iv-items-filter-active', $this->js);
		self::assertStringNotContainsString('iv-loc-filter-active', $this->js);
		self::assertStringContainsString("ev.key !== 'Enter'", $this->js);
	}

	public function testPass10QtyOnlyBalanceBooking(): void
	{
		self::assertStringContainsString('var mastersKnown =', $this->js);
		self::assertStringContainsString('Enter the quantity, then confirm.', $this->js);
		self::assertStringContainsString('conflictCount > 0', $this->js);
		self::assertStringContainsString('iv-filter-panel__intro iv-sr-only', $this->js);
		self::assertStringContainsString('clearBtn.hidden', $this->js);
		self::assertStringContainsString('iv-name-with-status', $this->js);
		self::assertStringContainsString("tr('Inactive')", $this->js);
		self::assertStringContainsString('Count this page as system', $this->js);
	}
}
