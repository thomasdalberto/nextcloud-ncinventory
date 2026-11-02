<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Tests\Unit\Controller;

use OCA\InventoryCheck\Controller\ConfigController;
use OCA\InventoryCheck\Exception\ValidationException;
use OCA\InventoryCheck\Service\AccessControlService;
use OCA\InventoryCheck\Service\LocationAclService;
use OCA\InventoryCheck\Service\LowStockService;
use OCA\InventoryCheck\Service\QtyScaleService;
use OCA\InventoryCheck\Service\WorkspaceSettings;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Config list writes must reject unknown uids/gids (no silent lock-out typos).
 */
final class ConfigDirectoryValidationTest extends TestCase
{
	/** @var IRequest&MockObject */
	private IRequest $request;
	/** @var AccessControlService&MockObject */
	private AccessControlService $access;
	/** @var IUserManager&MockObject */
	private IUserManager $users;
	/** @var IGroupManager&MockObject */
	private IGroupManager $groups;
	/** @var LowStockService&MockObject */
	private LowStockService $lowStock;
	/** @var QtyScaleService&MockObject */
	private QtyScaleService $qtyScaleService;
	/** @var LocationAclService&MockObject */
	private LocationAclService $locationAcl;
	/** @var WorkspaceSettings&MockObject */
	private WorkspaceSettings $config;

	private ConfigController $controller;

	protected function setUp(): void
	{
		parent::setUp();
		$this->request = $this->createMock(IRequest::class);
		$this->access = $this->createMock(AccessControlService::class);
		$this->users = $this->createMock(IUserManager::class);
		$this->groups = $this->createMock(IGroupManager::class);
		$this->lowStock = $this->createMock(LowStockService::class);
		$this->lowStock->method('isPerLocationHintEnabled')->willReturn(false);
		$this->qtyScaleService = $this->createMock(QtyScaleService::class);
		$this->locationAcl = $this->createMock(LocationAclService::class);
		$this->config = $this->createMock(WorkspaceSettings::class);
		$this->controller = new ConfigController(
			$this->request,
			$this->access,
			$this->lowStock,
			$this->qtyScaleService,
			$this->locationAcl,
			$this->createMock(\OCA\InventoryCheck\Service\ProductLookupService::class),
			$this->config,
			$this->createMock(\OCA\InventoryCheck\Service\WorkspaceService::class),
			$this->createMock(\OCA\InventoryCheck\Service\WorkspaceContext::class),
			$this->createMock(\Psr\Log\LoggerInterface::class),
		);
	}

	public function testSaveLocationAclEnabledOnlySkipsEmptySubject(): void
	{
		$this->access->method('currentUserId')->willReturn('admin');
		$this->access->expects($this->exactly(2))->method('requireWorkspaceAdmin')->with('admin');
		$this->request->method('getParams')->willReturn([
			'enabled' => true,
			'subjectType' => 'user',
			'subjectId' => '',
			'locationIds' => [],
		]);
		$this->locationAcl->expects($this->once())->method('setEnabled')->with(true);
		$this->locationAcl->expects($this->never())->method('setForSubject');
		$this->locationAcl->method('isEnabled')->willReturn(true);
		$this->locationAcl->method('isDevicesStrict')->willReturn(false);
		$this->locationAcl->method('listAll')->willReturn([]);

		$response = $this->controller->saveLocationAcl();
		$this->assertSame(200, $response->getStatus());
	}

	public function testSaveLocationAclPersistsDevicesStrict(): void
	{
		$this->access->method('currentUserId')->willReturn('admin');
		$this->access->expects($this->exactly(2))->method('requireWorkspaceAdmin')->with('admin');
		$this->request->method('getParams')->willReturn([
			'enabled' => true,
			'devicesStrict' => true,
		]);
		$this->locationAcl->expects($this->once())->method('setEnabled')->with(true);
		$this->locationAcl->expects($this->once())->method('setDevicesStrict')->with(true);
		$this->locationAcl->method('isEnabled')->willReturn(true);
		$this->locationAcl->method('isDevicesStrict')->willReturn(true);
		$this->locationAcl->method('listAll')->willReturn([]);

		$response = $this->controller->saveLocationAcl();
		$data = $response->getData();
		$this->assertTrue($data['devicesStrict']);
	}

	public function testSaveLocationAclCommitsEnabledAfterAssignmentsInSource(): void
	{
		$src = (string)file_get_contents(dirname(__DIR__, 3) . '/lib/Controller/ConfigController.php');
		$phase2 = strpos($src, 'Phase 2 — assignments first');
		$this->assertNotFalse($phase2);
		$replacePos = strpos($src, 'replaceAll($uid, $assignments)', $phase2);
		$strictPos = strpos($src, 'setDevicesStrict($devicesStrict)', $phase2);
		$enabledPos = strpos($src, 'setEnabled($enabled)', $phase2);
		$this->assertNotFalse($replacePos);
		$this->assertNotFalse($strictPos);
		$this->assertNotFalse($enabledPos);
		$this->assertLessThan($enabledPos, $replacePos, 'enabled must commit after assignment writes');
		$this->assertLessThan($enabledPos, $strictPos, 'devicesStrict must commit before enabled (no unbound org-wide window)');
	}
}
