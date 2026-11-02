<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Tests\Unit\Service;

use OCA\InventoryCheck\Db\WorkspaceMapper;
use OCA\InventoryCheck\Exception\ConflictException;
use OCA\InventoryCheck\Exception\NotFoundException;
use OCA\InventoryCheck\Exception\PermissionDeniedException;
use OCA\InventoryCheck\Exception\ValidationException;
use OCA\InventoryCheck\Service\DemoInventory;
use OCA\InventoryCheck\Service\WorkspaceService;
use OCA\InventoryCheck\Service\WorkspaceEventService;
use OCA\InventoryCheck\Activity\WorkspaceSubjects;
use OCP\Files\AppData\IAppDataFactory;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IUserManager;
use OCP\Lock\ILockingProvider;
use PHPUnit\Framework\TestCase;

final class WorkspaceServiceTest extends TestCase
{
	private WorkspaceMapper $mapper;
	private WorkspaceService $service;
	private WorkspaceEventService $events;

	protected function setUp(): void
	{
		$this->mapper = $this->createMock(WorkspaceMapper::class);
		$this->mapper->method('find')->willReturn(['id' => 7, 'owner_uid' => 'alice', 'name' => 'Private', 'demo_uid' => null]);
		$users = $this->createMock(IUserManager::class);
		$users->method('userExists')->willReturn(true);
		$this->events = $this->createMock(WorkspaceEventService::class);
		$this->service = new WorkspaceService($this->mapper, $this->createMock(IDBConnection::class),
			$this->createMock(IConfig::class), $users, $this->createMock(ILockingProvider::class),
			$this->createMock(DemoInventory::class), $this->createMock(IAppDataFactory::class), $this->events);
	}

	public function testOwnerCanOpenWithoutAnInvitation(): void
	{
		self::assertSame('owner', $this->service->requireMember('alice', 7)['role']);
	}

	/** @dataProvider nonMembers */
	public function testOnlyAcceptedMembersCanRead(array $members): void
	{
		$this->mapper->method('members')->willReturn($members);
		$this->expectException(NotFoundException::class);
		$this->service->requireMember('bob', 7);
	}

	public static function nonMembers(): array
	{
		return [[[]], [[['user_id' => 'bob', 'role' => 'editor', 'status' => 'pending']]],
			[[['user_id' => 'carol', 'role' => 'editor', 'status' => 'accepted']]]];
	}

	public function testAcceptedEditorCanReadButCannotInvite(): void
	{
		$this->mapper->method('members')->willReturn([['id' => 12, 'user_id' => 'bob', 'role' => 'editor', 'status' => 'accepted']]);
		self::assertSame(7, $this->service->requireMember('bob', 7)['id']);
		self::assertSame('editor', $this->service->requireMember('bob', 7)['role']);
		$this->mapper->expects(self::never())->method('invite');
		$this->expectException(PermissionDeniedException::class);
		$this->service->invite('bob', 7, 'carol', 'editor');
	}

	public function testOwnerCannotInviteAnotherOwner(): void
	{
		$this->mapper->expects(self::never())->method('invite');
		$this->expectException(ValidationException::class);
		$this->service->invite('alice', 7, 'bob', 'owner');
	}

	public function testDuplicateInvitationIsAConflict(): void
	{
		$this->events->expects(self::never())->method('publish');
		$this->mapper->expects(self::once())->method('invite')->with(7, 'bob', 'viewer')->willReturn(false);
		$this->expectException(ConflictException::class);
		$this->service->invite('alice', 7, 'bob', 'viewer');
	}

	public function testResponseCanOnlyChangeRecipientsOwnInvitation(): void
	{
		$this->mapper->expects(self::once())->method('pendingInvitation')->with('bob', 7, 12)
			->willReturn(['id' => 12, 'name' => 'Private', 'owner_uid' => 'alice']);
		$this->mapper->expects(self::once())->method('respond')->with(7, 'bob', true, 12)->willReturn(true);
		$this->events->expects(self::once())->method('clearInvitation')->with(12, 'bob');
		$this->events->expects(self::once())->method('publish')->with(WorkspaceSubjects::ACCEPTED, self::anything(), 'bob');
		$this->service->respond('bob', 7, true, 12);
	}

	public function testOldOrAnsweredInvitationsEmitNothing(): void
	{
		$this->mapper->method('pendingInvitation')->willReturn(null);
		$this->mapper->expects(self::never())->method('respond');
		$this->events->expects(self::never())->method('publish');
		$this->expectException(NotFoundException::class);
		$this->service->respond('bob', 7, true);
	}

	public function testOpeningNotificationDoesNotAcceptOrDeclineTheInvitation(): void
	{
		$this->mapper->expects(self::once())->method('pendingInvitation')->with('bob', 7, 12)->willReturn(['id' => 12]);
		$this->mapper->expects(self::never())->method('respond');
		$this->events->expects(self::once())->method('clearInvitation')->with(12, 'bob');
		$this->events->expects(self::never())->method('publish');
		$this->service->dismissInvitation('bob', 7, 12);
	}

	public function testSuccessfulInvitationPublishesOnceWithItsId(): void
	{
		$this->mapper->method('invite')->willReturn(true);
		$this->mapper->method('pendingInvitation')->willReturn(['id' => 12]);
		$this->events->expects(self::once())->method('publish')->with(WorkspaceSubjects::INVITED,
			self::anything(), 'alice', [], 'bob', ['invitationId' => 12, 'role' => 'viewer']);
		$this->service->invite('alice', 7, 'bob', 'viewer');
	}

	public function testEditorCanLeaveButCannotRemoveOthers(): void
	{
		$this->mapper->method('members')->willReturn([['id' => 12, 'user_id' => 'bob', 'role' => 'editor', 'status' => 'accepted']]);
		$this->mapper->expects(self::once())->method('removeMember')->with(7, 'bob')->willReturn(true);
		$this->events->expects(self::once())->method('publish')->with(WorkspaceSubjects::LEFT, self::anything(), 'bob', self::anything(), 'bob');
		$this->service->removeMember('bob', 7, 'bob');
		$this->expectException(PermissionDeniedException::class);
		$this->service->removeMember('bob', 7, 'carol');
	}

	public function testOwnerCannotBeRemovedThroughMemberEndpoint(): void
	{
		$this->expectException(PermissionDeniedException::class);
		$this->service->removeMember('alice', 7, 'alice');
	}
}
