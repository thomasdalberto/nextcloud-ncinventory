<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Tests\Unit\Service;

use OCA\InventoryCheck\Exception\PermissionDeniedException;
use OCA\InventoryCheck\Service\AccessControlService;
use OCA\InventoryCheck\Service\WorkspaceContext;
use OCA\InventoryCheck\Service\WorkspaceSettings;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

final class AccessControlServiceTest extends TestCase
{
	public function testInstanceAdministrationDoesNotGrantWorkspaceAdministration(): void
	{
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('isAdmin')->willReturn(true);
		$scope = $this->createMock(WorkspaceContext::class);
		$scope->method('canAdmin')->willReturn(false);
		$scope->method('canEdit')->willReturn(false);
		$access = new AccessControlService($groups, $this->createMock(IUserSession::class), $scope, $this->createMock(WorkspaceSettings::class));
		self::assertTrue($access->isSystemAdmin('admin'));
		self::assertFalse($access->isWorkspaceAdmin('admin'));
		self::assertFalse($access->canWrite('admin'));
		$this->expectException(PermissionDeniedException::class);
		$access->requireWorkspaceAdmin('admin');
	}

	public function testWorkspaceAdminHasNoInstanceAdministrationPrivileges(): void
	{
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('isAdmin')->willReturn(false);
		$scope = $this->createMock(WorkspaceContext::class);
		$scope->method('canAdmin')->with('alice')->willReturn(true);
		$scope->method('canEdit')->with('alice')->willReturn(true);
		$access = new AccessControlService($groups, $this->createMock(IUserSession::class), $scope, $this->createMock(WorkspaceSettings::class));
		self::assertTrue($access->isWorkspaceAdmin('alice'));
		self::assertTrue($access->canWrite('alice'));
		$this->expectException(PermissionDeniedException::class);
		$access->requireSystemAdmin('alice');
	}

	public function testCurrentUserRequiresAnAuthenticatedSession(): void
	{
		$access = new AccessControlService($this->createMock(IGroupManager::class), $this->createMock(IUserSession::class), $this->createMock(WorkspaceContext::class), $this->createMock(WorkspaceSettings::class));
		$this->expectException(PermissionDeniedException::class);
		$access->currentUserId();
	}

	public function testNegativeStockReadsTheWorkspacePolicy(): void
	{
		$settings = $this->createMock(WorkspaceSettings::class);
		$settings->method('get')->with(AccessControlService::KEY_ALLOW_NEGATIVE, '0')->willReturn('1');
		$settings->expects(self::once())->method('set')->with(AccessControlService::KEY_ALLOW_NEGATIVE, '0');
		$access = new AccessControlService($this->createMock(IGroupManager::class), $this->createMock(IUserSession::class), $this->createMock(WorkspaceContext::class), $settings);
		self::assertTrue($access->allowNegativeStock());
		$access->setAllowNegativeStock(false);
	}
}
