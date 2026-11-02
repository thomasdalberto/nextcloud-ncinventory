<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Tests\Unit\Repair;

use OCA\InventoryCheck\Repair\UninstallDropTables;
use OCA\InventoryCheck\Service\UpgradeBackupCatalog;
use PHPUnit\Framework\TestCase;

final class UninstallDropTablesTest extends TestCase
{
	public function testUninstallListUsesIvPrefixOnly(): void
	{
		foreach (UninstallDropTables::TABLES as $table) {
			self::assertStringStartsWith('iv_', $table, $table . ' must use iv_ prefix');
		}
	}

	public function testBackupCatalogMatchesUninstall(): void
	{
		$uninstall = UninstallDropTables::TABLES;
		sort($uninstall);
		$backup = UpgradeBackupCatalog::BACKUP_TABLES;
		sort($backup);
		self::assertSame($uninstall, $backup);
	}

	public function testUninstallListsAllTables(): void
	{
		self::assertCount(16, UninstallDropTables::TABLES);
		self::assertContains('iv_inv_push', UninstallDropTables::TABLES);
		self::assertContains('iv_movements', UninstallDropTables::TABLES);
		self::assertContains('iv_scan_devices', UninstallDropTables::TABLES);
		self::assertContains('iv_notif_log', UninstallDropTables::TABLES);
		self::assertContains('iv_cc_camp', UninstallDropTables::TABLES);
		self::assertContains('iv_cc_line', UninstallDropTables::TABLES);
		self::assertContains('iv_loc_fav', UninstallDropTables::TABLES);
		self::assertContains('iv_loc_acl', UninstallDropTables::TABLES);
		self::assertContains('iv_scan_idem', UninstallDropTables::TABLES);
	}

	public function testRepairStepNameIsDescriptive(): void
	{
		$step = new UninstallDropTables(
			$this->createMock(\OCP\IDBConnection::class),
			$this->createMock(\OCP\IConfig::class),
			$this->createMock(\OCP\Files\IRootFolder::class),
		);
		self::assertStringContainsString('inventorycheck', $step->getName());
		self::assertStringContainsString('uninstall', strtolower($step->getName()));
	}
}
