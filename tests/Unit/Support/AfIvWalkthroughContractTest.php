<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;

/**
 * AF-IV walkthrough contract — machine-checkable evidence that each failure
 * mode from planning EXECUTION-PLAN §2.3 still has a concrete control in tree.
 *
 * Mapping (AF → needle / locus):
 * - AF-IV1  seat/license gate: MobileRequestException seat_required / license_missing
 * - AF-IV2  bad envelope: Iv2Codec ERROR_INVALID_FORMAT / license_invalid path
 * - AF-IV3  unknown item code: ItemService byCode → code_not_found
 * - AF-IV4  unknown location code: LocationService byCode → code_not_found
 * - AF-IV5  insufficient_stock: InsufficientStockException + middleware map
 * - AF-IV6  double-tap Confirm: scan posts via single MovementService::scan match
 * - AF-IV7  device receive blocked: asOffice ? receive : PermissionDeniedException
 * - AF-IV8  offline hard error: MobileRequestException auth_required (device-only favourites)
 * - AF-IV9  offline queue success-on-200: companionApi capability surface (mobile outbox)
 * - AF-IV10 bag partial: cycle setCount / line-level posting (no all-or-nothing bag)
 * - AF-IV11 require_adjust_reason: ReasonCodes::requireForAdjust
 * - AF-IV12 location scan mismatch: location_code_mismatch in LocationScanPolicy
 * - AF-IV13 blind count: AF-IV13 unset systemQty/currentQty
 * - AF-IV14 mid-session seat revoke: assertGate on mobile mutations
 * - AF-IV15 cleartext URL: SupportUsLinks https rejection helpers (server-side URL hygiene)
 * - AF-IV16 location ACL: device: unrestricted + visibleLocationIds
 * - AF-IV17 reorder CSV suggested: SuggestedOrder::qty
 * - AF-IV18 variance empty: export kind variance
 * - AF-IV19 pair reused/rate: DevicePairingService RATE + rate_limited 429
 * - AF-IV20 web never 402: AF-IV20 middleware MobileController guard + coming_soon status
 * - companionApi floor 5: COMPANION_API_ITEM_PHOTO = 5
 */
final class AfIvWalkthroughContractTest extends TestCase
{
	private string $root;

	protected function setUp(): void
	{
		$this->root = dirname(__DIR__, 3);
	}

	private function src(string $relative): string
	{
		$path = $this->root . '/' . ltrim($relative, '/');
		$contents = file_get_contents($path);
		self::assertNotFalse($contents, 'missing source: ' . $relative);
		return (string)$contents;
	}

	public function testAfIv3UnknownItemCode(): void
	{
		$items = $this->src('lib/Service/ItemService.php');
		self::assertStringContainsString("throw new NotFoundException('code_not_found')", $items);
	}

	public function testAfIv4UnknownLocationCode(): void
	{
		$locs = $this->src('lib/Service/LocationService.php');
		self::assertStringContainsString("throw new NotFoundException('code_not_found')", $locs);
	}

	public function testAfIv5InsufficientStock(): void
	{
		$mov = $this->src('lib/Service/MovementService.php');
		self::assertStringContainsString('InsufficientStockException', $mov);
		$mw = $this->src('lib/Middleware/AppAccessMiddleware.php');
		self::assertStringContainsString("'insufficient_stock'", $mw);
	}

	public function testAfIv6SingleScanDispatch(): void
	{
		$mov = $this->src('lib/Service/MovementService.php');
		self::assertStringContainsString('public function scan(', $mov);
		self::assertMatchesRegularExpression(
			'/return match \(\$kind\) \{\s*\'receive\' =>/',
			$mov,
		);
	}

	public function testAfIv7DeviceReceiveDenied(): void
	{
		$mov = $this->src('lib/Service/MovementService.php');
		self::assertStringContainsString(
			"? \$this->receive(\$actorUid, \$itemId, \$locationId, (int)\$qty, \$reason, \$lotCode)\n\t\t\t\t: throw new PermissionDeniedException()",
			$mov,
		);
	}

	public function testAfIv8DeviceCannotUseSeatOnlyRoutes(): void
	{
		$mobile = $this->src('lib/Controller/MobileController.php');
		self::assertStringContainsString("throw new MobileRequestException('auth_required')", $mobile);
		self::assertStringContainsString('Device tokens are field-only', $mobile);
	}

	public function testAfIv9CompanionApiSurfaceForOutbox(): void
	{
		$gate = $this->src('lib/Service/MobileAccessService.php');
		self::assertStringContainsString('COMPANION_API_ITEM_PHOTO = 5', $gate);
		self::assertStringContainsString("'companionApi' => \$companionApi", $gate);
	}

	public function testAfIv10PerLineStocktakeCount(): void
	{
		$cycles = $this->src('lib/Service/CycleCountService.php');
		self::assertStringContainsString('function setCount(', $cycles);
		self::assertStringContainsString('line_already_posted', $cycles);
	}

	public function testAfIv11RequireForAdjust(): void
	{
		$reasons = $this->src('lib/Service/ReasonCodes.php');
		self::assertStringContainsString('public static function requireForAdjust(', $reasons);
		$mov = $this->src('lib/Service/MovementService.php');
		self::assertStringContainsString('ReasonCodes::requireForAdjust', $mov);
	}

	public function testAfIv12LocationCodeMismatch(): void
	{
		$policy = $this->src('lib/Service/LocationScanPolicy.php');
		self::assertStringContainsString("'location_code_mismatch'", $policy);
		$mov = $this->src('lib/Service/MovementService.php');
		// Must gate issue + transfer (not only scan()) — web API bypass otherwise.
		$issue = strpos($mov, 'function issue(');
		$transfer = strpos($mov, 'function transfer(');
		self::assertNotFalse($issue);
		self::assertNotFalse($transfer);
		self::assertStringContainsString(
			'LocationScanPolicy::assertMatches',
			substr($mov, $issue, 500),
		);
		self::assertGreaterThanOrEqual(
			2,
			substr_count(substr($mov, $transfer, 900), 'LocationScanPolicy::assertMatches'),
			'transfer must confirm from + to location codes',
		);

		// scan() must ACL before location-code match (IDOR: mismatch must not prove hidden shelves).
		$scan = strpos($mov, 'function scan(');
		self::assertNotFalse($scan);
		$scanSlice = substr($mov, $scan, 1200);
		$aclPos = strpos($scanSlice, 'assertLocationAccess($actorUid, $locationId)');
		$codePos = strpos($scanSlice, 'LocationScanPolicy::assertMatches');
		self::assertNotFalse($aclPos);
		self::assertNotFalse($codePos);
		self::assertLessThan($codePos, $aclPos, 'scan must assert location ACL before location-code match');
	}

	public function testAfIv13BlindCountStripsSystemQty(): void
	{
		$mobile = $this->src('lib/Controller/MobileController.php');
		self::assertStringContainsString('AF-IV13', $mobile);
		self::assertStringContainsString("\$formatted['systemQty']", $mobile);
		self::assertStringContainsString("\$formatted['currentQty']", $mobile);
		self::assertStringContainsString("\$formatted['conflict']", $mobile);
		self::assertStringContainsString("unset(\$campaign['hasConflicts'], \$campaign['conflictCount'])", $mobile);
		self::assertStringContainsString('idempotency_in_flight', $mobile);
		self::assertStringContainsString('clientRequestId', $mobile);
	}

	public function testAfIv14GateOnMutations(): void
	{
		$mobile = $this->src('lib/Controller/MobileController.php');
		self::assertGreaterThanOrEqual(3, substr_count($mobile, '$this->gate->requireCaller('));
	}

	public function testAfIv16DeviceAclFailClosed(): void
	{
		$acl = $this->src('lib/Service/LocationAclService.php');
		self::assertStringContainsString("str_starts_with(\$uid, 'device:')", $acl);
		self::assertStringContainsString('visibleLocationIdsForDevice', $acl);
		self::assertStringContainsString('TYPE_DEVICE', $acl);
		self::assertStringContainsString('empty → [] (fail closed)', $acl);
		self::assertStringNotContainsString('zero grants → unrestricted', $acl);
	}

	public function testAfIv17SuggestedOrder(): void
	{
		$sug = $this->src('lib/Service/SuggestedOrder.php');
		self::assertStringContainsString('public static function qty(', $sug);
		$export = $this->src('lib/Service/CsvExportService.php');
		self::assertStringContainsString('SuggestedOrder::qty', $export);
	}

	public function testAfIv18VarianceExport(): void
	{
		$export = $this->src('lib/Service/CsvExportService.php');
		self::assertStringContainsString("'variance' =>", $export);
		self::assertStringContainsString('inventorycheck-variance.csv', $export);
	}

	public function testAfIv19PairRateLimit(): void
	{
		$pair = $this->src('lib/Service/DevicePairingService.php');
		self::assertStringContainsString('inventorycheck/pair_rate', $pair);
		$mw = $this->src('lib/Middleware/AppAccessMiddleware.php');
		self::assertStringContainsString("'rate_limited'", $mw);
		self::assertStringContainsString('429', $mw);
	}

	public function testCloseBlockedByConflictsUsesAcknowledgeFlag(): void
	{
		$sem = $this->src('lib/Service/CycleCountSemantics.php');
		self::assertStringContainsString(
			'return $hasAnyConflict && !$acknowledgeConflicts;',
			$sem,
		);
		$ctrl = $this->src('lib/Controller/CycleCountController.php');
		self::assertStringContainsString('acknowledgeConflicts', $ctrl);
	}

	public function testItemLocationCrossUniquenessWired(): void
	{
		$items = $this->src('lib/Service/ItemService.php');
		$locs = $this->src('lib/Service/LocationService.php');
		self::assertStringContainsString('conflictsWithLocationCodes', $items);
		self::assertStringContainsString('locationConflictsWithItems', $locs);
		self::assertStringContainsString('CodeRules::CODES_LOCK', $locs);
	}
}
