<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Tests\Unit\Service;

use OCA\InventoryCheck\Activity\WorkspaceSubjects;
use OCA\InventoryCheck\Service\Clock;
use OCA\InventoryCheck\Service\WorkspaceEventService;
use OCP\Activity\IEvent;
use OCP\Activity\IManager as ActivityManager;
use OCP\IUserManager;
use OCP\Notification\IManager as NotificationManager;
use OCP\Notification\INotification;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class WorkspaceEventServiceTest extends TestCase
{
	private function service(ActivityManager $activities, NotificationManager $notifications, ?LoggerInterface $logger = null): WorkspaceEventService
	{
		$users = $this->createMock(IUserManager::class);
		$users->method('userExists')->willReturn(true);
		$clock = $this->createMock(Clock::class);
		$clock->method('now')->willReturn(1700000000);
		return new WorkspaceEventService($activities, $notifications, $users, $clock, $logger ?? $this->createMock(LoggerInterface::class));
	}

	private function notification(): INotification
	{
		$n = $this->createMock(INotification::class);
		foreach (['setApp', 'setUser', 'setDateTime', 'setObject', 'setSubject'] as $method) {
			$n->method($method)->willReturnSelf();
		}
		return $n;
	}

	public function testInvitationAudienceExcludesOtherPendingInvitees(): void
	{
		$recipients = [];
		$event = $this->createMock(IEvent::class);
		foreach (['setApp', 'setType', 'setAuthor', 'setSubject', 'setObject', 'setTimestamp'] as $method) {
			$event->method($method)->willReturnSelf();
		}
		$event->method('setAffectedUser')->willReturnCallback(static function ($uid) use (&$recipients, $event) {
			$recipients[] = $uid;
			return $event;
		});
		$activities = $this->createMock(ActivityManager::class);
		$activities->method('generateEvent')->willReturn($event);
		$activities->expects(self::exactly(3))->method('publish');
		$n = $this->notification();
		$n->expects(self::once())->method('setUser')->with('dave');
		$n->expects(self::once())->method('setObject')->with(WorkspaceEventService::INVITATION_OBJECT, '99');
		$notifications = $this->createMock(NotificationManager::class);
		$notifications->method('createNotification')->willReturn($n);
		$notifications->expects(self::once())->method('notify')->with($n);
		$this->service($activities, $notifications)->publish(WorkspaceSubjects::INVITED,
			['id' => 7, 'name' => 'Private', 'ownerUid' => 'alice'], 'alice', [
				['user_id' => 'bob', 'status' => 'accepted'], ['user_id' => 'carol', 'status' => 'pending'],
			], 'dave', ['invitationId' => 99, 'role' => 'viewer']);
		self::assertSame(['alice', 'dave', 'bob'], $recipients);
	}

	public function testActivityFailureDoesNotSuppressTheInvitationNotification(): void
	{
		$activities = $this->createMock(ActivityManager::class);
		$activities->method('generateEvent')->willThrowException(new \RuntimeException('Activity unavailable'));
		$notifications = $this->createMock(NotificationManager::class);
		$notifications->method('createNotification')->willReturn($this->notification());
		$notifications->expects(self::once())->method('notify');
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects(self::exactly(2))->method('warning');
		$this->service($activities, $notifications, $logger)->publish(WorkspaceSubjects::INVITED,
			['id' => 7, 'name' => 'Private', 'ownerUid' => 'alice'], 'alice', [], 'bob', ['invitationId' => 99]);
	}

	public function testCleanupOnlyProcessesTheMatchingRecipientAndInvitation(): void
	{
		$n = $this->notification();
		$n->expects(self::once())->method('setUser')->with('bob');
		$n->expects(self::once())->method('setObject')->with(WorkspaceEventService::INVITATION_OBJECT, '99');
		$notifications = $this->createMock(NotificationManager::class);
		$notifications->method('createNotification')->willReturn($n);
		$notifications->expects(self::once())->method('markProcessed')->with($n);
		$this->service($this->createMock(ActivityManager::class), $notifications)->clearInvitation(99, 'bob');
	}
}
