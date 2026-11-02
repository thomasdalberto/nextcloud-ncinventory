<?php

declare(strict_types=1);

use OCA\InventoryCheck\Support\IconCatalog;
use OCP\Util;

/**
 * Sidebar navigation — ArbeitszeitCheck shell parity + settings sub-pages.
 *
 * Each link includes a visible hint line (DutyCheck / design-system pattern)
 * so newcomers understand what the section is for without hovering titles.
 *
 * @var \OCP\IL10N $l
 * @var string $pageId
 * @var bool $isWorkspaceAdmin
 * @var bool $canWrite
 * @var string $urlsJson
 * @var string $roleLabel
 * @var array $_
 */

Util::addScript('inventorycheck', 'common/navigation');
Util::addScript('inventorycheck', 'common/mobile-nav');

$decodedUrls = json_decode($urlsJson, true);
$navUrls = is_array($decodedUrls) ? ($decodedUrls['pages'] ?? []) : [];
$settingsSectionUrls = is_array($decodedUrls) ? ($decodedUrls['settingsSections'] ?? []) : [];
$settingsSectionLabels = (array)($_['settingsSectionLabels'] ?? []);
$settingsSection = (string)($_['settingsSection'] ?? '');

$ivNavIcon = static function (string $name): string {
	return IconCatalog::render($name, 'iv-nav__icon-svg');
};

/** Visible name + hint under every primary nav item (WCAG: not title-only). */
$ivNavLabel = static function (string $name, string $hint): void {
	?>
	<span class="iv-nav__label">
		<span class="iv-nav__name"><?php p($name); ?></span>
		<?php if ($hint !== ''): ?>
			<span class="iv-nav__hint"><?php p($hint); ?></span>
		<?php endif; ?>
	</span>
	<?php
};

$activeNavId = match ($pageId) {
	'item-detail' => 'items',
	'location-detail' => 'locations',
	'stocktake-new' => 'stocktake',
	default => $pageId,
};

$isDashboard = $activeNavId === 'dashboard';
$isItems = $activeNavId === 'items';
$isLocations = $activeNavId === 'locations';
$isMovements = $activeNavId === 'movements';
$isStocktake = $activeNavId === 'stocktake';
$isSettings = $activeNavId === 'settings';
?>
<div id="inventorycheck-app" class="inventorycheck-app">
	<a href="#app-navigation" class="skip-link iv-skip-link--nav"><?php p($l->t('Skip to app navigation')); ?></a>

	<div id="app-navigation" class="iv-nav ivc-nav" role="navigation" aria-label="<?php p($l->t('Main navigation')); ?>">
		<div class="sidebar-header">
			<div class="app-brand">
				<div class="app-icon" aria-hidden="true">
					<span class="iv-nav__icon"><?php print_unescaped($ivNavIcon('inbox')); ?></span>
				</div>
				<div class="app-info">
					<h3><?php p($l->t('InventoryCheck')); ?></h3>
					<p class="app-brand__subtitle"><?php p($l->t('Stock & movements')); ?></p>
					<p class="app-brand__role"><?php p($roleLabel); ?></p>
				</div>
			</div>
		</div>

		<div class="iv-workspace-nav">
			<a class="iv-workspace-link" href="<?php p($navUrls['workspaces'] ?? '#'); ?>"<?php if ($pageId === 'workspaces'): ?> aria-current="page"<?php endif; ?>>
				<?php p($l->t('Workspaces')); ?>
				<?php if (!empty($_['workspace'])): ?><span class="iv-nav__hint"><?php p($_['workspace']['name']); ?></span><?php endif; ?>
			</a>
		</div>

		<ul class="nav-menu">
			<li class="<?php p($isDashboard ? 'active' : ''); ?>">
				<a <?php if ($isDashboard): ?>aria-current="page"<?php endif; ?> href="<?php p((string)($navUrls['dashboard'] ?? '#')); ?>"
					title="<?php p($l->t('Dashboard: Low stock and recent bookings')); ?>"
					aria-label="<?php p($l->t('Go to dashboard')); ?>">
					<span class="iv-nav__icon" aria-hidden="true"><?php print_unescaped($ivNavIcon('layout-grid')); ?></span>
					<?php $ivNavLabel($l->t('Dashboard'), $l->t('Low stock and recent bookings')); ?>
				</a>
			</li>
			<li class="<?php p($isItems ? 'active' : ''); ?>">
				<a <?php if ($isItems): ?>aria-current="page"<?php endif; ?> href="<?php p((string)($navUrls['items'] ?? '#')); ?>"
					title="<?php p($l->t('Items: SKU and scan codes')); ?>"
					aria-label="<?php p($l->t('Go to items')); ?>">
					<span class="iv-nav__icon" aria-hidden="true"><?php print_unescaped($ivNavIcon('list-checks')); ?></span>
					<?php $ivNavLabel($l->t('Items'), $l->t('SKU and scan codes')); ?>
				</a>
			</li>
			<li class="<?php p($isLocations ? 'active' : ''); ?>">
				<a <?php if ($isLocations): ?>aria-current="page"<?php endif; ?> href="<?php p((string)($navUrls['locations'] ?? '#')); ?>"
					title="<?php p($l->t('Locations: Warehouse, van, site')); ?>"
					aria-label="<?php p($l->t('Go to locations')); ?>">
					<span class="iv-nav__icon" aria-hidden="true"><?php print_unescaped($ivNavIcon('map-pin')); ?></span>
					<?php $ivNavLabel($l->t('Locations'), $l->t('Warehouse, van, site')); ?>
				</a>
			</li>
			<li class="<?php p($isMovements ? 'active' : ''); ?>">
				<a <?php if ($isMovements): ?>aria-current="page"<?php endif; ?> href="<?php p((string)($navUrls['movements'] ?? '#')); ?>"
					title="<?php p($l->t('Movements: Booking history')); ?>"
					aria-label="<?php p($l->t('Go to movements')); ?>">
					<span class="iv-nav__icon" aria-hidden="true"><?php print_unescaped($ivNavIcon('history')); ?></span>
					<?php $ivNavLabel($l->t('Movements'), $l->t('Booking history')); ?>
				</a>
			</li>
			<?php if ($canWrite): ?>
				<li class="<?php p($isStocktake ? 'active' : ''); ?>">
					<a <?php if ($isStocktake): ?>aria-current="page"<?php endif; ?> href="<?php p((string)($navUrls['stocktake'] ?? '#')); ?>"
						title="<?php p($l->t('Stocktake: Cycle counts and Inventur campaigns')); ?>"
						aria-label="<?php p($l->t('Go to stocktake')); ?>">
						<span class="iv-nav__icon" aria-hidden="true"><?php print_unescaped($ivNavIcon('clipboard-list')); ?></span>
						<?php $ivNavLabel($l->t('Stocktake'), $l->t('Cycle counts and Inventur')); ?>
					</a>
				</li>
			<?php endif; ?>
			<?php if ($isWorkspaceAdmin): ?>
				<li class="nav-section-divider" role="separator" aria-hidden="true"></li>
				<li class="nav-item-has-children <?php p($isSettings ? 'is-open' : ''); ?>">
					<button class="nav-parent-toggle" type="button"
						aria-expanded="<?php p($isSettings ? 'true' : 'false'); ?>"
						aria-controls="iv-settings-subnav"
						aria-label="<?php p($l->t('Open settings')); ?>">
						<span class="iv-nav__icon" aria-hidden="true"><?php print_unescaped($ivNavIcon('shield')); ?></span>
						<?php $ivNavLabel($l->t('Workspace settings'), $l->t('Members and stock policies')); ?>
						<span class="nav-parent-chevron" aria-hidden="true"></span>
					</button>
					<ul id="iv-settings-subnav" class="nav-submenu" <?php p($isSettings ? '' : 'hidden'); ?>>
						<?php
						// Prefer labels from the controller; fall back to URL keys so the menu
						// never renders empty when settingsSections are present in urlsJson.
						$ivSettingsNav = $settingsSectionLabels !== []
							? $settingsSectionLabels
							: array_fill_keys(array_keys($settingsSectionUrls), null);
						foreach ($ivSettingsNav as $sid => $slabel):
							$sid = (string)$sid;
							$href = (string)($settingsSectionUrls[$sid] ?? '#');
							if ($href === '' || $href === '#') {
								continue;
							}
							$active = $settingsSection === $sid;
							$labelText = is_string($slabel) && $slabel !== ''
								? $slabel
								: $sid;
							?>
							<li class="<?php p($active ? 'active' : ''); ?>">
								<a href="<?php p($href); ?>" <?php if ($active): ?>aria-current="page"<?php endif; ?>>
									<span><?php p($labelText); ?></span>
								</a>
							</li>
						<?php endforeach; ?>
					</ul>
				</li>
			<?php endif; ?>
		</ul>

		<?php if (!empty($_['isSystemAdmin'])): ?>
			<div class="iv-app-settings-footer">
				<button type="button" class="iv-app-settings-button" data-iv-app-settings aria-haspopup="dialog">
					<span class="iv-nav__icon" aria-hidden="true"><?php print_unescaped($ivNavIcon('settings')); ?></span>
					<span><?php p($l->t('App settings')); ?></span>
				</button>
			</div>
		<?php endif; ?>

	</div>
