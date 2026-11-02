#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Mutation gauntlet: MovementService office gates + BalanceMapper lock order.
 */

require __DIR__ . '/harness.php';

$movement = 'lib/Service/MovementService.php';
$balances = 'lib/Db/BalanceMapper.php';
$rowLocking = 'lib/Db/RowLocking.php';
$itemService = 'lib/Service/ItemService.php';

runMutations(dirname(__DIR__, 2), 'MovementLockingProtocolTest|LocationScanPolicyTest|WaveDLocationScanIntegrationTest|AfIvWalkthroughContractTest|SpecEdgeCasesIntegrationTest|LifecycleAndRaceIntegrationTest|WaveCFeaturesIntegrationTest|WaveAbFeaturesIntegrationTest', [
	[
		'name' => 'receive-office-gate-dropped',
		'file' => $movement,
		'search' => "\$this->access->requireWrite(\$actorUid);\n\t\t\$this->assertLocationAccess(\$actorUid, \$locationId);\n\t\treturn \$this->postSingle(\$actorUid, 'receive', \$itemId, \$locationId, \$qty, \$reason, \$lotCode, \$notifyLowStock);",
		'replace' => "\$this->assertLocationAccess(\$actorUid, \$locationId);\n\t\treturn \$this->postSingle(\$actorUid, 'receive', \$itemId, \$locationId, \$qty, \$reason, \$lotCode, \$notifyLowStock);",
	],
	[
		'name' => 'lock-order-item-reversed',
		'file' => $balances,
		'search' => "\$a['itemId'] <=> \$b['itemId']",
		'replace' => "\$b['itemId'] <=> \$a['itemId']",
	],
	[
		'name' => 'for-update-removed',
		'file' => $balances,
		'search' => 'FOR UPDATE',
		'replace' => '/* no lock */',
	],
	[
		'name' => 'entity-shared-lock-downgraded-to-plain-read',
		'file' => $movement,
		'search' => '$item = $this->items->lockById($itemId, $exclusive);',
		'replace' => '$item = $this->items->findById($itemId);',
	],
	[
		'name' => 'inactive-item-recheck-dropped',
		'file' => $movement,
		'search' => "\t\tif (!\$item->getActive()) {\n\t\t\tthrow new ValidationException('inactive_item');\n\t\t}",
		'replace' => "\t\tif (false) {\n\t\t\tthrow new ValidationException('inactive_item');\n\t\t}",
	],
	[
		'name' => 'share-mode-suffix-dropped',
		'file' => $rowLocking,
		'search' => "return ' LOCK IN SHARE MODE';",
		'replace' => "return '';",
	],
	[
		'name' => 'deactivate-exclusive-lock-downgraded',
		'file' => $itemService,
		'search' => 'lockById($id, true)',
		'replace' => 'findById($id)',
	],
	[
		'name' => 'scan-transfer-to-location-required-dropped',
		'file' => $movement,
		'search' => "\$toLocationId === null || \$toLocationId <= 0\n\t\t\t\t? throw new ValidationException('validation_failed', '', [\n\t\t\t\t\t['field' => 'toLocationId', 'code' => 'validation_failed'],\n\t\t\t\t])\n\t\t\t\t: \$this->transfer(",
		'replace' => "\$this->transfer(",
	],
	[
		'name' => 'serial-capacity-ceiling-raised',
		'file' => $movement,
		'search' => 'if ($current + $incomingDelta > $unit) {',
		'replace' => 'if ($current + $incomingDelta > $unit * 100) {',
	],
	[
		'name' => 'issue-location-scan-dropped',
		'file' => $movement,
		'search' => "\t\t// Wave D8 / AF-IV12: web issue must honour require_location_scan (not only /scan).\n\t\tLocationScanPolicy::assertMatches(\$this->config, \$this->locations, \$locationId, \$locationCode);\n\t\treturn \$this->postSingle(\$actorUid, 'issue', \$itemId, \$locationId, \$qty, \$reason, \$lotCode, true);",
		'replace' => "\t\treturn \$this->postSingle(\$actorUid, 'issue', \$itemId, \$locationId, \$qty, \$reason, \$lotCode, true);",
	],
	[
		'name' => 'transfer-to-location-scan-dropped',
		'file' => $movement,
		'search' => "\t\tLocationScanPolicy::assertMatches(\n\t\t\t\$this->config,\n\t\t\t\$this->locations,\n\t\t\t\$toLocationId,\n\t\t\t\$toLocationCode,\n\t\t\t'toLocationCode',\n\t\t);\n",
		'replace' => '',
	],
	[
		'name' => 'item-lock-conflict-dropped',
		'file' => $movement,
		'search' => "if (!\$exclusive && \$item->getTrackMode() === 'serial') {\n\t\t\tthrow new ConflictException('item_lock_conflict');\n\t\t}",
		'replace' => "if (false && !\$exclusive && \$item->getTrackMode() === 'serial') {\n\t\t\tthrow new ConflictException('item_lock_conflict');\n\t\t}",
	],
	[
		'name' => 'issue-with-ref-office-gate-dropped',
		'file' => $movement,
		'search' => "\$this->access->requireWrite(\$actorUid);\n\t\t\$this->assertLocationAccess(\$actorUid, \$locationId);\n\t\t\$refType = CodeRules::trim(\$refType);",
		'replace' => "\$this->assertLocationAccess(\$actorUid, \$locationId);\n\t\t\$refType = CodeRules::trim(\$refType);",
	],
	[
		'name' => 'track-mode-zero-stock-gate-dropped',
		'file' => $itemService,
		'search' => "if (\$this->items->hasNonZeroBalance(\$itemId)) {\n\t\t\tthrow new ConflictException('track_mode_requires_zero_stock');\n\t\t}",
		'replace' => "if (false && \$this->items->hasNonZeroBalance(\$itemId)) {\n\t\t\tthrow new ConflictException('track_mode_requires_zero_stock');\n\t\t}",
	],
	[
		'name' => 'scan-location-code-before-acl',
		'file' => $movement,
		'search' => "\t\t// ACL before location-code checks — otherwise a wrong code on a hidden\n\t\t// location returns location_code_mismatch and proves the shelf exists.\n\t\t\$this->assertLocationAccess(\$actorUid, \$locationId);\n\t\tif (\$kind === 'transfer' && \$toLocationId !== null && \$toLocationId > 0) {\n\t\t\t\$this->assertLocationAccess(\$actorUid, \$toLocationId);\n\t\t}\n\n\t\tLocationScanPolicy::assertMatches(\$this->config, \$this->locations, \$locationId, \$locationCode);",
		'replace' => "\t\tLocationScanPolicy::assertMatches(\$this->config, \$this->locations, \$locationId, \$locationCode);\n\t\t\$this->assertLocationAccess(\$actorUid, \$locationId);\n\t\tif (\$kind === 'transfer' && \$toLocationId !== null && \$toLocationId > 0) {\n\t\t\t\$this->assertLocationAccess(\$actorUid, \$toLocationId);\n\t\t}",
	],
]);
