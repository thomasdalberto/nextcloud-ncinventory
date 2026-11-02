<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Tests\Unit\Service;

use OCA\InventoryCheck\Db\ScanDevice;
use OCA\InventoryCheck\Exception\MobileRequestException;
use OCA\InventoryCheck\Service\AccessControlService;
use OCA\InventoryCheck\Service\LocationAclService;
use OCA\InventoryCheck\Service\MobileAccessService;
use OCA\InventoryCheck\Service\ProductLookupService;
use OCA\InventoryCheck\Service\WorkspaceSettings;
use PHPUnit\Framework\TestCase;

final class MobileAccessServiceTest extends TestCase
{
	private function service(): MobileAccessService
	{
		$settings = $this->createMock(WorkspaceSettings::class);
		$settings->method('get')->willReturnCallback(static fn (string $key, string $default = ''): string => $default);
		return new MobileAccessService($this->createMock(AccessControlService::class), $settings,
			$this->createMock(LocationAclService::class), $this->createMock(ProductLookupService::class));
	}

	public function testSessionOrPairedScannerIsAccepted(): void
	{
		$service = $this->service();
		$service->requireCaller('alice', null);
		$service->requireCaller(null, new ScanDevice());
		self::assertSame(5, $service->bootstrap('alice', null)['companionApi']);
	}

	public function testAnonymousCallerIsDenied(): void
	{
		$this->expectException(MobileRequestException::class);
		$this->expectExceptionMessage('auth_required');
		$this->service()->requireCaller(null, null);
	}
}
