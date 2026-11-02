<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Service;

use OCA\InventoryCheck\Activity\WorkspaceSubjects;
use OCA\InventoryCheck\AppInfo\Application;
use OCP\Activity\IManager as ActivityManager;
use OCP\IUserManager;
use OCP\Notification\IManager as NotificationManager;
use Psr\Log\LoggerInterface;

/** Deliver committed workspace changes through Nextcloud's existing event channels. */
class WorkspaceEventService
{
	public const TYPE = 'inventorycheck_workspaces';
	public const INVITATION_OBJECT = 'workspace_invitation';

	public function __construct(
		private readonly ActivityManager $activities,
		private readonly NotificationManager $notifications,
		private readonly IUserManager $users,
		private readonly Clock $clock,
		private readonly LoggerInterface $logger,
	) {
	}

	/** Pending invitees only receive their own invitation events and workspace deletion. */
	public function publish(string $subject, array $workspace, string $actor, array $members = [], string $target = '', array $extra = []): void
	{
		$params = array_merge($extra, [
			'workspaceId' => $workspace['id'], 'workspaceName' => $workspace['name'],
			'actor' => $actor, 'actorName' => $this->displayName($actor),
			'target' => $target, 'targetName' => $this->displayName($target),
		]);
		$recipients = [$workspace['ownerUid'], $actor, $target];
		foreach ($members as $member) {
			if ($member['status'] === 'accepted' || $subject === WorkspaceSubjects::DELETED) {
				$recipients[] = $member['user_id'];
			}
		}
		foreach (array_unique(array_filter($recipients)) as $uid) {
			if (!$this->users->userExists($uid)) { continue; }
			try {
				$event = $this->activities->generateEvent();
				$event->setApp(Application::APP_ID)->setType(self::TYPE)->setAuthor($actor)
					->setAffectedUser($uid)->setSubject($subject, $params)
					->setObject('workspace', (int)$workspace['id'])->setTimestamp($this->clock->now());
				$this->activities->publish($event);
			} catch (\Throwable $e) {
				$this->logger->warning('Could not publish workspace activity', ['app' => Application::APP_ID, 'exception' => $e]);
			}
		}
		if ($subject === WorkspaceSubjects::INVITED) {
			try {
				$n = $this->notifications->createNotification();
				$n->setApp(Application::APP_ID)->setUser($target)->setDateTime(new \DateTime('@' . $this->clock->now()))
					->setObject(self::INVITATION_OBJECT, (string)$extra['invitationId'])
					->setSubject(WorkspaceSubjects::INVITED, $params);
				$this->notifications->notify($n);
			} catch (\Throwable $e) {
				$this->logger->warning('Could not deliver workspace invitation notification', ['app' => Application::APP_ID, 'exception' => $e]);
			}
		}
	}

	public function clearInvitation(int $id, string $uid): void
	{
		try {
			$n = $this->notifications->createNotification();
			$n->setApp(Application::APP_ID)->setUser($uid)->setObject(self::INVITATION_OBJECT, (string)$id);
			$this->notifications->markProcessed($n);
		} catch (\Throwable $e) {
			$this->logger->warning('Could not dismiss workspace invitation notification', ['app' => Application::APP_ID, 'exception' => $e]);
		}
	}

	private function displayName(string $uid): string
	{
		return $uid === '' ? '' : ($this->users->get($uid)?->getDisplayName() ?? $uid);
	}
}
