<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Tests\Unit\Service;

use OCA\InventoryCheck\Db\BalanceMapper;
use OCA\InventoryCheck\Db\Item;
use OCA\InventoryCheck\Db\ItemMapper;
use OCA\InventoryCheck\Db\NotifyLog;
use OCA\InventoryCheck\Db\NotifyLogMapper;
use OCA\InventoryCheck\Service\AccessControlService;
use OCA\InventoryCheck\Service\Clock;
use OCA\InventoryCheck\Service\LowStockNotifyService;
use OCP\Activity\IEvent;
use OCP\Activity\IManager as IActivityManager;
use OCP\IConfig;
use OCP\IGroupManager;
use OCP\IURLGenerator;
use OCP\IUserManager;
use OCP\Notification\IManager as INotificationManager;
use OCP\Notification\INotification;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class LowStockNotifyServiceTest extends TestCase
{
	public function testSkipsWhenReorderZero(): void
	{
		$item = new Item();
		$item->setId(7);
		$item->setActive(true);
		$item->setReorderLevel(0);
		$item->setName('X');
		$item->setSku('X');

		$items = $this->createMock(ItemMapper::class);
		$items->method('findById')->willReturn($item);
		$balances = $this->createMock(BalanceMapper::class);
		$balances->method('sumQtyByItem')->willReturn([7 => 0]);
		$log = $this->createMock(NotifyLogMapper::class);
		$log->expects(self::once())->method('deleteByDedupeKey')
			->with(LowStockNotifyService::openEpisodeKey(7));
		$log->expects(self::never())->method('tryInsert');

		$svc = $this->makeService($items, $balances, $log);
		$svc->afterStockChange(7);
	}

	public function testNewlyEntersFiresOnceThenQuietWhileStillLow(): void
	{
		$item = new Item();
		$item->setId(7);
		$item->setActive(true);
		$item->setReorderLevel(5);
		$item->setName('Filter');
		$item->setSku('FILTER-42');

		$items = $this->createMock(ItemMapper::class);
		$items->method('findById')->willReturn($item);
		$balances = $this->createMock(BalanceMapper::class);
		$balances->method('sumQtyByItem')->willReturn([7 => 2]);

		$openExists = false;
		$log = $this->createMock(NotifyLogMapper::class);
		$log->method('findByDedupeKey')->willReturnCallback(
			static function (string $key) use (&$openExists): ?NotifyLog {
				if ($key === LowStockNotifyService::openEpisodeKey(7) && $openExists) {
					$row = new NotifyLog();
					$row->setDedupeKey($key);
					return $row;
				}
				return null;
			},
		);
		$log->method('findRecentForItem')->willReturn(null);
		$log->expects(self::exactly(2))->method('tryInsert')->willReturnCallback(
			static function (string $key) use (&$openExists): bool {
				if ($key === LowStockNotifyService::openEpisodeKey(7)) {
					if ($openExists) {
						return false;
					}
					$openExists = true;
					return true;
				}
				return true;
			},
		);

		$notification = $this->createMock(INotification::class);
		$notification->method('setApp')->willReturnSelf();
		$notification->method('setUser')->willReturnSelf();
		$notification->method('setDateTime')->willReturnSelf();
		$notification->method('setObject')->willReturnSelf();
		$notification->method('setSubject')->willReturnSelf();
		$notification->method('setLink')->willReturnSelf();
		$notifications = $this->createMock(INotificationManager::class);
		$notifications->method('createNotification')->willReturn($notification);
		$notifications->expects(self::once())->method('notify');

		$event = $this->createMock(IEvent::class);
		$event->method('setApp')->willReturnSelf();
		$event->method('setType')->willReturnSelf();
		$event->method('setAuthor')->willReturnSelf();
		$event->method('setAffectedUser')->willReturnSelf();
		$event->method('setSubject')->willReturnSelf();
		$event->method('setObject')->willReturnSelf();
		$event->method('setTimestamp')->willReturnSelf();
		$activity = $this->createMock(IActivityManager::class);
		$activity->method('generateEvent')->willReturn($event);
		$activity->expects(self::once())->method('publish');

		$access = $this->createMock(AccessControlService::class);
		$users = $this->createMock(IUserManager::class);
		$users->method('userExists')->willReturn(true);

		$svc = $this->makeService(
			$items,
			$balances,
			$log,
			$access,
			$users,
			$notifications,
			$activity,
		);
		$svc->afterStockChange(7);
		// Second call while still low: open episode exists → no second notify.
		$svc->afterStockChange(7);
	}

	public function testRecoveryClearsEpisode(): void
	{
		$item = new Item();
		$item->setId(9);
		$item->setActive(true);
		$item->setReorderLevel(5);
		$item->setName('Seal');
		$item->setSku('SEAL');

		$items = $this->createMock(ItemMapper::class);
		$items->method('findById')->willReturn($item);
		$balances = $this->createMock(BalanceMapper::class);
		$balances->method('sumQtyByItem')->willReturn([9 => 20]);
		$log = $this->createMock(NotifyLogMapper::class);
		$log->expects(self::once())->method('deleteByDedupeKey')
			->with(LowStockNotifyService::openEpisodeKey(9));
		$log->expects(self::never())->method('tryInsert');

		$svc = $this->makeService($items, $balances, $log);
		$svc->afterStockChange(9);
	}

	private function makeService(
		ItemMapper $items,
		BalanceMapper $balances,
		NotifyLogMapper $log,
		?AccessControlService $access = null,
		?IUserManager $users = null,
		?INotificationManager $notifications = null,
		?IActivityManager $activity = null,
	): LowStockNotifyService {
		$clock = $this->createMock(Clock::class);
		$clock->method('now')->willReturn(1_700_000_000);
		$url = $this->createMock(IURLGenerator::class);
		$url->method('linkToRouteAbsolute')->willReturn('https://example.test/item/7');
		$workspace = $this->createMock(\OCA\InventoryCheck\Service\WorkspaceContext::class);
		$workspace->method('canRead')->willReturn(true);
		$settings = $this->createMock(\OCA\InventoryCheck\Service\WorkspaceSettings::class);
		$settings->method('getList')->willReturn(['alice']);
		$settings->method('get')->willReturn('0');
		return new LowStockNotifyService(
			$items,
			$balances,
			$log,
			$access ?? $this->createMock(AccessControlService::class),
			$clock,
			$settings,
			$users ?? $this->createMock(IUserManager::class),
			$notifications ?? $this->createMock(INotificationManager::class),
			$activity ?? $this->createMock(IActivityManager::class),
			$url,
			$this->createMock(LoggerInterface::class),
		$workspace, $this->createMock(\OCA\InventoryCheck\Db\WorkspaceMapper::class));
	}
}
