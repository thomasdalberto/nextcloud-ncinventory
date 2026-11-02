<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Listener;

use OCA\InventoryCheck\Service\LocationAclService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Group\Events\GroupDeletedEvent;

/**
 * Group-delete: scrub live authorization — location ACL grants and every
 * gid-bearing config list. Without this, a deleted group's grants silently
 * re-activate if the same gid is recreated (stale-grant resurrection).
 *
 * @template-implements IEventListener<GroupDeletedEvent>
 */
class GroupDeletedListener implements IEventListener
{
	public function __construct(
		private LocationAclService $locationAcl,
	) {
	}

	public function handle(Event $event): void
	{
		if (!$event instanceof GroupDeletedEvent) {
			return;
		}
		$gid = $event->getGroup()->getGID();
		$this->locationAcl->purgeGroup($gid);
	}
}
