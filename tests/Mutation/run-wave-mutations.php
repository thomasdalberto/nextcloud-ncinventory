#!/usr/bin/env php
<?php

declare(strict_types=1);

require __DIR__ . '/harness.php';

// Keep Wave A/B/C integration oracles; inventur mutants need close/start races.
// Dev DB must not accumulate multi-million oc_iv_cc_line / balances rows or each
// mutant run takes minutes (TRUNCATE those tables in local troubleshooting).
runMutations(dirname(__DIR__, 2), 'WaveContractsTest|LowStockNotifyServiceTest|StockIssueFacadeTest|WaveAbFeaturesIntegrationTest|WaveCFeaturesIntegrationTest|MobileAccessServiceTest|ConfigDirectoryValidationTest|ScannerControllerDeviceBindTest', [
	[
		'name' => 'can-start-always-true',
		'file' => 'lib/Service/CycleCountSemantics.php',
		'search' => "return \$status === CycleCountService::STATUS_OPEN;",
		'replace' => 'return true;',
	],
	[
		'name' => 'incomplete-never',
		'file' => 'lib/Service/CycleCountSemantics.php',
		'search' => 'return $qtyCounted === null && !$abandonUncounted;',
		'replace' => 'return false;',
	],
	[
		'name' => 'qty-upper-bound-removed',
		'file' => 'lib/Service/CycleCountSemantics.php',
		'search' => 'return $qtyCounted >= 0 && $qtyCounted <= 1_000_000_000;',
		'replace' => 'return $qtyCounted >= 0;',
	],
	[
		'name' => 'favourite-cap-raised',
		'file' => 'lib/Service/LocationFavouriteService.php',
		'search' => 'public const MAX = 20;',
		'replace' => 'public const MAX = 200;',
	],
	[
		'name' => 'photo-max-bytes-raised',
		'file' => 'lib/Service/ItemPhotoService.php',
		'search' => 'public const MAX_BYTES = 2_097_152;',
		'replace' => 'public const MAX_BYTES = 20_000_000;',
	],
	[
		'name' => 'lowstock-allows-reorder-zero',
		'file' => 'lib/Service/LowStockQuery.php',
		'search' => "if (\$reorderLevel <= 0) {\n\t\t\treturn false;\n\t\t}",
		'replace' => "if (false) {\n\t\t\treturn false;\n\t\t}",
	],
	[
		'name' => 'notify-debounce-1s',
		'file' => 'lib/Service/LowStockNotifyService.php',
		'search' => 'public const DEBOUNCE_SECONDS = 86400;',
		'replace' => 'public const DEBOUNCE_SECONDS = 1;',
	],
	[
		'name' => 'notify-skips-recovery-clear',
		'file' => 'lib/Service/LowStockNotifyService.php',
		'search' => "if (!\$isLow) {\n\t\t\t\$this->notifyLog->deleteByDedupeKey(\$openKey);\n\t\t\treturn;\n\t\t}",
		'replace' => "if (!\$isLow) {\n\t\t\treturn;\n\t\t}",
	],
	[
		'name' => 'notify-ignores-open-episode',
		'file' => 'lib/Service/LowStockNotifyService.php',
		'search' => "if (\$this->notifyLog->findByDedupeKey(\$openKey) !== null) {\n\t\t\treturn;\n\t\t}",
		'replace' => "if (false) {\n\t\t\treturn;\n\t\t}",
	],
	[
		'name' => 'can-count-always-true',
		'file' => 'lib/Service/CycleCountSemantics.php',
		'search' => 'return $status === CycleCountService::STATUS_COUNTING;',
		'replace' => 'return true;',
	],
	[
		'name' => 'conflict-never',
		'file' => 'lib/Service/CycleCountSemantics.php',
		'search' => 'return $systemQty !== $currentQty;',
		'replace' => 'return false;',
	],
	[
		'name' => 'conflict-close-never-blocks',
		'file' => 'lib/Service/CycleCountSemantics.php',
		'search' => 'return $hasAnyConflict && !$acknowledgeConflicts;',
		'replace' => 'return false;',
	],
	[
		'name' => 'facade-skips-display-to-storage',
		'file' => 'lib/Public/StockIssueFacade.php',
		'search' => '$storageQty = QtyScale::toStorage($this->config, $line[\'qty\'] ?? 0);',
		'replace' => '$storageQty = (int)($line[\'qty\'] ?? 0);',
	],
	[
		'name' => 'close-skips-balance-lockpairs',
		'file' => 'lib/Service/CycleCountService.php',
		'search' => '$lockedBalances = $pairs === [] ? [] : $this->balances->lockPairs($pairs);',
		'replace' => '$lockedBalances = [];',
	],
	[
		'name' => 'close-skips-item-locks',
		'file' => 'lib/Service/CycleCountService.php',
		'search' => "foreach (\$sortedItemIds as \$itemId) {\n\t\t\t\t\$item = \$this->items->lockById(\$itemId, false);\n\t\t\t\tif (!\$item->getActive()) {\n\t\t\t\t\tthrow new ValidationException('inactive_item');\n\t\t\t\t}\n\t\t\t\t\$trackModes[\$itemId] = \$item->getTrackMode();\n\t\t\t}",
		'replace' => 'foreach ($sortedItemIds as $itemId) { $trackModes[$itemId] = \'none\'; }',
	],
	[
		'name' => 'inventur-eligible-always',
		'file' => 'lib/Service/CycleCountSemantics.php',
		'search' => "return \$trackMode === 'none';",
		'replace' => 'return true;',
	],
	[
		'name' => 'close-ignores-track-mode-flip',
		'file' => 'lib/Service/CycleCountService.php',
		'search' => "if (\$trackDetails !== []) {\n\t\t\t\tthrow new ValidationException('track_mode_changed', '', \$trackDetails);\n\t\t\t}",
		'replace' => "if (false) {\n\t\t\t\tthrow new ValidationException('track_mode_changed', '', \$trackDetails);\n\t\t\t}",
	],
	[
		'name' => 'item-reorder-ceiling-hardcoded',
		'file' => 'lib/Service/ItemService.php',
		'search' => 'QtyScale::maxStorage($this->config)',
		'replace' => '1000000',
	],
	[
		'name' => 'item-stocktake-guard-removed',
		'file' => 'lib/Service/ItemService.php',
		'search' => "if (\$this->cycleLines->countOpenCampaignsForItem(\$id) > 0) {\n\t\t\t\tthrow new ConflictException('item_in_open_stocktake');\n\t\t\t}",
		'replace' => "if (false) {\n\t\t\t\tthrow new ConflictException('item_in_open_stocktake');\n\t\t\t}",
	],
	[
		'name' => 'csv-import-notifies-inside-tx',
		'file' => 'lib/Service/CsvImportService.php',
		'search' => "null,\n\t\t\t\t\t\t\tfalse,\n\t\t\t\t\t\t);",
		'replace' => "null,\n\t\t\t\t\t\t\ttrue,\n\t\t\t\t\t\t);",
	],
	[
		'name' => 'device-acl-ignore-grants',
		'file' => 'lib/Service/LocationAclService.php',
		'search' => "if (\$ids === []) {\n\t\t\treturn [];\n\t\t}\n\t\treturn array_values(array_unique(\$ids));",
		'replace' => "return null;",
	],
	[
		'name' => 'device-acl-empty-fail-open',
		'file' => 'lib/Service/LocationAclService.php',
		'search' => "if (\$ids === []) {\n\t\t\treturn [];\n\t\t}",
		'replace' => "if (\$ids === []) {\n\t\t\treturn null;\n\t\t}",
	],
	[
		'name' => 'device-acl-invalid-device-fail-open',
		'file' => 'lib/Service/LocationAclService.php',
		'search' => "if (\$raw === '' || !ctype_digit(\$raw) || (int)\$raw <= 0) {\n\t\t\treturn [];\n\t\t}",
		'replace' => "if (\$raw === '' || !ctype_digit(\$raw) || (int)\$raw <= 0) {\n\t\t\treturn null;\n\t\t}",
	],
	[
		'name' => 'create-device-skips-location-bind',
		'file' => 'lib/Controller/ScannerController.php',
		'search' => "if (\$bindIds !== null && \$bindIds !== []) {\n\t\t\t\$deviceId = (int)(\$created['device']['id'] ?? 0);\n\t\t\ttry {\n\t\t\t\tif (\$deviceId <= 0) {\n\t\t\t\t\tthrow new \\RuntimeException('device_create_missing_id');\n\t\t\t\t}\n\t\t\t\t\$this->locationAcl->setForSubject(\n\t\t\t\t\tLocationAclService::TYPE_DEVICE,\n\t\t\t\t\t(string)\$deviceId,\n\t\t\t\t\t\$bindIds,\n\t\t\t\t);\n\t\t\t} catch (\\Throwable \$e) {\n\t\t\t\t// Fail closed: never leave a half-bound scanner that is pairable org-wide.\n\t\t\t\tif (\$deviceId > 0) {\n\t\t\t\t\ttry {\n\t\t\t\t\t\t\$this->license->deactivateDevice(\$deviceId);\n\t\t\t\t\t} finally {\n\t\t\t\t\t\t// purge even if deactivate throws (slot may still have grants).\n\t\t\t\t\t\t\$this->locationAcl->purgeDevice(\$deviceId);\n\t\t\t\t\t}\n\t\t\t\t}\n\t\t\t\tthrow \$e;\n\t\t\t}\n\t\t}",
		'replace' => "if (false) {\n\t\t}",
	],
	[
		'name' => 'create-device-skips-bind-rollback',
		'file' => 'lib/Controller/ScannerController.php',
		'search' => "} catch (\\Throwable \$e) {\n\t\t\t\t// Fail closed: never leave a half-bound scanner that is pairable org-wide.\n\t\t\t\tif (\$deviceId > 0) {\n\t\t\t\t\ttry {\n\t\t\t\t\t\t\$this->license->deactivateDevice(\$deviceId);\n\t\t\t\t\t} finally {\n\t\t\t\t\t\t// purge even if deactivate throws (slot may still have grants).\n\t\t\t\t\t\t\$this->locationAcl->purgeDevice(\$deviceId);\n\t\t\t\t\t}\n\t\t\t\t}\n\t\t\t\tthrow \$e;\n\t\t\t}",
		'replace' => "} catch (\\Throwable \$e) {\n\t\t\t\tthrow \$e;\n\t\t\t}",
	],
	[
		'name' => 'bootstrap-hides-device-strict-flag',
		'file' => 'lib/Service/MobileAccessService.php',
		'search' => "'locationAclDevicesStrict' => \$this->locationAcl->isDevicesStrict(),",
		'replace' => "'locationAclDevicesStrict' => false,",
	],
	[
		'name' => 'cyclecount-acl-deny-leaks-unknown-location',
		'file' => 'lib/Service/CycleCountService.php',
		'search' => "private function assertAccessibleOrNotFound(string \$actorUid, int \$locationId, string \$notFoundCode): void\n\t{\n\t\ttry {\n\t\t\t\$this->locationAcl->assertCanAccess(\$actorUid, \$locationId);\n\t\t} catch (NotFoundException) {\n\t\t\tthrow new NotFoundException(\$notFoundCode);\n\t\t}\n\t}",
		'replace' => "private function assertAccessibleOrNotFound(string \$actorUid, int \$locationId, string \$notFoundCode): void\n\t{\n\t\t\$this->locationAcl->assertCanAccess(\$actorUid, \$locationId);\n\t}",
	],
	[
		'name' => 'cyclecount-create-uses-offset-phantoms',
		'file' => 'lib/Service/CycleCountService.php',
		'search' => "\$page = \$this->items->searchActiveAfterId(\$afterId, self::ITEM_PAGE);",
		'replace' => "\$page = \$this->items->search('', true, self::ITEM_PAGE, \$afterId)['data'];",
	],
]);
