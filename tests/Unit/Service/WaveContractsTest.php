<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Tests\Unit\Service;

use OCA\InventoryCheck\Exception\ValidationException;
use OCA\InventoryCheck\Service\CsvImportService;
use OCA\InventoryCheck\Service\CycleCountSemantics;
use OCA\InventoryCheck\Service\CycleCountService;
use OCA\InventoryCheck\Service\ItemPhotoService;
use OCA\InventoryCheck\Service\LocationFavouriteService;
use OCA\InventoryCheck\Service\LowStockNotifyService;
use OCA\InventoryCheck\Service\LowStockQuery;
use PHPUnit\Framework\TestCase;

/**
 * Pure / contract unit tests for Wave A–B invariants (no DB).
 */
final class WaveContractsTest extends TestCase
{
	public function testLowStockNeverFiresWhenReorderZero(): void
	{
		self::assertFalse(LowStockQuery::isLowStock(true, 0, 0));
		self::assertFalse(LowStockQuery::isLowStock(true, 0, 100));
		// Negative totals must still not flag when reorder_level is 0
		// (otherwise removing the reorder<=0 guard would silently light up).
		self::assertFalse(LowStockQuery::isLowStock(true, 0, -1));
	}

	public function testLowStockStrictBelowReorder(): void
	{
		self::assertTrue(LowStockQuery::isLowStock(true, 5, 4));
		self::assertFalse(LowStockQuery::isLowStock(true, 5, 5));
		self::assertFalse(LowStockQuery::isLowStock(false, 5, 0));
	}

	public function testNotifyDebounceConstantIs24h(): void
	{
		self::assertSame(86400, LowStockNotifyService::DEBOUNCE_SECONDS);
		self::assertSame('lowstock:open:42', LowStockNotifyService::openEpisodeKey(42));
		$src = (string)file_get_contents(dirname(__DIR__, 3) . '/lib/Service/LowStockNotifyService.php');
		self::assertStringContainsString('deleteByDedupeKey($openKey)', $src);
		self::assertStringContainsString('findByDedupeKey($openKey)', $src);
		self::assertStringContainsString('tryInsert($openKey, $itemId, $now)', $src);
	}

	public function testPhotoMaxBytesIs2MiB(): void
	{
		self::assertSame(2_097_152, ItemPhotoService::MAX_BYTES);
		self::assertArrayHasKey('image/jpeg', ItemPhotoService::ALLOWED);
		self::assertArrayHasKey('image/png', ItemPhotoService::ALLOWED);
		self::assertArrayHasKey('image/webp', ItemPhotoService::ALLOWED);
		self::assertArrayNotHasKey('image/svg+xml', ItemPhotoService::ALLOWED);
	}

	public function testFavouriteCapIs20(): void
	{
		self::assertSame(20, LocationFavouriteService::MAX);
	}

	public function testCycleCountStatusMachineConstants(): void
	{
		self::assertSame('open', CycleCountService::STATUS_OPEN);
		self::assertSame('counting', CycleCountService::STATUS_COUNTING);
		self::assertSame('closed', CycleCountService::STATUS_CLOSED);
	}

	public function testCycleCountSemanticsGuards(): void
	{
		self::assertTrue(CycleCountSemantics::canStart('open'));
		self::assertFalse(CycleCountSemantics::canStart('counting'));
		self::assertTrue(CycleCountSemantics::canCountOrClose('counting'));
		self::assertFalse(CycleCountSemantics::canCountOrClose('open'));
		self::assertTrue(CycleCountSemantics::isIncomplete(null, false));
		self::assertFalse(CycleCountSemantics::isIncomplete(null, true));
		self::assertFalse(CycleCountSemantics::isIncomplete(0, false));
		self::assertTrue(CycleCountSemantics::isQtyCountedValid(0));
		self::assertTrue(CycleCountSemantics::isQtyCountedValid(1_000_000));
		self::assertTrue(CycleCountSemantics::isQtyCountedValid(1_000_000_000));
		self::assertFalse(CycleCountSemantics::isQtyCountedValid(-1));
		self::assertFalse(CycleCountSemantics::isQtyCountedValid(1_000_000_001));
		self::assertTrue(CycleCountSemantics::hasConflict(10, 12));
		self::assertFalse(CycleCountSemantics::hasConflict(10, 10));
		self::assertTrue(CycleCountSemantics::closeBlockedByConflicts(true, false));
		self::assertFalse(CycleCountSemantics::closeBlockedByConflicts(true, true));
		self::assertFalse(CycleCountSemantics::closeBlockedByConflicts(false, false));
		self::assertTrue(CycleCountSemantics::isInventurEligible('none'));
		self::assertFalse(CycleCountSemantics::isInventurEligible('lot'));
		self::assertFalse(CycleCountSemantics::isInventurEligible('serial'));
	}

	public function testImportMaxRowsMatchesPlanN1(): void
	{
		self::assertSame(2000, \OCA\InventoryCheck\Util\Csv::MAX_IMPORT_ROWS);
		self::assertSame(50000, \OCA\InventoryCheck\Util\Csv::MAX_EXPORT_ROWS);
	}

	public function testImportServiceClassExists(): void
	{
		self::assertTrue(class_exists(CsvImportService::class));
	}

	public function testLocationAclDeviceActorsFailClosedWhenUnbound(): void
	{
		$src = (string)file_get_contents(dirname(__DIR__, 3) . '/lib/Service/LocationAclService.php');
		self::assertStringContainsString("str_starts_with(\$uid, 'device:')", $src);
		self::assertStringContainsString('TYPE_DEVICE', $src);
		self::assertStringContainsString('visibleLocationIdsForDevice', $src);
		self::assertStringContainsString('empty → [] (fail closed)', $src);
		self::assertStringNotContainsString('zero grants → unrestricted', $src);
	}

	public function testCycleCountAclDenyMapsToSameNotFoundCode(): void
	{
		$src = (string)file_get_contents(dirname(__DIR__, 3) . '/lib/Service/CycleCountService.php');
		self::assertStringContainsString('assertAccessibleOrNotFound', $src);
		self::assertStringContainsString("assertAccessibleOrNotFound(\$actorUid, \$locationId, 'unknown_campaign')", $src);
		self::assertStringContainsString("assertAccessibleOrNotFound(\$actorUid, (int)\$camp->getLocationId(), 'unknown_count_line')", $src);
		self::assertStringContainsString('throw new NotFoundException($notFoundCode)', $src);
	}

	public function testCycleCountCreateLocksLocationInsideTransaction(): void
	{
		$src = (string)file_get_contents(dirname(__DIR__, 3) . '/lib/Service/CycleCountService.php');
		$create = strpos($src, 'function create(');
		self::assertNotFalse($create);
		$slice = substr($src, $create, 1200);
		$tx = strpos($slice, 'beginTransaction');
		$lock = strpos($slice, 'locations->lockById($locationId, true)');
		self::assertNotFalse($tx, 'create() must open a transaction');
		self::assertNotFalse($lock, 'create() must exclusive-lock the location');
		self::assertLessThan($lock, $tx, 'lock must be inside the transaction');
		self::assertStringNotContainsString(
			'$loc = $this->locations->findById($locationId);',
			$slice,
			'unlocked pre-check before TX is a TOCTOU hole',
		);
		self::assertStringContainsString('MAX_CAMPAIGN_LINES', $src);
		self::assertStringContainsString('stocktake_too_large', $src);
		self::assertStringContainsString('searchActiveAfterId', $src, 'inventur create must keyset-page items (no OFFSET phantoms)');
		self::assertStringNotContainsString("search('', true, self::ITEM_PAGE", $src);
		self::assertStringContainsString('forCampaignPage', $src);
		self::assertStringContainsString('linesTotal', $src);
		self::assertStringContainsString('campaignHasConflicts', $src);
	}

	public function testItemPhotoRejectsInactiveAfterLock(): void
	{
		$src = (string)file_get_contents(dirname(__DIR__, 3) . '/lib/Service/ItemPhotoService.php');
		self::assertStringContainsString('lockById($itemId, true)', $src);
		self::assertGreaterThanOrEqual(2, substr_count($src, "throw new ValidationException('inactive_item')"));
		self::assertMatchesRegularExpression(
			'/lockById\(\$itemId, true\);\s*\n\s*if \(!\$item->getActive\(\)\)/',
			$src,
		);
	}

	public function testFlangePostsSortedByItemId(): void
	{
		$src = (string)file_get_contents(dirname(__DIR__, 3) . '/lib/Public/StockIssueFacade.php');
		self::assertStringContainsString('usort', $src);
		self::assertStringContainsString("\$a['itemId'] <=> \$b['itemId']", $src);
		self::assertStringContainsString('ABBA-deadlocks', $src);
	}

	public function testCycleCountCloseLocksBalancesBeforeConflictDecision(): void
	{
		$src = (string)file_get_contents(dirname(__DIR__, 3) . '/lib/Service/CycleCountService.php');
		$lockPos = strpos($src, 'lockPairs($pairs)');
		$conflictPos = strpos($src, "throw new ValidationException('count_conflict'");
		self::assertNotFalse($lockPos, 'close() must lock balances before conflict decision');
		self::assertNotFalse($conflictPos);
		self::assertLessThan($conflictPos, $lockPos);
		self::assertStringContainsString('ensureZeroRow', $src);
		// Item/location before balances — matches MovementService global order.
		$locLock = strpos($src, 'locations->lockById($locationId');
		$itemLock = strpos($src, 'items->lockById($itemId');
		self::assertNotFalse($locLock);
		self::assertNotFalse($itemLock);
		self::assertLessThan($lockPos, $locLock === false ? PHP_INT_MAX : $locLock);
		self::assertLessThan($lockPos, $itemLock === false ? PHP_INT_MAX : $itemLock);
		// setCount locks campaign before line (no ABBA vs close).
		$setCount = strpos($src, 'function setCount');
		self::assertNotFalse($setCount);
		$setSlice = substr($src, $setCount, 800);
		$campInSet = strpos($setSlice, 'campaigns->lockById');
		$lineInSet = strpos($setSlice, 'lines->lockById($lineId');
		self::assertNotFalse($campInSet);
		self::assertNotFalse($lineInSet);
		self::assertLessThan($lineInSet, $campInSet);
		self::assertStringContainsString("throw new ValidationException('track_mode_changed'", $src);
		self::assertStringContainsString('$trackModes[$itemId] = $item->getTrackMode()', $src);
		self::assertStringContainsString('isInventurEligible', $src);
	}

	public function testItemServiceReorderCeilingUsesQtyScaleMaxStorage(): void
	{
		$src = (string)file_get_contents(dirname(__DIR__, 3) . '/lib/Service/ItemService.php');
		self::assertSame(3, substr_count($src, 'QtyScale::maxStorage($this->settings)'));
		self::assertStringNotContainsString('$reorder > 1000000', $src);
		self::assertStringContainsString('item_in_open_stocktake', $src);
		self::assertSame(2, substr_count($src, 'countOpenCampaignsForItem'));
	}

	public function testCsvImportDefersLowStockNotifyUntilAfterCommit(): void
	{
		$src = (string)file_get_contents(dirname(__DIR__, 3) . '/lib/Service/CsvImportService.php');
		$recv = strpos($src, "'CSV opening balance'");
		self::assertNotFalse($recv);
		$slice = substr($src, $recv, 160);
		self::assertStringContainsString('false', $slice, 'receive must defer notify (false)');
		// After the outer TX commits, notify each received item.
		$afterCommit = strpos($src, 'foreach (array_unique($notifyItemIds)');
		self::assertNotFalse($afterCommit);
		self::assertTrue($recv < $afterCommit, 'notify loop must follow receive posts');
		self::assertStringContainsString('notifyLowStockAfterChange', substr($src, $afterCommit, 200));
	}

	public function testLocationServiceBlocksOpenStocktakeDelete(): void
	{
		$src = (string)file_get_contents(dirname(__DIR__, 3) . '/lib/Service/LocationService.php');
		self::assertStringContainsString('location_in_open_stocktake', $src);
		self::assertSame(2, substr_count($src, 'countOpenForLocation'));
	}

	public function testActiveFlagMustNotUsePhpBoolCast(): void
	{
		$item = (string)file_get_contents(dirname(__DIR__, 3) . '/lib/Service/ItemService.php');
		$loc = (string)file_get_contents(dirname(__DIR__, 3) . '/lib/Service/LocationService.php');
		self::assertStringNotContainsString("(bool)\$input['active']", $item);
		self::assertStringNotContainsString("(bool)\$input['active']", $loc);
		self::assertStringContainsString('BoolParam::parse', $item);
		self::assertStringContainsString('BoolParam::parse', $loc);
	}

	public function testLowStockScansEveryActiveItemViaKeyset(): void
	{
		$low = (string)file_get_contents(dirname(__DIR__, 3) . '/lib/Service/LowStockService.php');
		$item = (string)file_get_contents(dirname(__DIR__, 3) . '/lib/Service/ItemService.php');
		$mapper = (string)file_get_contents(dirname(__DIR__, 3) . '/lib/Db/ItemMapper.php');
		self::assertStringContainsString('function iterateActive', $mapper);
		self::assertStringContainsString('iterateActive()', $low);
		self::assertStringContainsString('iterateActive()', $item);
		self::assertStringNotContainsString("search('', true, 100000, 0)", $low);
		self::assertStringNotContainsString("search('', true, 100000, 0)", $item);
	}

	public function testCsvImportLastPriceSharesApiCeiling(): void
	{
		$src = (string)file_get_contents(dirname(__DIR__, 3) . '/lib/Service/CsvImportService.php');
		self::assertStringContainsString('100_000_000', $src);
	}
}
