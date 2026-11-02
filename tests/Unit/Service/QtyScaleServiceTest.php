<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Tests\Unit\Service;

use OCA\InventoryCheck\Service\QtyScaleService;
use OCA\InventoryCheck\Service\WorkspaceSettings;
use OCP\IDBConnection;
use OCP\Lock\ILockingProvider;
use PHPUnit\Framework\TestCase;

final class QtyScaleServiceTest extends TestCase
{
	public function testAlreadyFractionalWorkspaceDoesNotRewriteTheLedger(): void
	{
		$settings = $this->createMock(WorkspaceSettings::class);
		$settings->method('get')->willReturn('3');
		$db = $this->createMock(IDBConnection::class);
		$db->expects(self::never())->method('beginTransaction');
		$locks = $this->createMock(ILockingProvider::class);
		$locks->expects(self::never())->method('acquireLock');
		self::assertSame(['qtyScale' => 3, 'changed' => false], (new QtyScaleService($db, $settings, $locks))->enableFractional());
	}

	public function testConcurrentWinnerIsRecheckedAfterObtainingTheWorkspaceLock(): void
	{
		$settings = $this->createMock(WorkspaceSettings::class);
		$settings->method('id')->willReturn(7);
		$settings->method('get')->willReturnOnConsecutiveCalls('0', '3');
		$db = $this->createMock(IDBConnection::class);
		$db->expects(self::never())->method('beginTransaction');
		$locks = $this->createMock(ILockingProvider::class);
		$locks->expects(self::once())->method('acquireLock')->with('inventorycheck/qty_scale/7', ILockingProvider::LOCK_EXCLUSIVE);
		$locks->expects(self::once())->method('releaseLock')->with('inventorycheck/qty_scale/7', ILockingProvider::LOCK_EXCLUSIVE);
		self::assertFalse((new QtyScaleService($db, $settings, $locks))->enableFractional()['changed']);
	}
}
