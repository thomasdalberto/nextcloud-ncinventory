<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Listener;

use OCA\InventoryCheck\Db\LocationFavouriteMapper;
use OCA\InventoryCheck\Service\LocationAclService;
use OCA\InventoryCheck\Service\WorkspaceService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\User\Events\UserDeletedEvent;

/**
 * GDPR / user-delete: scrub live authorization, seats, favourites, and
 * per-user location ACL grants. Ledger rows stay (audit); only live grants go.
 *
 * @template-implements IEventListener<UserDeletedEvent>
 */
class UserDeletedListener implements IEventListener
{
	public function __construct(
		private LocationFavouriteMapper $favourites,
		private LocationAclService $locationAcl,
		private WorkspaceService $workspaces,
	) {
	}

	public function handle(Event $event): void
	{
		if (!$event instanceof UserDeletedEvent) {
			return;
		}
		$uid = $event->getUser()->getUID();
		$this->workspaces->purgeUser($uid);
		$this->favourites->deleteAllForUser($uid);
		$this->locationAcl->purgeUser($uid);
	}
}
