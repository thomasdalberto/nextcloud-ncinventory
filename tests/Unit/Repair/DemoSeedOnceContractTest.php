<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Tests\Unit\Repair;

use OCA\InventoryCheck\Repair\EnsureInventoryCheckSchema;
use PHPUnit\Framework\TestCase;

/**
 * Demo seed must run at most once so admins can delete WH / FILTER-42 without resurrection on repair.
 */
final class DemoSeedOnceContractTest extends TestCase
{
	public function testDemoIsPerAccountAndRepairDoesNotSeedGlobalData(): void
	{
		$root = dirname(__DIR__, 3);
		$src = (string)file_get_contents($root . '/lib/Service/WorkspaceService.php');
		self::assertStringContainsString("'workspace_initialized'", $src);
		self::assertStringContainsString('getUserValue($uid', $src);
		self::assertStringContainsString('setUserValue($uid', $src);
		$repair = (string)file_get_contents($root . '/lib/Repair/EnsureInventoryCheckSchema.php');
		self::assertStringNotContainsString("->insert('iv_items')", $repair);
		self::assertStringNotContainsString("->insert('iv_locations')", $repair);
	}

	public function testUninstallClearsAllAppConfigIncludingDemoFlag(): void
	{
		$src = (string)file_get_contents(dirname(__DIR__, 3) . '/lib/Repair/UninstallDropTables.php');
		self::assertStringContainsString('deleteAppValues(self::APP_ID)', $src);
	}
}
