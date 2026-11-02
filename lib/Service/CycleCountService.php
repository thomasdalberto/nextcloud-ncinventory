<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Service;

use OCA\InventoryCheck\Db\BalanceMapper;
use OCA\InventoryCheck\Db\CycleCampaign;
use OCA\InventoryCheck\Db\CycleCampaignMapper;
use OCA\InventoryCheck\Db\CycleLine;
use OCA\InventoryCheck\Db\CycleLineMapper;
use OCA\InventoryCheck\Db\ItemMapper;
use OCA\InventoryCheck\Db\LocationMapper;
use OCA\InventoryCheck\Exception\ConflictException;
use OCA\InventoryCheck\Exception\NotFoundException;
use OCA\InventoryCheck\Exception\ValidationException;
use OCP\IDBConnection;

/**
 * Cycle-count / Inventur campaigns (Wave B1).
 *
 * States: open → counting → closed.
 * Each counted line posts one adjust (mode=set) or is skipped on abandon.
 */
class CycleCountService
{
	public const STATUS_OPEN = 'open';
	public const STATUS_COUNTING = 'counting';
	public const STATUS_CLOSED = 'closed';

	/** Hard cap so inventur create cannot materialise the whole catalog into PHP/DB (DoS / OOM). */
	public const MAX_CAMPAIGN_LINES = 10000;

	private const ITEM_PAGE = 500;

	public function __construct(
		private readonly IDBConnection $db,
		private readonly CycleCampaignMapper $campaigns,
		private readonly CycleLineMapper $lines,
		private readonly LocationMapper $locations,
		private readonly ItemMapper $items,
		private readonly BalanceMapper $balances,
		private readonly MovementService $movements,
		private readonly AccessControlService $access,
		private readonly Clock $clock,
		private readonly LocationAclService $locationAcl,
	) {
	}

	/**
	 * @return array{data: list<array<string, mixed>>, total: int, limit: int, offset: int}
	 */
	public function list(string $actorUid, ?string $status, int $limit, int $offset): array
	{
		$visible = $this->locationAcl->visibleLocationIds($actorUid);
		$result = $this->campaigns->search($status, $limit, $offset, $visible);
		return [
			'data' => array_map(static fn (CycleCampaign $c) => $c->toApi(), $result['data']),
			'total' => $result['total'],
			'limit' => $limit,
			'offset' => $offset,
		];
	}

	/**
	 * @return array<string, mixed>
	 */
	public function get(string $actorUid, int $id, ?int $limit = null, ?int $offset = null): array
	{
		$camp = $this->campaigns->findById($id);
		$locationId = (int)$camp->getLocationId();
		// IDOR: ACL deny must look identical to a missing campaign.
		$this->assertAccessibleOrNotFound($actorUid, $locationId, 'unknown_campaign');
		$api = $camp->toApi();
		$page = Pagination::parse(
			$limit ?? Pagination::DEFAULT_LIMIT,
			$offset ?? 0,
		);
		$linesTotal = $this->lines->countForCampaign($id);
		$linesCounted = $this->lines->countCountedForCampaign($id);
		$lines = [];
		foreach ($this->lines->forCampaignPage($id, $page['limit'], $page['offset']) as $l) {
			$row = $l->toApi();
			$itemId = (int)$l->getItemId();
			try {
				$item = $this->items->findById($itemId);
				$row['sku'] = $item->getSku();
				$row['itemName'] = $item->getName();
				$row['scanCode'] = $item->getScanCode();
			} catch (\Throwable) {
				// Line stays countable even if the item row was removed mid-campaign.
			}
			$pair = $this->balances->findPair($itemId, $locationId);
			$current = $pair !== null ? $pair->getQty() : 0;
			$row['currentQty'] = $current;
			$row['conflict'] = CycleCountSemantics::hasConflict((int)$l->getSystemQty(), $current);
			$lines[] = $row;
		}
		$api['lines'] = $lines;
		$api['linesTotal'] = $linesTotal;
		$api['linesCounted'] = $linesCounted;
		$api['linesUncounted'] = max(0, $linesTotal - $linesCounted);
		$api['limit'] = $page['limit'];
		$api['offset'] = $page['offset'];
		$api['hasConflicts'] = $this->lines->campaignHasConflicts($id, $locationId);
		$api['conflictCount'] = $this->lines->countConflictsForCampaign($id, $locationId);
		return $api;
	}

	/** @return array<string, mixed> */
	public function create(string $actorUid, int $locationId, string $name): array
	{
		$this->access->requireWrite($actorUid);
		$name = CodeRules::trim($name);
		if ($name === '' || mb_strlen($name) > 255) {
			throw new ValidationException('validation_failed', '', [['field' => 'name', 'code' => 'validation_failed']]);
		}
		$now = $this->clock->now();
		$this->db->beginTransaction();
		try {
			// Exclusive lock serialises against location deactivate/delete (S5/S6)
			// so we cannot open inventur on a location flipped inactive mid-create.
			$loc = $this->locations->lockById($locationId, true);
			if (!$loc->getActive()) {
				throw new ValidationException('inactive_location');
			}

			$camp = new CycleCampaign();
			$camp->setLocationId($locationId);
			$camp->setStatus(self::STATUS_OPEN);
			$camp->setName($name);
			$camp->setCreatedAt($now);
			$camp->setUpdatedAt($now);
			$camp->setCreatedBy($actorUid);
			$camp->setClosedAt(null);
			$camp = $this->campaigns->insert($camp);

			// Keyset by id (not OFFSET): concurrent item inserts must not
			// re-emit the same SKU across pages and trip iv_ccl_camp_item_uq.
			$eligible = 0;
			$afterId = 0;
			while (true) {
				$page = $this->items->searchActiveAfterId($afterId, self::ITEM_PAGE);
				if ($page === []) {
					break;
				}
				foreach ($page as $item) {
					$itemId = (int)$item->getId();
					$afterId = $itemId;
					// Wave C2: inventur adjusts total qty without a lot/serial —
					// lot/serial SKUs are excluded until a dedicated per-lot count
					// exists (FEFO backlog). Including them would fail closed on
					// close() with invalid_lot_code and leave campaigns unclosable.
					if ($item->getTrackMode() !== 'none') {
						continue;
					}
					$eligible++;
					if ($eligible > self::MAX_CAMPAIGN_LINES) {
						throw new ValidationException('stocktake_too_large', '', [
							['field' => 'locationId', 'code' => 'stocktake_too_large'],
						]);
					}
					$bal = $this->balances->findPair($itemId, $locationId);
					$systemQty = $bal?->getQty() ?? 0;
					$line = new CycleLine();
					$line->setCampaignId((int)$camp->getId());
					$line->setItemId($itemId);
					$line->setSystemQty($systemQty);
					$line->setQtyCounted(null);
					$line->setPostedMovId(null);
					$line->setUpdatedAt($now);
					$this->lines->insert($line);
				}
				if (count($page) < self::ITEM_PAGE) {
					break;
				}
			}
			$this->db->commit();
			return $this->get($actorUid, (int)$camp->getId(), Pagination::MAX_LIMIT, 0);
		} catch (\Throwable $e) {
			if ($this->db->inTransaction()) {
				$this->db->rollBack();
			}
			throw $e;
		}
	}

	/** @return array<string, mixed> */
	public function startCounting(string $actorUid, int $campaignId): array
	{
		$this->access->requireWrite($actorUid);
		$this->db->beginTransaction();
		try {
			$camp = $this->campaigns->lockById($campaignId, true);
			if (!CycleCountSemantics::canStart($camp->getStatus())) {
				throw new ConflictException('campaign_not_open');
			}
			$camp->setStatus(self::STATUS_COUNTING);
			$camp->setUpdatedAt($this->clock->now());
			$this->campaigns->update($camp);
			$this->db->commit();
			return $this->get($actorUid, $campaignId, Pagination::MAX_LIMIT, 0);
		} catch (\Throwable $e) {
			if ($this->db->inTransaction()) {
				$this->db->rollBack();
			}
			throw $e;
		}
	}

	/** @return array<string, mixed> */
	/**
	 * Submit a counted qty for a line. Any app user with location ACL may count
	 * (companion P2 inventur counters); create/start/close remain office-only.
	 */
	public function setCount(string $actorUid, int $lineId, int $qtyCounted): array
	{
		if (!CycleCountSemantics::isQtyCountedValid($qtyCounted)) {
			throw new ValidationException('validation_failed', '', [['field' => 'qtyCounted', 'code' => 'validation_failed']]);
		}
		$this->db->beginTransaction();
		try {
			// Lock order matches close(): campaign → line (never line → campaign).
			// Otherwise setCount vs close ABBA-deadlocks on the same inventur.
			$peek = $this->lines->findById($lineId);
			$camp = $this->campaigns->lockById($peek->getCampaignId(), true);
			// IDOR: ACL deny must look identical to a missing count line.
			$this->assertAccessibleOrNotFound($actorUid, (int)$camp->getLocationId(), 'unknown_count_line');
			$line = $this->lines->lockById($lineId, true);
			if ((int)$line->getCampaignId() !== (int)$camp->getId()) {
				throw new ConflictException('campaign_not_counting');
			}
			if (!CycleCountSemantics::canCountOrClose($camp->getStatus())) {
				throw new ConflictException('campaign_not_counting');
			}
			if ($line->getPostedMovId() !== null) {
				throw new ConflictException('line_already_posted');
			}
			$line->setQtyCounted($qtyCounted);
			$line->setUpdatedAt($this->clock->now());
			$this->lines->update($line);
			$this->db->commit();
			return $line->toApi();
		} catch (\Throwable $e) {
			if ($this->db->inTransaction()) {
				$this->db->rollBack();
			}
			throw $e;
		}
	}

	/**
	 * @return array<string, mixed>
	 */
	public function close(
		string $actorUid,
		int $campaignId,
		bool $abandonUncounted = false,
		bool $acknowledgeConflicts = false,
	): array {
		$this->access->requireWrite($actorUid);
		$this->db->beginTransaction();
		try {
			$camp = $this->campaigns->lockById($campaignId, true);
			if (!CycleCountSemantics::canCountOrClose($camp->getStatus())) {
				throw new ConflictException('campaign_not_counting');
			}
			$lines = $this->lines->forCampaign($campaignId);
			foreach ($lines as $line) {
				if (CycleCountSemantics::isIncomplete($line->getQtyCounted(), $abandonUncounted)) {
					throw new ValidationException('count_incomplete');
				}
			}

			// Global lock protocol (must match MovementService — deadlock-free):
			//   campaign → items (asc id) → location → balances (asc item,loc)
			// Never location-before-items: movements take item then location;
			// inventur close taking location then item is classic ABBA deadlock
			// against concurrent receive/issue/transfer/scan.
			$locationId = (int)$camp->getLocationId();
			$itemIds = [];
			foreach ($lines as $line) {
				$itemIds[(int)$line->getItemId()] = true;
			}
			$sortedItemIds = array_keys($itemIds);
			sort($sortedItemIds);
			$trackModes = [];
			foreach ($sortedItemIds as $itemId) {
				$item = $this->items->lockById($itemId, false);
				if (!$item->getActive()) {
					throw new ValidationException('inactive_item');
				}
				$trackModes[$itemId] = $item->getTrackMode();
			}
			$loc = $this->locations->lockById($locationId, false);
			if (!$loc->getActive()) {
				throw new ValidationException('inactive_location');
			}

			// B1∩C2: create skipped lot/serial SKUs, but trackMode can flip mid-
			// campaign. Adjust without lotCode would throw invalid_lot_code and
			// brick close — fail closed with a clear code instead.
			$trackDetails = [];
			foreach ($lines as $line) {
				$itemId = (int)$line->getItemId();
				if (!CycleCountSemantics::isInventurEligible($trackModes[$itemId] ?? 'none')) {
					$trackDetails[] = [
						'field' => 'itemId',
						'code' => 'track_mode_changed',
						'itemId' => $itemId,
						'lineId' => (int)$line->getId(),
					];
				}
			}
			if ($trackDetails !== []) {
				throw new ValidationException('track_mode_changed', '', $trackDetails);
			}

			// Lock every campaign balance FOR UPDATE (S2 order) *before* the
			// conflict decision. Without this, a concurrent receive/issue can
			// commit between an unlocked findPair and adjust(set) and wipe
			// mid-count stock even when acknowledgeConflicts=false (TOCTOU).
			$nowSeed = $this->clock->now();
			$pairs = [];
			foreach ($lines as $line) {
				$itemId = (int)$line->getItemId();
				$this->balances->ensureZeroRow($itemId, $locationId, $nowSeed);
				$pairs[] = ['itemId' => $itemId, 'locationId' => $locationId];
			}
			$lockedBalances = $pairs === [] ? [] : $this->balances->lockPairs($pairs);

			// UC-C2: mid-count receive/issue moves live qty off the frozen snapshot.
			// Closing without acknowledgement would set-adjust counted qty and wipe
			// those movements. Fail closed until office acknowledges.
			$conflictDetails = [];
			foreach ($lines as $line) {
				$key = (int)$line->getItemId() . ':' . $locationId;
				$current = isset($lockedBalances[$key]) ? $lockedBalances[$key]->getQty() : 0;
				if (CycleCountSemantics::hasConflict((int)$line->getSystemQty(), $current)) {
					$conflictDetails[] = [
						'field' => 'lineId',
						'code' => 'count_conflict',
						'lineId' => (int)$line->getId(),
					];
				}
			}
			if (CycleCountSemantics::closeBlockedByConflicts($conflictDetails !== [], $acknowledgeConflicts)) {
				throw new ValidationException('count_conflict', '', $conflictDetails);
			}

			$posted = [];
			$notifyItemIds = [];
			foreach ($lines as $line) {
				$locked = $this->lines->lockById((int)$line->getId(), true);
				if ($locked->getPostedMovId() !== null) {
					continue;
				}
				if ($locked->getQtyCounted() === null) {
					continue; // abandoned
				}
				// Matching count → no ledger row (AdjustSemantics rejects δ=0).
				// Skipping keeps inventur close green when the shelf matches the system.
				$key = (int)$locked->getItemId() . ':' . $locationId;
				$current = isset($lockedBalances[$key]) ? $lockedBalances[$key]->getQty() : 0;
				if ((int)$locked->getQtyCounted() === $current) {
					$locked->setUpdatedAt($this->clock->now());
					$this->lines->update($locked);
					continue;
				}
				$result = $this->movements->adjust(
					$actorUid,
					$locked->getItemId(),
					$locationId,
					'set',
					$locked->getQtyCounted(),
					null,
					'Cycle count #' . $campaignId,
					null,
					false, // defer low-stock notify until after inventur TX commits
					'inventur',
				);
				$movId = (int)($result['movements'][0]['id'] ?? 0);
				$locked->setPostedMovId($movId > 0 ? $movId : null);
				$locked->setUpdatedAt($this->clock->now());
				$this->lines->update($locked);
				// Keep local map coherent if a later line somehow shared a pair.
				if (isset($lockedBalances[$key])) {
					$lockedBalances[$key]->setQty((int)$locked->getQtyCounted());
				}
				$posted[] = $movId;
				$notifyItemIds[(int)$locked->getItemId()] = true;
			}

			$now = $this->clock->now();
			$camp->setStatus(self::STATUS_CLOSED);
			$camp->setClosedAt($now);
			$camp->setUpdatedAt($now);
			$this->campaigns->update($camp);
			$this->db->commit();
			foreach (array_keys($notifyItemIds) as $itemId) {
				$this->movements->notifyLowStockAfterChange($itemId);
			}
			$api = $this->get($actorUid, $campaignId, Pagination::MAX_LIMIT, 0);
			$api['postedMovementIds'] = $posted;
			return $api;
		} catch (\Throwable $e) {
			if ($this->db->inTransaction()) {
				$this->db->rollBack();
			}
			throw $e;
		}
	}

	/**
	 * Map ACL denial onto the same not-found code as a missing row so callers
	 * cannot probe whether a campaign/line exists outside their location grants.
	 */
	private function assertAccessibleOrNotFound(string $actorUid, int $locationId, string $notFoundCode): void
	{
		try {
			$this->locationAcl->assertCanAccess($actorUid, $locationId);
		} catch (NotFoundException) {
			throw new NotFoundException($notFoundCode);
		}
	}
}
