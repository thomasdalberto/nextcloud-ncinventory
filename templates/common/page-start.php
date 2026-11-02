<?php

declare(strict_types=1);

/**
 * Common page opening — ArbeitszeitCheck shell parity.
 *
 * @var array $_
 * @var \OCP\IL10N $l
 */

use OCA\InventoryCheck\Support\IconCatalog;

$pageId = (string)$_['pageId'];
$pageTitle = (string)$_['pageTitle'];
$pageHint = (string)$_['pageHint'];
$entityId = $_['entityId'] ?? null;
$currentUserId = (string)$_['currentUserId'];
$isWorkspaceAdmin = !empty($_['isWorkspaceAdmin']);
$isSystemAdmin = !empty($_['isSystemAdmin']);
$canWrite = !empty($_['canWrite']);
$urlsJson = (string)$_['urlsJson'];
$allowNegativeStock = !empty($_['allowNegativeStock']);
$locationReorderHintEnabled = !empty($_['locationReorderHintEnabled']);
$requireAdjustReason = !empty($_['requireAdjustReason']);
$requireLocationScan = !empty($_['requireLocationScan']);
$qtyScale = (int)($_['qtyScale'] ?? 0);
$locationAclEnabled = !empty($_['locationAclEnabled']);
$productLookupEnabled = !empty($_['productLookupEnabled']);
$productLookupAutofire = !empty($_['productLookupAutofire']);
$timezone = (string)($_['timezone'] ?? 'UTC');
$roleLabel = (string)($_['roleLabel'] ?? ($isWorkspaceAdmin ? $l->t('Administrator') : ($canWrite ? $l->t('Office') : $l->t('Field'))));
$htmlLang = str_replace('_', '-', $l->getLanguageCode());
$settingsSection = (string)($_['settingsSection'] ?? '');

$pageIcons = [
	'dashboard' => 'layout-grid',
	'items' => 'list-checks',
	'item-detail' => 'list-checks',
	'locations' => 'map-pin',
	'location-detail' => 'map-pin',
	'movements' => 'history',
	'stocktake' => 'clipboard-list',
	'stocktake-new' => 'clipboard-list',
	'settings' => 'settings',
	'access-denied' => 'shield',
];
$headerIcon = $pageIcons[$pageId] ?? 'inbox';

$decodedUrls = json_decode($urlsJson, true);
$navUrls = is_array($decodedUrls) ? ($decodedUrls['pages'] ?? []) : [];
$homeUrl = (string)($navUrls['dashboard'] ?? '#');
$settingsHomeUrl = (string)($navUrls['settings'] ?? '#');
$stocktakeListUrl = (string)($navUrls['stocktake'] ?? '#');

require __DIR__ . '/navigation.php';
?>
<div id="app-content" class="iv-app iv-app--<?php p($pageId); ?>"
	lang="<?php p($htmlLang); ?>"
	data-iv-workspace-id="<?php p((string)($_['workspace']['id'] ?? '')); ?>"
	data-iv-workspace-role="<?php p((string)($_['workspace']['role'] ?? '')); ?>"
	data-iv-page="<?php p($pageId); ?>"
	<?php if ($entityId !== null): ?>data-iv-entity-id="<?php p((string)$entityId); ?>"<?php endif; ?>
	<?php if ($settingsSection !== ''): ?>data-iv-settings-section="<?php p($settingsSection); ?>"<?php endif; ?>
	data-iv-current-user="<?php p($currentUserId); ?>"
	data-iv-is-workspace-admin="<?php p($isWorkspaceAdmin ? '1' : '0'); ?>"
	data-iv-is-system-admin="<?php p($isSystemAdmin ? '1' : '0'); ?>"
	data-iv-can-write="<?php p($canWrite ? '1' : '0'); ?>"
	data-iv-allow-negative="<?php p($allowNegativeStock ? '1' : '0'); ?>"
	data-iv-location-reorder-hint="<?php p($locationReorderHintEnabled ? '1' : '0'); ?>"
	data-iv-require-adjust-reason="<?php p($requireAdjustReason ? '1' : '0'); ?>"
	data-iv-require-location-scan="<?php p($requireLocationScan ? '1' : '0'); ?>"
	data-iv-qty-scale="<?php p((string)$qtyScale); ?>"
	data-iv-location-acl="<?php p($locationAclEnabled ? '1' : '0'); ?>"
	data-iv-product-lookup="<?php p($productLookupEnabled ? '1' : '0'); ?>"
	data-iv-product-lookup-autofire="<?php p($productLookupAutofire ? '1' : '0'); ?>"
	data-iv-timezone="<?php p($timezone); ?>"
	data-iv-urls="<?php p($urlsJson); ?>">
	<a class="iv-skip-link" href="#iv-main-content"><?php p($l->t('Skip to main content')); ?></a>
	<div id="iv-live-region" class="iv-sr-only" role="status" aria-live="polite" aria-atomic="true"></div>
	<div id="iv-alert-region" class="iv-sr-only" role="alert" aria-live="assertive" aria-atomic="true"></div>
	<div id="iv-toast-region" class="iv-toast-region" role="region" aria-label="<?php p($l->t('Notifications')); ?>"></div>
	<div id="app-content-wrapper" class="iv-shell">
		<header class="iv-page-header" aria-labelledby="iv-page-title">
			<button type="button"
				class="ivc-nav-toggle"
				id="ivc-nav-toggle"
				data-ivc-nav-toggle
				aria-controls="app-navigation"
				aria-expanded="false"
				aria-label="<?php p($l->t('Open navigation menu')); ?>"
				data-aria-label-open="<?php p($l->t('Open navigation menu')); ?>"
				data-aria-label-close="<?php p($l->t('Close navigation menu')); ?>">
				<span class="ivc-nav-toggle__icon" aria-hidden="true">
					<?php print_unescaped(IconCatalog::render('menu', 'ivc-nav-toggle__icon-svg')); ?>
				</span>
				<span class="ivc-nav-toggle__label"><?php p($l->t('Menu')); ?></span>
			</button>
			<nav class="iv-breadcrumb" aria-label="<?php p($l->t('Breadcrumb')); ?>">
				<ol class="iv-breadcrumb__list">
					<li class="iv-breadcrumb__item">
						<a class="iv-breadcrumb__link" href="<?php p($homeUrl); ?>"><?php p($l->t('InventoryCheck')); ?></a>
					</li>
					<?php if ($pageId === 'settings' && $settingsSection !== ''): ?>
						<li class="iv-breadcrumb__item">
							<a class="iv-breadcrumb__link" href="<?php p($settingsHomeUrl); ?>"><?php p($l->t('Settings')); ?></a>
						</li>
						<li class="iv-breadcrumb__item iv-breadcrumb__item--current" aria-current="page">
							<span class="iv-breadcrumb__current"><?php p($pageTitle); ?></span>
						</li>
					<?php elseif ($pageId === 'stocktake-new'): ?>
						<li class="iv-breadcrumb__item">
							<a class="iv-breadcrumb__link" href="<?php p($stocktakeListUrl); ?>"><?php p($l->t('Stocktake')); ?></a>
						</li>
						<li class="iv-breadcrumb__item iv-breadcrumb__item--current" aria-current="page">
							<span class="iv-breadcrumb__current"><?php p($pageTitle); ?></span>
						</li>
					<?php else: ?>
						<li class="iv-breadcrumb__item iv-breadcrumb__item--current" aria-current="page">
							<span class="iv-breadcrumb__current"><?php p($pageTitle); ?></span>
						</li>
					<?php endif; ?>
				</ol>
			</nav>
			<div class="iv-page-header__main">
				<div class="iv-page-header__icon" aria-hidden="true">
					<?php print_unescaped(IconCatalog::render($headerIcon, 'iv-page-header__icon-svg')); ?>
				</div>
				<div class="iv-page-header__text">
					<h1 id="iv-page-title"><?php p($pageTitle); ?></h1>
					<?php if ($pageHint !== ''): ?>
						<p class="iv-page-header__lead"><?php p($pageHint); ?></p>
					<?php endif; ?>
				</div>
				<div id="iv-page-actions" class="iv-page-header__actions" aria-live="polite"></div>
			</div>
			<div class="iv-scope-strip" aria-label="<?php p($l->t('Active session context')); ?>">
				<?php if (!empty($_['workspace'])): ?>
				<span class="iv-scope-strip__label"><?php p($l->t('Workspace')); ?></span>
				<span class="iv-scope-strip__value"><?php p($_['workspace']['name']); ?></span>
				<span class="iv-scope-strip__sep" aria-hidden="true">·</span>
				<?php endif; ?>
				<span class="iv-scope-strip__label"><?php p($l->t('Role')); ?></span>
				<span class="iv-badge iv-badge--neutral iv-scope-strip__badge"><?php p($roleLabel); ?></span>
				<span class="iv-scope-strip__sep" aria-hidden="true">·</span>
				<span class="iv-scope-strip__label"><?php p($l->t('Timezone')); ?></span>
				<span class="iv-scope-strip__value"><?php p($timezone); ?></span>
			</div>
		</header>
		<main id="iv-main-content" class="iv-main" tabindex="-1" aria-labelledby="iv-page-title">
