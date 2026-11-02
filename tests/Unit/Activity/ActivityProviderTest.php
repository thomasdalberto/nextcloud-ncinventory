<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Tests\Unit\Activity;

use OCA\InventoryCheck\Activity\Provider;
use OCA\InventoryCheck\AppInfo\Application;
use OCA\InventoryCheck\Service\LowStockNotifyService;
use OCP\Activity\IEvent;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\L10N\IFactory;
use PHPUnit\Framework\TestCase;

final class ActivityProviderTest extends TestCase
{
	public function testParsesLowStockSubject(): void
	{
		$l = $this->createMock(IL10N::class);
		$l->method('t')->willReturnCallback(static function (string $text, array $params = []): string {
			return $text . '|' . implode(',', $params);
		});
		$factory = $this->createMock(IFactory::class);
		$factory->method('get')->willReturn($l);
		$url = $this->createMock(IURLGenerator::class);
		$url->expects(self::once())->method('imagePath')->with(Application::APP_ID, 'app-dark.svg')->willReturn('/apps/inventorycheck/img/app-dark.svg');
		$url->method('getAbsoluteURL')->willReturnCallback(static fn (string $p): string => 'https://nc.test' . $p);
		$url->method('linkToRouteAbsolute')->willReturn('https://nc.test/item/7');

		$event = $this->createMock(IEvent::class);
		$event->method('getApp')->willReturn(Application::APP_ID);
		$event->method('getSubject')->willReturn(LowStockNotifyService::SUBJECT);
		$event->method('getSubjectParameters')->willReturn([
			'itemName' => 'Filter',
			'sku' => 'FILTER-42',
			'totalQty' => '2',
			'reorderLevel' => '5',
		]);
		$event->method('getObjectId')->willReturn(7);
		$event->expects(self::once())->method('setParsedSubject')->willReturnSelf();
		$event->expects(self::once())->method('setParsedMessage')->willReturnSelf();
		$event->expects(self::once())->method('setIcon')->with('https://nc.test/apps/inventorycheck/img/app-dark.svg')->willReturnSelf();
		$event->expects(self::once())->method('setLink')->willReturnSelf();

		$provider = new Provider($factory, $url);
		$out = $provider->parse('en', $event);
		self::assertSame($event, $out);
	}

	public function testRejectsForeignApp(): void
	{
		$event = $this->createMock(IEvent::class);
		$event->method('getApp')->willReturn('files');
		$provider = new Provider(
			$this->createMock(IFactory::class),
			$this->createMock(IURLGenerator::class),
		);
		$this->expectException(\Throwable::class);
		$provider->parse('en', $event);
	}
}
