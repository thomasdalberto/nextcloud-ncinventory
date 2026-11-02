<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Tests\Unit\Public;

use OCA\InventoryCheck\Db\Item;
use OCA\InventoryCheck\Db\ItemMapper;
use OCA\InventoryCheck\Db\Location;
use OCA\InventoryCheck\Db\LocationMapper;
use OCA\InventoryCheck\Db\Movement;
use OCA\InventoryCheck\Db\MovementMapper;
use OCA\InventoryCheck\Exception\InsufficientStockException;
use OCA\InventoryCheck\Public\StockIssueFacade;
use OCA\InventoryCheck\Public\StockIssueRequest;
use OCA\InventoryCheck\Service\MovementService;
use OCA\InventoryCheck\Service\WorkspaceSettings;
use OCP\IDBConnection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * CHECK-SUITE FC-IV-ISSUE / AC-FC4.
 */
class StockIssueFacadeTest extends TestCase
{
	private IDBConnection&MockObject $db;
	private MovementService&MockObject $movements;
	private MovementMapper&MockObject $movementMapper;
	private ItemMapper&MockObject $items;
	private LocationMapper&MockObject $locations;
	private WorkspaceSettings&MockObject $config;
	private StockIssueFacade $facade;

	protected function setUp(): void
	{
		$this->db = $this->createMock(IDBConnection::class);
		$this->movements = $this->createMock(MovementService::class);
		$this->movementMapper = $this->createMock(MovementMapper::class);
		$this->items = $this->createMock(ItemMapper::class);
		$this->locations = $this->createMock(LocationMapper::class);
		$this->locations->method('search')->willReturn(['data' => [], 'total' => 0]);
		$this->config = $this->createMock(WorkspaceSettings::class);
		$this->config->method('get')->willReturnCallback(
			static function (string $key, string $default = ''): string {
				if ($key === \OCA\InventoryCheck\Service\FlangeService::KEY_MAINT_ENABLED
					|| $key === \OCA\InventoryCheck\Service\FlangeService::KEY_PROJECT_ENABLED) {
					return '1';
				}
				return $default;
			},
		);
		$this->facade = new StockIssueFacade(
			$this->db,
			$this->movements,
			$this->movementMapper,
			$this->items,
			$this->locations,
			$this->config,
		);
	}

	public function testFractionalScaleConvertsDisplayQtyToStorage(): void
	{
		$config = $this->createMock(WorkspaceSettings::class);
		$config->method('get')->willReturnCallback(
			static function (string $key, string $default = ''): string {
				if ($key === \OCA\InventoryCheck\Service\QtyScale::KEY) {
					return '3';
				}
				if ($key === \OCA\InventoryCheck\Service\FlangeService::KEY_MAINT_ENABLED
					|| $key === \OCA\InventoryCheck\Service\FlangeService::KEY_PROJECT_ENABLED) {
					return '1';
				}
				return $default;
			},
		);
		$facade = new StockIssueFacade(
			$this->db,
			$this->movements,
			$this->movementMapper,
			$this->items,
			$this->locations,
			$config,
		);

		$item = new Item();
		$item->setId(10);
		$item->setSku('FILTER-42');
		$item->setActive(true);
		$this->items->method('findBySku')->willReturn($item);
		$this->movementMapper->method('findByRef')->willReturn([]);
		$this->movementMapper->method('findByRefAndItemId')->willReturn(null);
		$loc = new Location();
		$loc->setId(3);
		$loc->setActive(true);
		$this->locations->method('findById')->willReturn($loc);
		$this->db->method('beginTransaction');
		$this->db->method('commit');

		// Display qty 2 → storage 2000 under scale=3 (not raw 2 milli-units).
		$this->movements->expects($this->once())
			->method('issueWithRef')
			->with('tech', 10, 3, 2000, 'Maint WO #9001', StockIssueRequest::REF_MAINT_WO, 9001, false)
			->willReturn(['movements' => [['id' => 555]], 'balances' => []]);
		$this->movements->expects($this->once())
			->method('notifyLowStockAfterChange')
			->with(10);

		$result = $facade->issueBySkuBundle(new StockIssueRequest(
			actorUid: 'tech',
			lines: [['sku' => 'FILTER-42', 'qty' => 2]],
			locationPolicy: StockIssueRequest::POLICY_EXPLICIT,
			refType: StockIssueRequest::REF_MAINT_WO,
			refId: 9001,
			locationId: 3,
		));
		$this->assertTrue($result->ok);
		// Response qty stays display ('2'), not storage milli-units (2000).
		$this->assertSame('2', $result->data['movements'][0]['qty']);
	}

	public function testLocationUnresolved(): void
	{
		$result = $this->facade->issueBySkuBundle(new StockIssueRequest(
			actorUid: 'tech',
			lines: [['sku' => 'FILTER-42', 'qty' => 2]],
			locationPolicy: StockIssueRequest::POLICY_FAIL_AMBIGUOUS,
			refType: StockIssueRequest::REF_MAINT_WO,
			refId: 9001,
		));
		$this->assertFalse($result->ok);
		$this->assertSame('location_unresolved', $result->code);
	}

	public function testFailIfAmbiguousUsesSoleActiveLocation(): void
	{
		$loc = new Location();
		$loc->setId(7);
		$loc->setActive(true);
		$this->locations = $this->createMock(LocationMapper::class);
		$this->locations->method('search')->willReturn(['data' => [$loc], 'total' => 1]);
		$this->locations->method('findById')->with(7)->willReturn($loc);
		$facade = new StockIssueFacade(
			$this->db,
			$this->movements,
			$this->movementMapper,
			$this->items,
			$this->locations,
			$this->config,
		);

		$item = new Item();
		$item->setId(10);
		$item->setSku('FILTER-42');
		$item->setActive(true);
		$this->items->method('findBySku')->willReturn($item);
		$this->movementMapper->method('findByRef')->willReturn([]);
		$this->movementMapper->method('findByRefAndItemId')->willReturn(null);
		$this->db->method('beginTransaction');
		$this->db->method('commit');
		$this->movements->expects($this->once())
			->method('issueWithRef')
			->with('tech', 10, 7, 1, 'Maint WO #9001', StockIssueRequest::REF_MAINT_WO, 9001, false)
			->willReturn(['movements' => [['id' => 1]], 'balances' => []]);
		$this->movements->method('notifyLowStockAfterChange');

		$result = $facade->issueBySkuBundle(new StockIssueRequest(
			actorUid: 'tech',
			lines: [['sku' => 'FILTER-42', 'qty' => 1]],
			locationPolicy: StockIssueRequest::POLICY_FAIL_AMBIGUOUS,
			refType: StockIssueRequest::REF_MAINT_WO,
			refId: 9001,
		));
		$this->assertTrue($result->ok);
	}

	public function testMaintFlangeDisabledFailsClosed(): void
	{
		$config = $this->createMock(WorkspaceSettings::class);
		$config->method('get')->willReturn('0');
		$facade = new StockIssueFacade(
			$this->db,
			$this->movements,
			$this->movementMapper,
			$this->items,
			$this->locations,
			$config,
		);
		$result = $facade->issueBySkuBundle(new StockIssueRequest(
			actorUid: 'tech',
			lines: [['sku' => 'FILTER-42', 'qty' => 1]],
			locationPolicy: StockIssueRequest::POLICY_EXPLICIT,
			refType: StockIssueRequest::REF_MAINT_WO,
			refId: 1,
			locationId: 3,
		));
		$this->assertFalse($result->ok);
		$this->assertSame('flange_disabled', $result->code);
	}

	public function testRejectsUnsupportedRefType(): void
	{
		$result = $this->facade->issueBySkuBundle(new StockIssueRequest(
			actorUid: 'tech',
			lines: [['sku' => 'FILTER-42', 'qty' => 1]],
			locationPolicy: StockIssueRequest::POLICY_EXPLICIT,
			refType: 'purchase_order',
			refId: 1,
			locationId: 3,
		));
		$this->assertFalse($result->ok);
		$this->assertSame('validation_failed', $result->code);
	}

	public function testRejectsEmptyActorUid(): void
	{
		$result = $this->facade->issueBySkuBundle(new StockIssueRequest(
			actorUid: '   ',
			lines: [['sku' => 'FILTER-42', 'qty' => 1]],
			locationPolicy: StockIssueRequest::POLICY_EXPLICIT,
			refType: StockIssueRequest::REF_MAINT_WO,
			refId: 1,
			locationId: 3,
		));
		$this->assertFalse($result->ok);
		$this->assertSame('validation_failed', $result->code);
		$this->assertStringContainsString('actorUid', (string)$result->message);
	}

	public function testRejectsZeroDisplayQty(): void
	{
		$result = $this->facade->issueBySkuBundle(new StockIssueRequest(
			actorUid: 'tech',
			lines: [['sku' => 'FILTER-42', 'qty' => 0]],
			locationPolicy: StockIssueRequest::POLICY_EXPLICIT,
			refType: StockIssueRequest::REF_MAINT_WO,
			refId: 1,
			locationId: 3,
		));
		$this->assertFalse($result->ok);
		$this->assertSame('validation_failed', $result->code);
	}

	/**
	 * C5: project flange reuses the same all-or-nothing/idempotent path as
	 * maint_wo, just with a different ref type and reason string.
	 */
	public function testProjectRefTypeIssuesWithProjectReason(): void
	{
		$item = new Item();
		$item->setId(10);
		$item->setSku('FILTER-42');
		$item->setActive(true);
		$this->items->method('findBySku')->willReturn($item);
		$this->movementMapper->method('findByRef')->willReturn([]);
		$this->movementMapper->method('findByRefAndItemId')->willReturn(null);

		$loc = new Location();
		$loc->setId(3);
		$loc->setActive(true);
		$this->locations->method('findById')->willReturn($loc);

		$this->db->expects($this->once())->method('beginTransaction');
		$this->db->expects($this->once())->method('commit');
		$this->movements->expects($this->once())
			->method('issueWithRef')
			->with(
				'tech',
				10,
				3,
				2,
				'Project #4200',
				StockIssueRequest::REF_PROJECT,
				4200,
				false,
			)
			->willReturn(['movements' => [['id' => 777]], 'balances' => []]);
		$this->movements->expects($this->once())
			->method('notifyLowStockAfterChange')
			->with(10);

		$result = $this->facade->issueBySkuBundle(new StockIssueRequest(
			actorUid: 'tech',
			lines: [['sku' => 'FILTER-42', 'qty' => 2]],
			locationPolicy: StockIssueRequest::POLICY_EXPLICIT,
			refType: StockIssueRequest::REF_PROJECT,
			refId: 4200,
			locationId: 3,
		));

		$this->assertTrue($result->ok);
		$this->assertSame(777, $result->data['movements'][0]['movementId']);
	}

	public function testUnknownSku(): void
	{
		$this->items->method('findBySku')->willReturn(null);
		$result = $this->facade->issueBySkuBundle(new StockIssueRequest(
			actorUid: 'tech',
			lines: [['sku' => 'NOPE', 'qty' => 1]],
			locationPolicy: StockIssueRequest::POLICY_EXPLICIT,
			refType: StockIssueRequest::REF_MAINT_WO,
			refId: 1,
			locationId: 3,
		));
		$this->assertFalse($result->ok);
		$this->assertSame('not_found', $result->code);
	}

	public function testSuccessAllOrNothing(): void
	{
		$item = new Item();
		$item->setId(10);
		$item->setSku('FILTER-42');
		$item->setActive(true);
		$this->items->method('findBySku')->willReturn($item);
		$this->movementMapper->method('findByRef')->willReturn([]);
		$this->movementMapper->method('findByRefAndItemId')->willReturn(null);

		$loc = new Location();
		$loc->setId(3);
		$loc->setActive(true);
		$this->locations->method('findById')->willReturn($loc);

		$this->db->expects($this->once())->method('beginTransaction');
		$this->db->expects($this->once())->method('commit');
		$this->movements->expects($this->once())
			->method('issueWithRef')
			->with(
				'tech',
				10,
				3,
				2,
				'Maint WO #9001',
				StockIssueRequest::REF_MAINT_WO,
				9001,
				false,
			)
			->willReturn(['movements' => [['id' => 555]], 'balances' => []]);
		$this->movements->expects($this->once())
			->method('notifyLowStockAfterChange')
			->with(10);

		$result = $this->facade->issueBySkuBundle(new StockIssueRequest(
			actorUid: 'tech',
			lines: [['sku' => 'FILTER-42', 'qty' => 2]],
			locationPolicy: StockIssueRequest::POLICY_EXPLICIT,
			refType: StockIssueRequest::REF_MAINT_WO,
			refId: 9001,
			locationId: 3,
		));

		$this->assertTrue($result->ok);
		$this->assertSame(555, $result->data['movements'][0]['movementId']);
	}

	public function testInsufficientStockRollsBack(): void
	{
		$item = new Item();
		$item->setId(10);
		$item->setSku('FILTER-42');
		$item->setActive(true);
		$this->items->method('findBySku')->willReturn($item);
		$this->movementMapper->method('findByRef')->willReturn([]);
		$this->movementMapper->method('findByRefAndItemId')->willReturn(null);
		$loc = new Location();
		$loc->setId(3);
		$loc->setActive(true);
		$this->locations->method('findById')->willReturn($loc);

		$this->db->method('inTransaction')->willReturn(true);
		$this->db->expects($this->once())->method('beginTransaction');
		$this->db->expects($this->once())->method('rollBack');
		$this->movements->method('issueWithRef')->willThrowException(new InsufficientStockException(0));
		$this->movements->expects($this->never())->method('notifyLowStockAfterChange');

		$result = $this->facade->issueBySkuBundle(new StockIssueRequest(
			actorUid: 'tech',
			lines: [['sku' => 'FILTER-42', 'qty' => 99]],
			locationPolicy: StockIssueRequest::POLICY_EXPLICIT,
			refType: StockIssueRequest::REF_MAINT_WO,
			refId: 1,
			locationId: 3,
		));

		$this->assertFalse($result->ok);
		$this->assertSame('insufficient_stock', $result->code);
		$this->assertSame('FILTER-42', $result->data['sku'] ?? null);
	}

	/**
	 * Multi-line bundle: blame the SKU that actually failed, not the first line.
	 */
	public function testInsufficientStockReportsFailingSkuNotFirstLine(): void
	{
		$filter = new Item();
		$filter->setId(10);
		$filter->setSku('FILTER-42');
		$filter->setActive(true);
		$bolt = new Item();
		$bolt->setId(11);
		$bolt->setSku('BOLT-9');
		$bolt->setActive(true);
		$this->items->method('findBySku')->willReturnCallback(
			static function (string $sku) use ($filter, $bolt): ?Item {
				return match ($sku) {
					'FILTER-42' => $filter,
					'BOLT-9' => $bolt,
					default => null,
				};
			},
		);
		$this->movementMapper->method('findByRef')->willReturn([]);
		$this->movementMapper->method('findByRefAndItemId')->willReturn(null);
		$loc = new Location();
		$loc->setId(3);
		$loc->setActive(true);
		$this->locations->method('findById')->willReturn($loc);

		$this->db->method('inTransaction')->willReturn(true);
		$this->db->expects($this->once())->method('beginTransaction');
		$this->db->expects($this->once())->method('rollBack');
		$this->movements->method('issueWithRef')->willReturnCallback(
			static function (
				string $actor,
				int $itemId,
			): array {
				if ($itemId === 11) {
					throw new InsufficientStockException(0);
				}
				return ['movements' => [['id' => 1]], 'balances' => []];
			},
		);
		$this->movements->expects($this->never())->method('notifyLowStockAfterChange');

		$result = $this->facade->issueBySkuBundle(new StockIssueRequest(
			actorUid: 'tech',
			lines: [
				['sku' => 'FILTER-42', 'qty' => 1],
				['sku' => 'BOLT-9', 'qty' => 5],
			],
			locationPolicy: StockIssueRequest::POLICY_EXPLICIT,
			refType: StockIssueRequest::REF_MAINT_WO,
			refId: 42,
			locationId: 3,
		));

		$this->assertFalse($result->ok);
		$this->assertSame('insufficient_stock', $result->code);
		$this->assertSame('BOLT-9', $result->data['sku'] ?? null);
	}

	public function testIdempotentReplay(): void
	{
		$item = new Item();
		$item->setId(10);
		$item->setSku('FILTER-42');
		$item->setActive(true);
		$this->items->method('findBySku')->willReturn($item);
		$this->items->method('findById')->willReturn($item);

		$mov = new Movement();
		$mov->setId(555);
		$mov->setItemId(10);
		$mov->setLocationId(3);
		$this->movementMapper->method('findByRef')->willReturn([$mov]);

		$this->movements->expects($this->never())->method('issueWithRef');

		$result = $this->facade->issueBySkuBundle(new StockIssueRequest(
			actorUid: 'tech',
			lines: [['sku' => 'FILTER-42', 'qty' => 2]],
			locationPolicy: StockIssueRequest::POLICY_EXPLICIT,
			refType: StockIssueRequest::REF_MAINT_WO,
			refId: 9001,
			locationId: 3,
		));

		$this->assertTrue($result->ok);
		$this->assertSame('idempotent_replay', $result->code);
	}

	public function testUniqueConstraintRaceReplaysAsIdempotent(): void
	{
		$item = new Item();
		$item->setId(10);
		$item->setSku('FILTER-42');
		$item->setActive(true);
		$this->items->method('findBySku')->willReturn($item);
		$this->items->method('findById')->willReturn($item);
		$this->movementMapper->method('findByRef')->willReturnOnConsecutiveCalls([], [
			(static function (): Movement {
				$mov = new Movement();
				$mov->setId(777);
				$mov->setItemId(10);
				$mov->setLocationId(3);
				$mov->setQtyDelta(-2);
				return $mov;
			})(),
		]);
		$this->movementMapper->method('findByRefAndItemId')->willReturn(null);

		$loc = new Location();
		$loc->setId(3);
		$loc->setActive(true);
		$this->locations->method('findById')->willReturn($loc);

		$this->db->method('beginTransaction');
		$this->db->method('inTransaction')->willReturn(true);
		$this->db->expects($this->once())->method('rollBack');

		$ex = $this->createMock(\OCP\DB\Exception::class);
		$ex->method('getReason')->willReturn(\OCP\DB\Exception::REASON_UNIQUE_CONSTRAINT_VIOLATION);
		$this->movements->method('issueWithRef')->willThrowException($ex);

		$result = $this->facade->issueBySkuBundle(new StockIssueRequest(
			actorUid: 'tech',
			lines: [['sku' => 'FILTER-42', 'qty' => 2]],
			locationPolicy: StockIssueRequest::POLICY_EXPLICIT,
			refType: StockIssueRequest::REF_MAINT_WO,
			refId: 9001,
			locationId: 3,
		));

		$this->assertTrue($result->ok);
		$this->assertSame('idempotent_replay', $result->code);
		$this->assertSame(777, $result->data['movements'][0]['movementId'] ?? null);
	}

	/**
	 * Multi-SKU posts must lock items in ascending id order (ABBA-safe vs inventur).
	 */
	public function testMultiSkuPostsInAscendingItemIdOrder(): void
	{
		$low = new Item();
		$low->setId(5);
		$low->setSku('AAA');
		$low->setActive(true);
		$high = new Item();
		$high->setId(50);
		$high->setSku('ZZZ');
		$high->setActive(true);
		$this->items->method('findBySku')->willReturnCallback(
			static fn (string $sku): ?Item => match ($sku) {
				'ZZZ' => $high,
				'AAA' => $low,
				default => null,
			},
		);
		$this->movementMapper->method('findByRef')->willReturn([]);
		$this->movementMapper->method('findByRefAndItemId')->willReturn(null);
		$loc = new Location();
		$loc->setId(3);
		$loc->setActive(true);
		$this->locations->method('findById')->willReturn($loc);
		$this->db->method('beginTransaction');
		$this->db->method('commit');
		$this->db->method('inTransaction')->willReturn(false);

		$seen = [];
		$this->movements->method('issueWithRef')->willReturnCallback(
			static function (string $actor, int $itemId) use (&$seen): array {
				$seen[] = $itemId;
				return ['movements' => [['id' => $itemId]], 'balances' => []];
			},
		);

		$result = $this->facade->issueBySkuBundle(new StockIssueRequest(
			actorUid: 'tech',
			// Deliberately reverse of item id order
			lines: [
				['sku' => 'ZZZ', 'qty' => 1],
				['sku' => 'AAA', 'qty' => 1],
			],
			locationPolicy: StockIssueRequest::POLICY_EXPLICIT,
			refType: StockIssueRequest::REF_MAINT_WO,
			refId: 77,
			locationId: 3,
		));

		$this->assertTrue($result->ok);
		$this->assertSame([5, 50], $seen, 'must post lowest itemId first regardless of SKU order');
	}
}
