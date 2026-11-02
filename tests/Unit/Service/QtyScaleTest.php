<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Tests\Unit\Service;

use OCA\InventoryCheck\AppInfo\Application;
use OCA\InventoryCheck\Exception\ValidationException;
use OCA\InventoryCheck\Service\QtyScale;
use OCA\InventoryCheck\Service\WorkspaceSettings;
use PHPUnit\Framework\TestCase;

final class QtyScaleTest extends TestCase
{
	private function config(string $scale = '0'): WorkspaceSettings
	{
		$config = $this->createMock(WorkspaceSettings::class);
		$config->method('get')->willReturnCallback(
			static function (string $key, string $default = '') use ($scale): string {
				if ($key === QtyScale::KEY) {
					return $scale;
				}
				return $default;
			},
		);
		return $config;
	}

	public function testIntScaleRejectsFractionsAndMapsInts(): void
	{
		$c = $this->config('0');
		self::assertSame(0, QtyScale::current($c));
		self::assertSame(1, QtyScale::factor($c));
		self::assertSame(12, QtyScale::toStorage($c, 12));
		self::assertSame(12, QtyScale::toStorage($c, '12'));
		self::assertSame(12, QtyScale::toDisplay($c, 12));
		$this->expectException(ValidationException::class);
		QtyScale::toStorage($c, '1.5');
	}

	public function testMilliScaleRoundTripsThreeDecimals(): void
	{
		$c = $this->config('3');
		self::assertSame(3, QtyScale::current($c));
		self::assertSame(1000, QtyScale::serialUnit($c));
		self::assertSame(1500, QtyScale::toStorage($c, '1.5'));
		self::assertSame(1001, QtyScale::toStorage($c, '1.001'));
		self::assertSame('1.5', QtyScale::toDisplay($c, 1500));
		self::assertSame('1.001', QtyScale::toDisplay($c, 1001));
		self::assertSame('2', QtyScale::toDisplay($c, 2000));
		self::assertSame('-1.25', QtyScale::toDisplay($c, -1250));
	}

	public function testMilliScaleRejectsTooManyDecimals(): void
	{
		$c = $this->config('3');
		$this->expectException(ValidationException::class);
		QtyScale::toStorage($c, '1.0001');
	}

	public function testIntScaleAcceptsDisplayMaxAndRejectsMaxPlusOne(): void
	{
		$c = $this->config('0');
		self::assertSame(QtyScale::MAX_DISPLAY, QtyScale::toStorage($c, QtyScale::MAX_DISPLAY));
		self::assertSame(QtyScale::MAX_DISPLAY, QtyScale::toStorage($c, (string)QtyScale::MAX_DISPLAY));
		self::assertSame(-QtyScale::MAX_DISPLAY, QtyScale::toStorage($c, -QtyScale::MAX_DISPLAY));
		try {
			QtyScale::toStorage($c, QtyScale::MAX_DISPLAY + 1);
			self::fail('integer 1_000_001 must be rejected at the client qty boundary');
		} catch (ValidationException $e) {
			self::assertSame('invalid_qty', $e->getErrorCode());
		}
		try {
			QtyScale::toStorage($c, (string)(QtyScale::MAX_DISPLAY + 1));
			self::fail('string 1000001 must be rejected at the client qty boundary');
		} catch (ValidationException $e) {
			self::assertSame('invalid_qty', $e->getErrorCode());
		}
	}

	public function testMilliScaleRejectsDisplayAboveOneMillion(): void
	{
		$c = $this->config('3');
		self::assertSame(QtyScale::MAX_DISPLAY * QtyScale::FACTOR, QtyScale::toStorage($c, '1000000'));
		$this->expectException(ValidationException::class);
		QtyScale::toStorage($c, '1000000.001');
	}

	public function testFormattersOnlyTouchQtyFields(): void
	{
		$c = $this->config('3');
		$item = QtyScale::formatItem(['id' => 1, 'reorderLevel' => 2500, 'name' => 'x'], $c);
		self::assertSame('2.5', $item['reorderLevel']);
		self::assertSame('x', $item['name']);

		$bal = QtyScale::formatBalance(['qty' => 1000, 'itemId' => 9], $c);
		self::assertSame('1', $bal['qty']);

		$mov = QtyScale::formatMovement(['qtyDelta' => -500, 'qtyAfter' => 1500], $c);
		self::assertSame('-0.5', $mov['qtyDelta']);
		self::assertSame('1.5', $mov['qtyAfter']);

		$low = QtyScale::formatLowStock(['totalQty' => 900, 'reorderLevel' => 1000], $c);
		self::assertSame('0.9', $low['totalQty']);
		self::assertSame('1', $low['reorderLevel']);

		$cycle = QtyScale::formatCycleLine([
			'id' => 7,
			'itemId' => 42,
			'sku' => 'FILTER-42',
			'itemName' => 'Air filter',
			'scanCode' => 'SC-42',
			'systemQty' => 2500,
			'qtyCounted' => 1500,
			'currentQty' => 2400,
		], $c);
		self::assertSame('FILTER-42', $cycle['sku']);
		self::assertSame('Air filter', $cycle['itemName']);
		self::assertSame('SC-42', $cycle['scanCode']);
		self::assertSame(7, $cycle['id']);
		self::assertSame(42, $cycle['itemId']);
		self::assertSame('2.5', $cycle['systemQty']);
		self::assertSame('1.5', $cycle['qtyCounted']);
		self::assertSame('2.4', $cycle['currentQty']);
	}

	public function testFormatMovementPreservesDisplayNameJoins(): void
	{
		$c = $this->config('3');
		$mov = QtyScale::formatMovement([
			'id' => 9,
			'itemId' => 1,
			'locationId' => 2,
			'qtyDelta' => -500,
			'qtyAfter' => 1500,
			'itemName' => 'Air filter',
			'sku' => 'FILTER-42',
			'locationCode' => 'VAN-1',
			'locationName' => 'Van front',
		], $c);
		self::assertSame('-0.5', $mov['qtyDelta']);
		self::assertSame('1.5', $mov['qtyAfter']);
		self::assertSame('Air filter', $mov['itemName']);
		self::assertSame('FILTER-42', $mov['sku']);
		self::assertSame('VAN-1', $mov['locationCode']);
		self::assertSame('Van front', $mov['locationName']);
		self::assertSame(9, $mov['id']);
	}
}
