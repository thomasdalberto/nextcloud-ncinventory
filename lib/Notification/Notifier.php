<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Notification;

use OCA\InventoryCheck\AppInfo\Application;
use OCA\InventoryCheck\Activity\WorkspaceSubjects;
use OCA\InventoryCheck\Db\WorkspaceMapper;
use OCA\InventoryCheck\Service\LowStockNotifyService;
use OCA\InventoryCheck\Service\WorkspaceEventService;
use OCA\InventoryCheck\Service\WorkspaceInvitationLinks;
use OCP\IURLGenerator;
use OCP\L10N\IFactory;
use OCP\Notification\INotification;
use OCP\Notification\AlreadyProcessedException;
use OCP\Notification\IAction;
use OCP\Notification\INotifier;
use OCP\Notification\UnknownNotificationException;

final class Notifier implements INotifier
{
	public function __construct(
		private readonly IFactory $l10nFactory,
		private readonly IURLGenerator $url,
		private readonly WorkspaceMapper $workspaces,
		private readonly WorkspaceInvitationLinks $links,
	) {
	}

	public function getID(): string
	{
		return Application::APP_ID;
	}

	public function getName(): string
	{
		return 'InventoryCheck';
	}

	public function prepare(INotification $notification, string $languageCode): INotification
	{
		if ($notification->getApp() !== Application::APP_ID) {
			throw new UnknownNotificationException();
		}
		$l = $this->l10nFactory->get(Application::APP_ID, $languageCode !== '' ? $languageCode : null);
		$subject = $notification->getSubject();
		/** @var array<string, mixed> $p */
		$p = $notification->getSubjectParameters();
		if ($subject === WorkspaceSubjects::INVITED) {
			$id = (int)($p['workspaceId'] ?? 0);
			$invitationId = (int)$notification->getObjectId();
			if ($notification->getObjectType() !== WorkspaceEventService::INVITATION_OBJECT
				|| $this->workspaces->pendingInvitation($notification->getUser(), $id, $invitationId) === null) {
				throw new AlreadyProcessedException();
			}
			$notification->setParsedSubject(WorkspaceSubjects::render($l, $subject, $p));
			$notification->setParsedMessage(WorkspaceSubjects::invitationMessage($l, (string)($p['role'] ?? '')));
			$notification->setIcon($this->url->getAbsoluteURL($this->url->imagePath(Application::APP_ID, 'app-dark.svg')));
			$notification->setLink($this->links->link($notification->getUser(), $id, $invitationId, 'view'));
			foreach (['accept' => $l->t('Accept invitation'), 'decline' => $l->t('Decline invitation')] as $decision => $label) {
				$action = $notification->createAction();
				// WEB actions work in email too; the signed landing page submits the response immediately.
				$link = $this->links->link($notification->getUser(), $id, $invitationId, $decision);
				$action->setParsedLabel($label)->setPrimary($decision === 'accept')->setLink($link, IAction::TYPE_WEB);
				$notification->addParsedAction($action);
			}
			return $notification;
		}

		if ($subject !== LowStockNotifyService::SUBJECT) {
			throw new UnknownNotificationException();
		}

		$name = (string)($p['itemName'] ?? '');
		$sku = (string)($p['sku'] ?? '');
		$total = (string)($p['totalQty'] ?? '');
		$reorder = (string)($p['reorderLevel'] ?? '');

		$notification->setParsedSubject($l->t('Low stock: %s', [$name !== '' ? $name : $sku]));
		$notification->setParsedMessage($l->t(
			'%1$s (%2$s) is below reorder level: %3$s on hand, reorder at %4$s.',
			[$name, $sku, $total, $reorder],
		));
		$objectId = $notification->getObjectId();
		if (ctype_digit($objectId)) {
			$notification->setLink($this->url->linkToRouteAbsolute('inventorycheck.page.item',
				array_filter(['id' => (int)$objectId, 'workspaceId' => $p['workspaceId'] ?? null])));
		}
		return $notification;
	}
}
