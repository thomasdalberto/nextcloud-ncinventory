<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Tests\Unit\Controller;

use OCA\InventoryCheck\Controller\ScannerController;
use OCA\InventoryCheck\Exception\ValidationException;
use OCA\InventoryCheck\Service\AccessControlService;
use OCA\InventoryCheck\Service\ScannerService;
use OCA\InventoryCheck\Service\LocationAclService;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ScannerControllerDeviceBindTest extends TestCase
{
	public function testCreateDeviceBindsOptionalLocationIds(): void
	{
		$request = $this->createMock(IRequest::class);
		$license = $this->createMock(ScannerService::class);
		$access = $this->createMock(AccessControlService::class);
		$acl = $this->createMock(LocationAclService::class);

		$access->method('currentUserId')->willReturn('admin');
		$access->expects($this->once())->method('requireWorkspaceAdmin')->with('admin');
		$request->method('getParam')->willReturnMap([
			['label', '', 'Van A'],
			['locationIds', null, [11, 22]],
		]);
		$acl->expects($this->once())->method('normalizeLocationIds')->with([11, 22])->willReturn([11, 22]);
		$license->expects($this->once())->method('createDevice')->with('admin', 'Van A')->willReturn([
			'device' => ['id' => 55, 'label' => 'Van A'],
			'pairCode' => 'ABC',
		]);
		$acl->expects($this->once())->method('setForSubject')->with(
			LocationAclService::TYPE_DEVICE,
			'55',
			[11, 22],
		);
		$license->expects($this->never())->method('deactivateDevice');

		$controller = new ScannerController($request, $license, $access, $acl, new \Psr\Log\NullLogger());
		$response = $controller->createDevice();
		$this->assertSame(200, $response->getStatus());
	}

	public function testCreateDeviceSkipsBindWhenLocationIdsEmpty(): void
	{
		$request = $this->createMock(IRequest::class);
		$license = $this->createMock(ScannerService::class);
		$access = $this->createMock(AccessControlService::class);
		$acl = $this->createMock(LocationAclService::class);

		$access->method('currentUserId')->willReturn('admin');
		$access->expects($this->once())->method('requireWorkspaceAdmin')->with('admin');
		$request->method('getParam')->willReturnMap([
			['label', '', 'Van B'],
			['locationIds', null, []],
		]);
		$acl->expects($this->never())->method('normalizeLocationIds');
		$license->expects($this->once())->method('createDevice')->willReturn([
			'device' => ['id' => 56, 'label' => 'Van B'],
			'pairCode' => 'DEF',
		]);
		$acl->expects($this->never())->method('setForSubject');

		$controller = new ScannerController($request, $license, $access, $acl, new \Psr\Log\NullLogger());
		$controller->createDevice();
	}

	public function testCreateDeviceValidatesLocationsBeforeCreate(): void
	{
		$request = $this->createMock(IRequest::class);
		$license = $this->createMock(ScannerService::class);
		$access = $this->createMock(AccessControlService::class);
		$acl = $this->createMock(LocationAclService::class);

		$access->method('currentUserId')->willReturn('admin');
		$access->expects($this->once())->method('requireWorkspaceAdmin')->with('admin');
		$request->method('getParam')->willReturnMap([
			['label', '', 'Van C'],
			['locationIds', null, [999]],
		]);
		$acl->expects($this->once())->method('normalizeLocationIds')->with([999])
			->willThrowException(new ValidationException('validation_failed', '', [
				['field' => 'locationIds', 'code' => 'validation_failed'],
			]));
		$license->expects($this->never())->method('createDevice');

		$controller = new ScannerController($request, $license, $access, $acl, new \Psr\Log\NullLogger());
		$this->expectException(ValidationException::class);
		$controller->createDevice();
	}

	public function testCreateDeviceRollsBackSlotWhenBindFails(): void
	{
		$request = $this->createMock(IRequest::class);
		$license = $this->createMock(ScannerService::class);
		$access = $this->createMock(AccessControlService::class);
		$acl = $this->createMock(LocationAclService::class);

		$access->method('currentUserId')->willReturn('admin');
		$access->expects($this->once())->method('requireWorkspaceAdmin')->with('admin');
		$request->method('getParam')->willReturnMap([
			['label', '', 'Van D'],
			['locationIds', null, [11]],
		]);
		$acl->method('normalizeLocationIds')->willReturn([11]);
		$license->expects($this->once())->method('createDevice')->willReturn([
			'device' => ['id' => 77, 'label' => 'Van D'],
			'pairCode' => 'GHI',
		]);
		$acl->expects($this->once())->method('setForSubject')
			->willThrowException(new RuntimeException('bind_failed'));
		$license->expects($this->once())->method('deactivateDevice')->with(77);
		$acl->expects($this->once())->method('purgeDevice')->with(77);

		$controller = new ScannerController($request, $license, $access, $acl, new \Psr\Log\NullLogger());
		$this->expectException(RuntimeException::class);
		$controller->createDevice();
	}
}
