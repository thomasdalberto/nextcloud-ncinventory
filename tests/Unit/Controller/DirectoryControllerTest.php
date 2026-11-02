<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Tests\Unit\Controller;

use OCA\InventoryCheck\Controller\DirectoryController;
use OCA\InventoryCheck\Exception\PermissionDeniedException;
use OCA\InventoryCheck\Service\AccessControlService;
use OCA\InventoryCheck\Service\DirectoryOptionsService;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Portfolio rule (planning/check-productivity-suite/ACCESS-AND-DIRECTORY-PICKERS.md
 * §2/anti-patterns): directory search must be gated to app admins, never every
 * canUseApp user, and must never search on a too-short query (avoids dumping
 * the whole directory on every keystroke).
 */
final class DirectoryControllerTest extends TestCase
{
	/** @var IRequest&MockObject */
	private IRequest $request;
	/** @var AccessControlService&MockObject */
	private AccessControlService $access;
	/** @var DirectoryOptionsService&MockObject */
	private DirectoryOptionsService $directory;
	private DirectoryController $controller;

	protected function setUp(): void
	{
		parent::setUp();
		$this->request = $this->createMock(IRequest::class);
		$this->access = $this->createMock(AccessControlService::class);
		$this->directory = $this->createMock(DirectoryOptionsService::class);
		$this->controller = new DirectoryController($this->request, $this->access, $this->directory);
	}

	public function testSearchUsersRequiresAppAdmin(): void
	{
		$this->access->method('currentUserId')->willReturn('field-worker');
		$this->access->expects($this->once())->method('requireWorkspaceAdmin')->with('field-worker')
			->willThrowException(new PermissionDeniedException());
		$this->directory->expects($this->never())->method('searchUsers');

		$this->expectException(PermissionDeniedException::class);
		$this->controller->searchUsers();
	}

	public function testSearchGroupsRequiresAppAdmin(): void
	{
		$this->access->method('currentUserId')->willReturn('field-worker');
		$this->access->expects($this->once())->method('requireWorkspaceAdmin')->with('field-worker')
			->willThrowException(new PermissionDeniedException());
		$this->directory->expects($this->never())->method('searchGroups');

		$this->expectException(PermissionDeniedException::class);
		$this->controller->searchGroups();
	}

	public function testSearchUsersShortQueryReturnsEmptyWithoutDirectoryHit(): void
	{
		$this->access->method('currentUserId')->willReturn('admin');
		$this->request->method('getParam')->with('q', '')->willReturn('a');
		$this->directory->expects($this->never())->method('searchUsers');

		$response = $this->controller->searchUsers();
		$this->assertSame(200, $response->getStatus());
		$this->assertSame(['users' => []], $response->getData());
	}

	public function testSearchGroupsShortQueryReturnsEmptyWithoutDirectoryHit(): void
	{
		$this->access->method('currentUserId')->willReturn('admin');
		$this->request->method('getParam')->with('q', '')->willReturn(' a ');
		$this->directory->expects($this->never())->method('searchGroups');

		$response = $this->controller->searchGroups();
		$this->assertSame(['groups' => []], $response->getData());
	}

	public function testSearchUsersDelegatesToDirectoryServiceForValidQuery(): void
	{
		$this->access->method('currentUserId')->willReturn('admin');
		$this->request->method('getParam')->with('q', '')->willReturn(' anna ');
		$this->directory->expects($this->once())->method('searchUsers')->with('anna', 20)
			->willReturn([['id' => 'anna', 'displayName' => 'Anna']]);

		$response = $this->controller->searchUsers();
		$this->assertSame(200, $response->getStatus());
		$this->assertSame(['users' => [['id' => 'anna', 'displayName' => 'Anna']]], $response->getData());
	}

	public function testSearchGroupsDelegatesToDirectoryServiceForValidQuery(): void
	{
		$this->access->method('currentUserId')->willReturn('admin');
		$this->request->method('getParam')->with('q', '')->willReturn('office');
		$this->directory->expects($this->once())->method('searchGroups')->with('office', 20)
			->willReturn([['id' => 'office', 'displayName' => 'Office']]);

		$response = $this->controller->searchGroups();
		$this->assertSame(['groups' => [['id' => 'office', 'displayName' => 'Office']]], $response->getData());
	}
}
