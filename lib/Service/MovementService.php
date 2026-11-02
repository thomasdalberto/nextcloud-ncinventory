<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Service;

use OCA\InventoryCheck\Db\Balance;
use OCA\InventoryCheck\Db\BalanceMapper;
use OCA\InventoryCheck\Db\Item;
use OCA\InventoryCheck\Db\ItemMapper;
use OCA\InventoryCheck\Db\LocationMapper;
use OCA\InventoryCheck\Db\Movement;
use OCA\InventoryCheck\Db\MovementMapper;
use OCA\InventoryCheck\Exception\ConflictException;
use OCA\InventoryCheck\Exception\InsufficientStockException;
use OCA\InventoryCheck\Exception\NotFoundException;
use OCA\InventoryCheck\Exception\PermissionDeniedException;
use OCA\InventoryCheck\Exception\ValidationException;
use OCA\InventoryCheck\Service\WorkspaceSettings;
use OCP\IDBConnection;

/**
 * Append-only stock ledger (SPEC §5).
 *
 * Every qty change posts one immutable movement row and updates iv_balances
 * in the same transaction. Lock protocol (global order, deadlock-free):
 *   1. item row (shared for lot/none items; EXCLUSIVE for serial-tracked
 *      items — see {@see lockActiveItemForMovement}) — conflicts with the
 *      exclusive deactivate/delete lock (S5/S6)
 *   2. location row(s) (shared, ascending id)
 *   3. balance rows FOR UPDATE in ascending (item_id, location_id) order —
 *      deadlock-free for opposite transfers (S2)
 * Active checks run on the locked rows, so a movement can never commit
 * against an entity that a concurrent request just deactivated or deleted.
 *
 * Wave C2 (lot/serial): a serial item's net quantity across every location
 * must never exceed 1. That invariant is only race-free because every
 * movement against a serial item takes an EXCLUSIVE item-row lock before
 * touching balances — two concurrent receives of the same serial number
 * serialise on that lock, so the second one always sees the first one's
 * committed {@see MovementMapper::sumQtyDeltaByItemAndLot} total.
 *
 * Wave C3 (location ACL): field users are checked against
 * {@see LocationAclService::canAccessLocation} before any lock is taken —
 * an inaccessible location 404s exactly like a non-existent one (IDOR-safe).
 */
class MovementService
{
	public function __construct(
		private readonly IDBConnection $db,
		private readonly MovementMapper $movements,
		private readonly BalanceMapper $balances,
		private readonly ItemMapper $items,
		private readonly LocationMapper $locations,
		private readonly AccessControlService $access,
		private readonly Clock $clock,
		private readonly LocationAclService $locationAcl,
		private readonly WorkspaceSettings $settings,
		private readonly ?LowStockNotifyService $lowStockNotify = null,
	) {
	}

	/**
	 * @return array{movements: list<array<string, mixed>>, balances: list<array<string, mixed>>}
	 */
	public function receive(
		string $actorUid,
		int $itemId,
		int $locationId,
		int $qty,
		?string $reason,
		?string $lotCode = null,
		bool $notifyLowStock = true,
	): array {
		$this->access->requireWrite($actorUid);
		$this->assertLocationAccess($actorUid, $locationId);
		return $this->postSingle($actorUid, 'receive', $itemId, $locationId, $qty, $reason, $lotCode, $notifyLowStock);
	}

	/**
	 * @return array{movements: list<array<string, mixed>>, balances: list<array<string, mixed>>}
	 */
	public function issue(
		string $actorUid,
		int $itemId,
		int $locationId,
		int $qty,
		?string $reason,
		?string $lotCode = null,
		?string $locationCode = null,
	): array {
		$this->assertLocationAccess($actorUid, $locationId);
		// Wave D8 / AF-IV12: web issue must honour require_location_scan (not only /scan).
		LocationScanPolicy::assertMatches($this->settings, $this->locations, $locationId, $locationCode);
		return $this->postSingle($actorUid, 'issue', $itemId, $locationId, $qty, $reason, $lotCode, true);
	}

	/**
	 * @return array{movements: list<array<string, mixed>>, balances: list<array<string, mixed>>}
	 */
	public function transfer(
		string $actorUid,
		int $itemId,
		int $fromLocationId,
		int $toLocationId,
		int $qty,
		?string $reason,
		?string $lotCode = null,
		?string $locationCode = null,
		?string $toLocationCode = null,
	): array {
		if ($fromLocationId === $toLocationId) {
			throw new ValidationException('same_location');
		}
		if (!MovementMath::isValidMovementQty($qty, QtyScale::maxStorage($this->settings))) {
			throw new ValidationException('invalid_qty');
		}
		$this->assertLocationAccess($actorUid, $fromLocationId);
		$this->assertLocationAccess($actorUid, $toLocationId);
		// Wave D8 / AF-IV12: confirm both ends when policy is on.
		LocationScanPolicy::assertMatches($this->settings, $this->locations, $fromLocationId, $locationCode);
		LocationScanPolicy::assertMatches(
			$this->settings,
			$this->locations,
			$toLocationId,
			$toLocationCode,
			'toLocationCode',
		);
		$reason = $this->normalizeReason($reason);

		$allowNeg = $this->access->allowNegativeStock();
		$now = $this->clock->now();
		$group = $this->uuidV4();

		$result = $this->runInTransaction(function () use (
			$actorUid, $itemId, $fromLocationId, $toLocationId, $qty, $reason, $allowNeg, $now, $group, $lotCode,
		): array {
			$item = $this->lockActiveItemForMovement($itemId);
			// Transfers move existing stock rather than creating it, so the
			// only serial invariant to enforce here is qty=1 + a valid code
			// (checked by enforceTrackMode) — the net-quantity-across-locations
			// check in checkSerialCapacity() only applies to receive/adjust.
			$lockedLot = $this->enforceTrackMode($item, $lotCode, 'transfer', $qty);
			$lockedLocs = $this->lockActiveLocations([$fromLocationId, $toLocationId]);
			$fromLoc = $lockedLocs[$fromLocationId];

			$this->balances->ensureZeroRow($itemId, $fromLocationId, $now);
			$this->balances->ensureZeroRow($itemId, $toLocationId, $now);
			$locked = $this->balances->lockPairs([
				['itemId' => $itemId, 'locationId' => $fromLocationId],
				['itemId' => $itemId, 'locationId' => $toLocationId],
			]);
			$fromBal = $locked[$itemId . ':' . $fromLocationId];
			$toBal = $locked[$itemId . ':' . $toLocationId];

			$outAfter = MovementMath::applyDelta($fromBal->getQty(), -$qty);
			$inAfter = MovementMath::applyDelta($toBal->getQty(), $qty);

			if (MovementMath::isInsufficientStock($fromBal->getQty(), $outAfter, $allowNeg)) {
				throw new InsufficientStockException($fromBal->getQty(), $fromLoc->getCode());
			}
			if (!MovementMath::isValidBalance($outAfter) || !MovementMath::isValidBalance($inAfter)) {
				throw new ValidationException('qty_out_of_range');
			}

			$this->updateBalance($fromBal, $outAfter, $now);
			$this->updateBalance($toBal, $inAfter, $now);

			$outMov = $this->insertMovement(
				$itemId, $fromLocationId, 'transfer_out', -$qty, $outAfter,
				$group, $toLocationId, $reason, $actorUid, $now, null, null, $lockedLot,
			);
			$inMov = $this->insertMovement(
				$itemId, $toLocationId, 'transfer_in', $qty, $inAfter,
				$group, $fromLocationId, $reason, $actorUid, $now, null, null, $lockedLot,
			);

			return [
				'movements' => [$outMov->toApi(), $inMov->toApi()],
				'balances' => [$fromBal->toApi(), $toBal->toApi()],
			];
		});
		$this->notifyLowStock($itemId);
		return $result;
	}

	/**
	 * @return array{movements: list<array<string, mixed>>, balances: list<array<string, mixed>>}
	 */
	public function adjust(
		string $actorUid,
		int $itemId,
		int $locationId,
		string $mode,
		?int $qty,
		?int $qtyDelta,
		?string $reason,
		?string $lotCode = null,
		bool $notifyLowStock = true,
		?string $reasonCode = null,
	): array {
		$this->access->requireWrite($actorUid);
		$this->assertLocationAccess($actorUid, $locationId);
		$reason = $this->normalizeReason($reason);
		$allowNeg = $this->access->allowNegativeStock();
		$now = $this->clock->now();

		$result = $this->runInTransaction(function () use (
			$actorUid, $itemId, $locationId, $mode, $qty, $qtyDelta, $reason, $reasonCode, $allowNeg, $now, $lotCode,
		): array {
			$item = $this->lockActiveItemForMovement($itemId);
			$lockedLot = $this->enforceTrackMode($item, $lotCode, 'adjust', 0);
			$this->lockActiveLocations([$locationId]);
			$this->balances->ensureZeroRow($itemId, $locationId, $now);
			$locked = $this->balances->lockPairs([
				['itemId' => $itemId, 'locationId' => $locationId],
			]);
			$bal = $locked[$itemId . ':' . $locationId];

			try {
				$computed = AdjustSemantics::compute(
					$mode,
					$bal->getQty(),
					$qty,
					$qtyDelta,
					$allowNeg,
					QtyScale::maxStorage($this->settings),
				);
			} catch (ValidationException $e) {
				if ($e->getErrorCode() === 'would_go_negative') {
					throw new InsufficientStockException($bal->getQty());
				}
				throw $e;
			}

			$reasonCode = ReasonCodes::requireForAdjust($this->settings, $reasonCode);

			// An adjust can increase on-hand qty exactly like a receive (e.g.
			// a cycle-count correction) — the serial uniqueness invariant
			// must hold here too, not just on the literal "receive" endpoint.
			if ($item->getTrackMode() === 'serial' && $lockedLot !== null && $computed['delta'] > 0) {
				$this->checkSerialCapacity($itemId, $lockedLot, $computed['delta']);
			}

			$this->updateBalance($bal, $computed['qtyAfter'], $now);
			$mov = $this->insertMovement(
				$itemId, $locationId, 'adjust', $computed['delta'], $computed['qtyAfter'],
				null, null, $reason, $actorUid, $now, null, null, $lockedLot, $reasonCode,
			);

			return [
				'movements' => [$mov->toApi()],
				'balances' => [$bal->toApi()],
			];
		});
		if ($notifyLowStock) {
			$this->notifyLowStock($itemId);
		}
		return $result;
	}

	/**
	 * Inventur close holds the outer ledger TX open across many adjusts —
	 * callers defer notify until after that TX commits.
	 */
	public function notifyLowStockAfterChange(int $itemId): void
	{
		$this->notifyLowStock($itemId);
	}

	/**
	 * Server-only flange path: issue with immutable ref_type/ref_id (Wave B2).
	 * Public web/mobile clients must never set these fields.
	 *
	 * @return array{movements: list<array<string, mixed>>, balances: list<array<string, mixed>>}
	 */
	public function issueWithRef(
		string $actorUid,
		int $itemId,
		int $locationId,
		int $qty,
		?string $reason,
		string $refType,
		int $refId,
		bool $notifyLowStock = true,
	): array {
		// Defense in depth: FlangeService already requireWrite, but in-process
		// StockIssueFacade callers must not skip office / location ACL.
		$this->access->requireWrite($actorUid);
		$this->assertLocationAccess($actorUid, $locationId);
		$refType = CodeRules::trim($refType);
		if ($refType === '' || mb_strlen($refType) > 32 || $refId <= 0) {
			throw new ValidationException('validation_failed', '', [
				['field' => 'refType', 'code' => 'validation_failed'],
			]);
		}
		if (!MovementMath::isValidMovementQty($qty, QtyScale::maxStorage($this->settings))) {
			throw new ValidationException('invalid_qty');
		}
		$reason = $this->normalizeReason($reason);
		$allowNeg = $this->access->allowNegativeStock();
		$delta = MovementMath::deltaForKind('issue', $qty);
		$now = $this->clock->now();

		$result = $this->runInTransaction(function () use (
			$actorUid, $itemId, $locationId, $qty, $reason, $allowNeg, $delta, $now, $refType, $refId,
		): array {
			$item = $this->lockActiveItemForMovement($itemId);
			// Server-side flange callers never supply a lot/serial code, so a
			// lot/serial-tracked SKU cannot be issued through this path — it
			// fails closed with invalid_lot_code rather than silently
			// skipping the C2 invariant.
			$this->enforceTrackMode($item, null, 'issue', $qty);
			$loc = $this->lockActiveLocations([$locationId])[$locationId];
			$this->balances->ensureZeroRow($itemId, $locationId, $now);
			$locked = $this->balances->lockPairs([
				['itemId' => $itemId, 'locationId' => $locationId],
			]);
			$bal = $locked[$itemId . ':' . $locationId];
			$qtyAfter = MovementMath::applyDelta($bal->getQty(), $delta);

			if (MovementMath::isInsufficientStock($bal->getQty(), $qtyAfter, $allowNeg)) {
				throw new InsufficientStockException($bal->getQty(), $loc->getCode());
			}
			if (!MovementMath::isValidBalance($qtyAfter)) {
				throw new ValidationException('qty_out_of_range');
			}

			$this->updateBalance($bal, $qtyAfter, $now);
			$mov = $this->insertMovement(
				$itemId, $locationId, 'issue', $delta, $qtyAfter,
				null, null, $reason, $actorUid, $now, $refType, $refId,
			);

			return [
				'movements' => [$mov->toApi()],
				'balances' => [$bal->toApi()],
			];
		});
		// Flange all-or-nothing wraps an outer TX — defer notify so a later
		// line rollback cannot leave phantom Notifications/Activity.
		if ($notifyLowStock) {
			$this->notifyLowStock($itemId);
		}
		return $result;
	}

	/**
	 * Scan endpoint (S12): resolve code then dispatch by kind.
	 * Device callers are treated as field (caller passes $asOffice=false).
	 *
	 * @return array{movements: list<array<string, mixed>>, balances: list<array<string, mixed>>}
	 */
	public function scan(
		string $actorUid,
		string $code,
		string $kind,
		int $locationId,
		?int $toLocationId,
		?int $qty,
		?int $qtyDelta,
		?string $reason,
		bool $asOffice,
		?string $lotCode = null,
		?string $reasonCode = null,
		?string $locationCode = null,
		?string $toLocationCode = null,
	): array {
		$item = $this->items->resolveByCode(CodeRules::trim($code));
		if ($item === null) {
			throw new NotFoundException('code_not_found');
		}
		$kind = strtolower(trim($kind));
		$itemId = (int)$item->getId();

		// ACL before location-code checks — otherwise a wrong code on a hidden
		// location returns location_code_mismatch and proves the shelf exists.
		$this->assertLocationAccess($actorUid, $locationId);
		if ($kind === 'transfer' && $toLocationId !== null && $toLocationId > 0) {
			$this->assertLocationAccess($actorUid, $toLocationId);
		}

		LocationScanPolicy::assertMatches($this->settings, $this->locations, $locationId, $locationCode);

		return match ($kind) {
			'receive' => $asOffice
				? $this->receive($actorUid, $itemId, $locationId, (int)$qty, $reason, $lotCode)
				: throw new PermissionDeniedException(),
			// issue/transfer re-assert inside their methods (web API paths share the gate).
			'issue' => $this->issue($actorUid, $itemId, $locationId, (int)$qty, $reason, $lotCode, $locationCode),
			'transfer' => $toLocationId === null || $toLocationId <= 0
				? throw new ValidationException('validation_failed', '', [
					['field' => 'toLocationId', 'code' => 'validation_failed'],
				])
				: $this->transfer(
					$actorUid,
					$itemId,
					$locationId,
					$toLocationId,
					(int)$qty,
					$reason,
					$lotCode,
					$locationCode,
					$toLocationCode,
				),
			'adjust' => $asOffice
				? $this->adjust($actorUid, $itemId, $locationId, 'delta', null, $qtyDelta, $reason, $lotCode, true, $reasonCode)
				: throw new PermissionDeniedException(),
			default => throw new ValidationException('validation_failed', '', [
				['field' => 'kind', 'code' => 'validation_failed'],
			]),
		};
	}

	/**
	 * @return array{data: list<array<string, mixed>>, total: int, limit: int, offset: int}
	 */
	public function list(
		string $actorUid,
		?string $kind,
		?int $itemId,
		?int $locationId,
		?int $from,
		?int $to,
		?string $transferGroup,
		int $limit,
		int $offset,
		?string $reasonCode = null,
	): array {
		if ($from !== null && $to !== null && $from > $to) {
			throw new ValidationException('invalid_query');
		}
		$visible = $this->locationAcl->visibleLocationIds($actorUid);
		if ($visible !== null) {
			if ($visible === []) {
				return ['data' => [], 'total' => 0, 'limit' => $limit, 'offset' => $offset];
			}
			if ($locationId !== null && !in_array($locationId, $visible, true)) {
				// IDOR-safe: hidden location looks empty, not "unknown".
				return ['data' => [], 'total' => 0, 'limit' => $limit, 'offset' => $offset];
			}
		}
		$result = $this->movements->search(
			$kind,
			$itemId,
			$locationId,
			$from,
			$to,
			$transferGroup,
			$limit,
			$offset,
			$visible,
			$reasonCode,
		);
		return [
			'data' => array_map(fn (Movement $m) => $this->movementToListApi($m), $result['data']),
			'total' => $result['total'],
			'limit' => $limit,
			'offset' => $offset,
		];
	}

	/**
	 * Join item name+sku and location code+name for companion Recent / API lists.
	 * Keys are always present; deleted masters yield null (row stays listable).
	 *
	 * @return array<string, mixed>
	 */
	private function movementToListApi(Movement $m): array
	{
		$itemName = null;
		$sku = null;
		$locationCode = null;
		$locationName = null;
		try {
			$item = $this->items->findById($m->getItemId());
			$itemName = $item->getName();
			$sku = $item->getSku();
		} catch (\Throwable) {
			// Keep numeric ids when the item row is gone.
		}
		try {
			$loc = $this->locations->findById($m->getLocationId());
			$locationCode = $loc->getCode();
			$locationName = $loc->getName();
		} catch (\Throwable) {
			// Keep numeric ids when the location row is gone.
		}
		return self::withDisplayNames($m->toApi(), $itemName, $sku, $locationCode, $locationName);
	}

	/**
	 * Attach display fields used by companion Recent. QtyScale formatting must
	 * leave these keys untouched.
	 *
	 * @param array<string, mixed> $api
	 * @return array<string, mixed>
	 */
	public static function withDisplayNames(
		array $api,
		?string $itemName,
		?string $sku,
		?string $locationCode,
		?string $locationName,
	): array {
		$api['itemName'] = $itemName;
		$api['sku'] = $sku;
		$api['locationCode'] = $locationCode;
		$api['locationName'] = $locationName;
		return $api;
	}

	/**
	 * @return array{movements: list<array<string, mixed>>, balances: list<array<string, mixed>>}
	 */
	private function postSingle(
		string $actorUid,
		string $kind,
		int $itemId,
		int $locationId,
		int $qty,
		?string $reason,
		?string $lotCode = null,
		bool $notifyLowStock = true,
	): array {
		if (!MovementMath::isValidMovementQty($qty, QtyScale::maxStorage($this->settings))) {
			throw new ValidationException('invalid_qty');
		}
		$reason = $this->normalizeReason($reason);
		$allowNeg = $this->access->allowNegativeStock();
		$delta = MovementMath::deltaForKind($kind, $qty);
		$now = $this->clock->now();

		$result = $this->runInTransaction(function () use (
			$actorUid, $kind, $itemId, $locationId, $qty, $reason, $allowNeg, $delta, $now, $lotCode,
		): array {
			$item = $this->lockActiveItemForMovement($itemId);
			$lockedLot = $this->enforceTrackMode($item, $lotCode, $kind, $qty);
			$loc = $this->lockActiveLocations([$locationId])[$locationId];
			$this->balances->ensureZeroRow($itemId, $locationId, $now);
			$locked = $this->balances->lockPairs([
				['itemId' => $itemId, 'locationId' => $locationId],
			]);
			$bal = $locked[$itemId . ':' . $locationId];
			$qtyAfter = MovementMath::applyDelta($bal->getQty(), $delta);

			if ($kind === 'receive' && $item->getTrackMode() === 'serial' && $lockedLot !== null) {
				$this->checkSerialCapacity($itemId, $lockedLot, $delta);
			}

			if (MovementMath::isInsufficientStock($bal->getQty(), $qtyAfter, $allowNeg)) {
				throw new InsufficientStockException($bal->getQty(), $loc->getCode());
			}
			if (!MovementMath::isValidBalance($qtyAfter)) {
				throw new ValidationException('qty_out_of_range');
			}

			$this->updateBalance($bal, $qtyAfter, $now);
			$mov = $this->insertMovement(
				$itemId, $locationId, $kind, $delta, $qtyAfter,
				null, null, $reason, $actorUid, $now, null, null, $lockedLot,
			);

			return [
				'movements' => [$mov->toApi()],
				'balances' => [$bal->toApi()],
			];
		});
		if ($notifyLowStock) {
			$this->notifyLowStock($itemId);
		}
		return $result;
	}

	/**
	 * Nested-transaction safe: only begin/commit when not already in a TX
	 * (CSV import / campaign close wrap many posts).
	 *
	 * @template T
	 * @param callable(): T $fn
	 * @return T
	 */
	private function runInTransaction(callable $fn): mixed
	{
		$started = !$this->db->inTransaction();
		if ($started) {
			$this->db->beginTransaction();
		}
		try {
			$result = $fn();
			if ($started) {
				$this->db->commit();
			}
			return $result;
		} catch (\Throwable $e) {
			if ($started && $this->db->inTransaction()) {
				$this->db->rollBack();
			}
			throw $e;
		}
	}

	private function notifyLowStock(int $itemId): void
	{
		if ($this->lowStockNotify === null) {
			return;
		}
		try {
			$this->lowStockNotify->afterStockChange($itemId);
		} catch (\Throwable) {
			// Best-effort: ledger commit must not roll back for notify failures.
		}
	}

	private function updateBalance(Balance $bal, int $qtyAfter, int $now): void
	{
		$bal->setQty($qtyAfter);
		$bal->setUpdatedAt($now);
		$this->balances->update($bal);
	}

	private function insertMovement(
		int $itemId,
		int $locationId,
		string $kind,
		int $qtyDelta,
		int $qtyAfter,
		?string $transferGroup,
		?int $counterpartyLocId,
		?string $reason,
		string $actorUid,
		int $now,
		?string $refType = null,
		?int $refId = null,
		?string $lotCode = null,
		?string $reasonCode = null,
	): Movement {
		$m = new Movement();
		$m->setItemId($itemId);
		$m->setLocationId($locationId);
		$m->setKind($kind);
		$m->setQtyDelta($qtyDelta);
		$m->setQtyAfter($qtyAfter);
		$m->setTransferGroup($transferGroup);
		$m->setCounterpartyLocId($counterpartyLocId);
		$m->setReason($reason);
		$m->setReasonCode($reasonCode);
		$m->setRefType($refType);
		$m->setRefId($refId);
		$m->setLotCode($lotCode);
		$m->setCreatedAt($now);
		$m->setCreatedBy($actorUid);
		return $this->movements->insert($m);
	}

	/**
	 * Lock the item row inside the open transaction and verify it is active.
	 *
	 * Wave C2: a non-serial item takes the usual shared lock (S5/S6 TOCTOU
	 * protection, unchanged concurrency for the common case). A serial item
	 * takes an EXCLUSIVE lock instead, serialising every movement against
	 * it so {@see checkSerialCapacity} always sees a consistent, committed
	 * net-quantity total — this is what makes the "never more than 1" check
	 * race-free.
	 *
	 * track_mode is peeked (unlocked) first purely to pick the lock
	 * strength; the authoritative value is re-read from the locked row.
	 * If it turns out to be serial after a shared lock was taken, we do
	 * **not** escalate in-place (SHARE→EXCLUSIVE upgrade deadlocks when two
	 * transactions both hold SHARE and both escalate). Instead we abort with
	 * {@see ConflictException} `item_lock_conflict` so the client retries and
	 * the next attempt peeks serial and takes EXCLUSIVE from the start.
	 */
	private function lockActiveItemForMovement(int $itemId): Item
	{
		// Peek unlocked only to choose shared vs exclusive strength; then lock.
		$peek = $this->items->findById($itemId);
		$exclusive = $peek->getTrackMode() === 'serial';
		$item = $this->items->lockById($itemId, $exclusive);
		if (!$item->getActive()) {
			throw new ValidationException('inactive_item');
		}
		if (!$exclusive && $item->getTrackMode() === 'serial') {
			throw new ConflictException('item_lock_conflict');
		}
		return $item;
	}

	/**
	 * Wave C2: validate lotCode against the item's track_mode and, for
	 * serial items, that qty=1 on issue/transfer legs. Returns the
	 * normalized (trimmed) lot code to persist on the movement row, or null
	 * for track_mode=none.
	 */
	private function enforceTrackMode(Item $item, ?string $lotCode, string $kind, int $qty): ?string
	{
		$trimmed = $lotCode !== null ? CodeRules::trim($lotCode) : null;
		$trackMode = $item->getTrackMode();
		if ($trackMode === 'none') {
			if ($trimmed !== null && $trimmed !== '') {
				throw new ValidationException('lot_code_not_applicable', '', [
					['field' => 'lotCode', 'code' => 'lot_code_not_applicable'],
				]);
			}
			return null;
		}
		if ($trimmed === null || $trimmed === '' || !CodeRules::isValidLotCode($trimmed)) {
			throw new ValidationException('invalid_lot_code', '', [
				['field' => 'lotCode', 'code' => 'invalid_lot_code'],
			]);
		}
		$unit = QtyScale::serialUnit($this->settings);
		if ($trackMode === 'serial' && in_array($kind, ['issue', 'transfer'], true) && $qty !== $unit) {
			throw new ValidationException('serial_qty_must_be_one', '', [
				['field' => 'qty', 'code' => 'serial_qty_must_be_one'],
			]);
		}
		if ($trackMode === 'serial' && $kind === 'receive' && $qty !== $unit) {
			throw new ValidationException('serial_qty_must_be_one', '', [
				['field' => 'qty', 'code' => 'serial_qty_must_be_one'],
			]);
		}
		return $trimmed;
	}

	/**
	 * Wave C2: a serial number must never exist more than once across every
	 * location at the same time. Capacity is one *display* unit in storage
	 * ints ({@see QtyScale::serialUnit}) so scale=3 still allows exactly one
	 * physical serial. Called only for qty-increasing operations.
	 */
	private function checkSerialCapacity(int $itemId, string $lotCode, int $incomingDelta): void
	{
		if ($incomingDelta <= 0) {
			return;
		}
		$unit = QtyScale::serialUnit($this->settings);
		$current = $this->movements->sumQtyDeltaByItemAndLot($itemId, $lotCode);
		if ($current + $incomingDelta > $unit) {
			throw new ConflictException('serial_exists');
		}
	}

	/**
	 * Wave C3: field users (office/app-admin/system-admin always bypass —
	 * see {@see LocationAclService::visibleLocationIds}) may only post
	 * against locations explicitly granted to them. An inaccessible
	 * location fails exactly like a non-existent one so it cannot be probed
	 * for existence (IDOR-safe).
	 */
	private function assertLocationAccess(string $actorUid, int $locationId): void
	{
		if (!$this->locationAcl->canAccessLocation($actorUid, $locationId)) {
			throw new NotFoundException('unknown_location');
		}
	}

	/**
	 * Shared-lock location rows in ascending id order (global lock ordering)
	 * and verify each is active.
	 *
	 * @param list<int> $locationIds
	 * @return array<int, \OCA\InventoryCheck\Db\Location> keyed by location id
	 */
	private function lockActiveLocations(array $locationIds): array
	{
		$ids = array_values(array_unique($locationIds));
		sort($ids);
		$out = [];
		foreach ($ids as $id) {
			$loc = $this->locations->lockById($id, false);
			if (!$loc->getActive()) {
				throw new ValidationException('inactive_location');
			}
			$out[$id] = $loc;
		}
		return $out;
	}

	private function normalizeReason(?string $reason): ?string
	{
		if ($reason === null) {
			return null;
		}
		$reason = CodeRules::trim($reason);
		if ($reason === '') {
			return null;
		}
		if (mb_strlen($reason) > 512) {
			throw new ValidationException('validation_failed', '', [
				['field' => 'reason', 'code' => 'validation_failed'],
			]);
		}
		return $reason;
	}

	private function uuidV4(): string
	{
		$data = random_bytes(16);
		$data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
		$data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
		return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
	}
}
