<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Tests\Unit\Service;

use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

/**
 * Deadlock / race protocol encoded as executable source assertions
 * (SPEC S2/S5/S6, AC-8/AC-9) plus behavioural checks of the provider-aware
 * row-lock suffix.
 */
final class MovementLockingProtocolTest extends TestCase
{
	public function testBalanceMapperLocksPairsAscendingWithForUpdate(): void
	{
		$src = file_get_contents(dirname(__DIR__, 3) . '/lib/Db/BalanceMapper.php');
		self::assertNotFalse($src);
		self::assertStringContainsString('FOR UPDATE', $src);
		self::assertStringContainsString('usort', $src);
		self::assertMatchesRegularExpression(
			"/itemId'\]\s*<=>\s*\\\$b\['itemId'\]/",
			$src,
		);
		self::assertMatchesRegularExpression(
			"/locationId'\]\s*<=>\s*\\\$b\['locationId'\]/",
			$src,
		);
		self::assertStringContainsString('PLATFORM_SQLITE', $src);
	}

	public function testScanTransferRequiresToLocationId(): void
	{
		$src = file_get_contents(dirname(__DIR__, 3) . '/lib/Service/MovementService.php');
		self::assertNotFalse($src);
		self::assertStringContainsString("\$toLocationId === null || \$toLocationId <= 0", $src);
		self::assertStringContainsString("['field' => 'toLocationId'", $src);
	}

	/** Wave D8 / AF-IV12: issue + transfer (both ends) must call LocationScanPolicy. */
	public function testIssueAndTransferEnforceLocationScanPolicy(): void
	{
		$src = file_get_contents(dirname(__DIR__, 3) . '/lib/Service/MovementService.php');
		self::assertNotFalse($src);
		self::assertGreaterThanOrEqual(
			4,
			substr_count($src, 'LocationScanPolicy::assertMatches'),
			'scan + issue + transfer-from + transfer-to (at minimum)',
		);
		self::assertStringContainsString("'toLocationCode'", $src);
		self::assertStringContainsString('web issue must honour require_location_scan', $src);
	}

	public function testMovementServiceDocumentsAscendingLockOrderAndUsesLockPairs(): void
	{
		$src = file_get_contents(dirname(__DIR__, 3) . '/lib/Service/MovementService.php');
		self::assertNotFalse($src);
		self::assertStringContainsString('ascending (item_id, location_id)', $src);
		self::assertStringContainsString('lockPairs', $src);
		self::assertStringContainsString('beginTransaction', $src);
		self::assertStringContainsString('InsufficientStockException', $src);
		self::assertGreaterThanOrEqual(
			2,
			substr_count($src, 'lockPairs'),
			'receive/issue and transfer paths must both lock',
		);
	}

	public function testEnsureZeroRowUsesInsertIgnoreConflict(): void
	{
		$src = file_get_contents(dirname(__DIR__, 3) . '/lib/Db/BalanceMapper.php');
		self::assertNotFalse($src);
		self::assertStringContainsString(
			'insertIgnoreConflict',
			$src,
			'a raw unique violation would abort the surrounding transaction on PostgreSQL',
		);
		self::assertStringNotContainsString(
			'catch (\\Throwable)',
			$src,
			'swallowing insert errors hides PG transaction aborts',
		);
	}

	public function testIssueWithRefEnforcesOfficeAndLocationAcl(): void
	{
		$src = file_get_contents(dirname(__DIR__, 3) . '/lib/Service/MovementService.php');
		self::assertNotFalse($src);
		$start = strpos($src, 'function issueWithRef(');
		self::assertNotFalse($start);
		$body = substr($src, $start, 900);
		self::assertStringContainsString('$this->access->requireWrite($actorUid)', $body);
		self::assertStringContainsString('$this->assertLocationAccess($actorUid, $locationId)', $body);
	}

	public function testTrackModeUpgradeRequiresZeroStockGate(): void
	{
		$src = file_get_contents(dirname(__DIR__, 3) . '/lib/Service/ItemService.php');
		self::assertNotFalse($src);
		self::assertStringContainsString('assertTrackModeChangeAllowed', $src);
		self::assertStringContainsString("ConflictException('track_mode_requires_zero_stock')", $src);
		self::assertStringContainsString('hasNonZeroBalance', $src);
	}

	public function testMovementsTakeEntitySharedLocksInsideTheTransaction(): void
	{
		$src = file_get_contents(dirname(__DIR__, 3) . '/lib/Service/MovementService.php');
		self::assertNotFalse($src);
		// Active checks must be locking reads inside the open transaction,
		// not unlocked pre-checks (S5/S6 TOCTOU).
		self::assertSame(4, substr_count($src, '$this->lockActiveItemForMovement('), 'receive/issue, transfer, adjust, issueWithRef');
		self::assertSame(4, substr_count($src, '$this->lockActiveLocations('), 'receive/issue, transfer, adjust, issueWithRef');
		self::assertStringContainsString('lockById($id, false)', $src);
		self::assertStringContainsString('sort($ids)', $src);
		self::assertStringNotContainsString('requireActiveItem', $src);
	}

	/**
	 * Wave C2: a serial-tracked item must take an EXCLUSIVE item-row lock
	 * (not the usual shared lock) so two concurrent receives of the same
	 * serial number can never both pass the net-quantity check.
	 *
	 * track_mode flip mid-flight must NOT escalate SHARE→EXCLUSIVE in-place
	 * (deadlock); abort with item_lock_conflict so the client retries.
	 */
	public function testSerialItemsTakeExclusiveLockNonSerialTakeShared(): void
	{
		$src = file_get_contents(dirname(__DIR__, 3) . '/lib/Service/MovementService.php');
		self::assertNotFalse($src);
		self::assertStringContainsString("getTrackMode() === 'serial'", $src);
		self::assertStringContainsString('lockById($itemId, $exclusive)', $src);
		self::assertStringContainsString("ConflictException('item_lock_conflict')", $src);
		self::assertMatchesRegularExpression(
			"/if\s*\(\s*!\\\$exclusive\s*&&\s*\\\$item->getTrackMode\(\)\s*===\s*'serial'\s*\)/",
			$src,
			'track_mode flip must throw item_lock_conflict (no dead if(false) branch)',
		);
		self::assertStringNotContainsString(
			'// track_mode may have flipped to serial between peek and lock — escalate.',
			$src,
			'shared→exclusive escalate is a deadlock footgun',
		);
		self::assertStringContainsString("throw new ValidationException('inactive_item')", $src);
		self::assertStringContainsString('if (!$item->getActive())', $src);
		self::assertStringContainsString('checkSerialCapacity', $src);
		self::assertStringContainsString('sumQtyDeltaByItemAndLot', $src);
	}

	/** Companion honesty: recent list joins item/location names for field UX. */
	public function testListApiJoinsItemAndLocationLabels(): void
	{
		$src = file_get_contents(dirname(__DIR__, 3) . '/lib/Service/MovementService.php');
		self::assertNotFalse($src);
		self::assertStringContainsString('movementToListApi', $src);
		self::assertStringContainsString('withDisplayNames', $src);
		self::assertStringContainsString("\$api['itemName']", $src);
		self::assertStringContainsString("\$api['sku']", $src);
		self::assertStringContainsString("\$api['locationCode']", $src);
		self::assertStringContainsString("\$api['locationName']", $src);
	}

	public function testDeactivateAndDeletePathsTakeExclusiveEntityLocks(): void
	{
		$items = file_get_contents(dirname(__DIR__, 3) . '/lib/Service/ItemService.php');
		$locations = file_get_contents(dirname(__DIR__, 3) . '/lib/Service/LocationService.php');
		self::assertNotFalse($items);
		self::assertNotFalse($locations);
		self::assertSame(2, substr_count($items, 'lockById($id, true)'), 'item update + delete');
		self::assertSame(2, substr_count($locations, 'lockById($id, true)'), 'location update + delete');
		self::assertStringContainsString('withCodesLock', $items);
		self::assertStringContainsString('UniqueViolation::is', $items);
		self::assertStringContainsString('UniqueViolation::is', $locations);
	}

	public function testRowLockSuffixPerProvider(): void
	{
		self::assertSame(' LOCK IN SHARE MODE', $this->suffixFor(IDBConnection::PLATFORM_MYSQL, false));
		if (\defined(IDBConnection::class . '::PLATFORM_MARIADB')) {
			self::assertSame(
				' LOCK IN SHARE MODE',
				$this->suffixFor(\constant(IDBConnection::class . '::PLATFORM_MARIADB'), false),
			);
			self::assertSame(
				' FOR UPDATE',
				$this->suffixFor(\constant(IDBConnection::class . '::PLATFORM_MARIADB'), true),
			);
		}
		self::assertSame(' FOR SHARE', $this->suffixFor(IDBConnection::PLATFORM_POSTGRES, false));
		self::assertSame('', $this->suffixFor(IDBConnection::PLATFORM_SQLITE, false));
		self::assertSame('', $this->suffixFor(IDBConnection::PLATFORM_SQLITE, true));
		self::assertSame(' FOR UPDATE', $this->suffixFor(IDBConnection::PLATFORM_MYSQL, true));
		self::assertSame(' FOR UPDATE', $this->suffixFor(IDBConnection::PLATFORM_POSTGRES, true));
		// Unknown providers fall back to the universal exclusive lock.
		self::assertSame(' FOR UPDATE', $this->suffixFor(IDBConnection::PLATFORM_ORACLE, false));
	}

	private function suffixFor(string $provider, bool $exclusive): string
	{
		$db = $this->createMock(IDBConnection::class);
		$db->method('getDatabaseProvider')->willReturn($provider);

		$probe = new class($db) {
			use \OCA\InventoryCheck\Db\RowLocking;

			public function __construct(private readonly IDBConnection $db)
			{
			}

			public function suffix(bool $exclusive): string
			{
				return $this->rowLockSuffix($exclusive);
			}
		};

		return $probe->suffix($exclusive);
	}
}
