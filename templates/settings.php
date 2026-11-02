<?php
declare(strict_types=1);
/** @var array $_ */
/** @var \OCP\IL10N $l */
require __DIR__ . '/common/page-start.php';

$ivSettingsSection = (string)($_['settingsSection'] ?? '');
if ($ivSettingsSection !== '' && !empty($_['isWorkspaceAdmin'])) {
	require __DIR__ . '/parts/settings-nav.php';
}
?>
<div id="iv-page-root" class="iv-page-root" aria-busy="true">
	<p class="iv-loading"><?php p($l->t('Loading…')); ?></p>
</div>
<?php
require __DIR__ . '/common/page-end.php';
