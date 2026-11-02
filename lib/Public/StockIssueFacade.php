<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Public;

use OCA\InventoryCheck\AppInfo\Application;
use OCA\InventoryCheck\Db\ItemMapper;
use OCA\InventoryCheck\Db\LocationMapper;
use OCA\InventoryCheck\Db\MovementMapper;
use OCA\InventoryCheck\Exception\InsufficientStockException;
use OCA\InventoryCheck\Exception\NotFoundException;
use OCA\InventoryCheck\Exception\ValidationException;
use OCA\InventoryCheck\Service\CodeRules;
use OCA\InventoryCheck\Service\FlangeService;
use OCA\InventoryCheck\Service\MovementService;
use OCA\InventoryCheck\Service\QtyScale;
use OCP\DB\Exception as DbException;
use OCA\InventoryCheck\Service\WorkspaceSettings;
use OCP\IDBConnection;

/**
 * Server-side stock issue flange (CHECK-SUITE FC-IV-ISSUE / §4.4.1).
 *
 * All-or-nothing for a WO's mapped SKU set. Idempotent on (refType, refId, sku).
 * Never a public HTTP surface.
 *
 * Line `qty` is always a **display** quantity (whole pieces from MN/PC kits).
 * This facade converts display → storage via {@see QtyScale::toStorage} so
 * fractional enable (scale=3) does not silently under-issue kits.
 */
class StockIssueFacade
{
	public const FACADE_VERSION = 1;

	/** @var list<string> */
	private const SUPPORTED_REF_TYPES = [
		StockIssueRequest::REF_MAINT_WO,
		StockIssueRequest::REF_PROJECT,
	];

	public function __construct(
		private readonly IDBConnection $db,
		private readonly MovementService $movements,
		private readonly MovementMapper $movementMapper,
		private readonly ItemMapper $items,
		private readonly LocationMapper $locations,
		private readonly WorkspaceSettings $settings,
	) {
	}

	public function issueBySkuBundle(StockIssueRequest $req): FacadeResult
	{
		if (trim($req->actorUid) === '') {
			return FacadeResult::failure('validation_failed', 'actorUid is required.');
		}
		if (!in_array($req->refType, self::SUPPORTED_REF_TYPES, true)) {
			return FacadeResult::failure('validation_failed', 'refType must be maint_wo or project.');
		}
		// Wave B2 / C5: IV opt-in toggles gate the in-process facade too —
		// not only FlangeController HTTP. Otherwise MN/PC could post stock
		// while Settings shows the flange as off (split-brain).
		if ($req->refType === StockIssueRequest::REF_MAINT_WO
			&& $this->settings->get(FlangeService::KEY_MAINT_ENABLED, '0') !== '1') {
			return FacadeResult::failure('flange_disabled', 'MaintenanceCheck flange is disabled in InventoryCheck.');
		}
		if ($req->refType === StockIssueRequest::REF_PROJECT
			&& $this->settings->get(FlangeService::KEY_PROJECT_ENABLED, '0') !== '1') {
			return FacadeResult::failure('flange_disabled', 'ProjectCheck flange is disabled in InventoryCheck.');
		}
		if ($req->refId <= 0) {
			return FacadeResult::failure('validation_failed', 'refId must be > 0.');
		}
		if ($req->lines === []) {
			return FacadeResult::failure('validation_failed', 'lines must not be empty.');
		}

		$normalized = [];
		foreach ($req->lines as $line) {
			$sku = CodeRules::trim((string)($line['sku'] ?? ''));
			if ($sku === '') {
				continue; // unmapped kit lines ignored by F6
			}
			try {
				$storageQty = QtyScale::toStorage($this->settings, $line['qty'] ?? 0);
			} catch (ValidationException) {
				return FacadeResult::failure('validation_failed', 'qty is not a valid display quantity.', ['sku' => $sku]);
			}
			// At least one display unit (1 pcs / 1.000 when fractional).
			if ($storageQty < QtyScale::serialUnit($this->settings)) {
				return FacadeResult::failure('validation_failed', 'qty must be >= 1.', ['sku' => $sku]);
			}
			$normalized[$sku] = ($normalized[$sku] ?? 0) + $storageQty;
		}
		if ($normalized === []) {
			return FacadeResult::success(['movements' => []]);
		}

		$locationId = $this->resolveLocation($req);
		if ($locationId === null) {
			return FacadeResult::failure('location_unresolved', 'Could not resolve issue location.', [
				'policy' => $req->locationPolicy,
			]);
		}

		$resolved = [];
		foreach ($normalized as $sku => $qty) {
			$item = $this->items->findBySku($sku);
			if ($item === null || !$item->getActive()) {
				return FacadeResult::failure('not_found', 'Unknown or inactive SKU.', ['sku' => $sku]);
			}
			$resolved[] = [
				'sku' => $sku,
				'qty' => $qty,
				'itemId' => (int)$item->getId(),
			];
		}

		// Idempotency: classify prior state for this ref.
		$priorBySku = [];
		foreach ($this->movementMapper->findByRef($req->refType, $req->refId) as $mov) {
			$item = null;
			try {
				$item = $this->items->findById((int)$mov->getItemId());
			} catch (\Throwable) {
				continue;
			}
			$sku = (string)$item->getSku();
			$priorBySku[$sku] = $mov;
		}

		$needed = [];
		$replay = [];
		foreach ($resolved as $row) {
			$sku = $row['sku'];
				if (isset($priorBySku[$sku])) {
				$mov = $priorBySku[$sku];
				$replay[] = [
					'sku' => $sku,
					'qty' => QtyScale::toDisplay($this->settings, $row['qty']),
					'movementId' => (int)$mov->getId(),
					'locationId' => (int)$mov->getLocationId(),
				];
			} else {
				$needed[] = $row;
			}
		}

		// Partial prior state for this SKU set without completing the bundle → fail soft.
		if ($replay !== [] && $needed !== [] && count($priorBySku) > 0) {
			$expectedSkus = array_keys($normalized);
			$priorSkus = array_keys($priorBySku);
			sort($expectedSkus);
			sort($priorSkus);
			if ($expectedSkus !== $priorSkus && $needed !== []) {
				// Some but not all of *this* call's SKUs already issued — do not double-issue.
				$onlyPrior = array_diff(array_keys($priorBySku), array_keys($normalized));
				if ($onlyPrior === [] && count($replay) !== count($normalized)) {
					return FacadeResult::failure('inventory_sync_failed', 'Partial prior issue state for this ref.', [
						'prior' => array_keys($priorBySku),
					]);
				}
			}
		}

		if ($needed === []) {
			return FacadeResult::success(['movements' => $replay], 'idempotent_replay');
		}

		// Global lock order: item_id ascending (matches MovementService / inventur close).
		// Unsorted SKU map order ABBA-deadlocks against concurrent multi-item TXs.
		usort(
			$needed,
			static fn (array $a, array $b): int => $a['itemId'] <=> $b['itemId'],
		);

		return $this->postAllOrNothing($req, $locationId, $needed, $replay);
	}

	/**
	 * @param list<array{sku: string, qty: int, itemId: int}> $needed
	 * @param list<array{sku: string, qty: int, movementId: int, locationId: int}> $replay
	 */
	private function postAllOrNothing(
		StockIssueRequest $req,
		int $locationId,
		array $needed,
		array $replay,
	): FacadeResult {
		// Preflight: ensure every line can succeed (stock + location) before posting any.
		foreach ($needed as $row) {
			try {
				$loc = $this->locations->findById($locationId);
				if (!$loc->getActive()) {
					return FacadeResult::failure('location_unresolved', 'Issue location is inactive.');
				}
			} catch (NotFoundException) {
				return FacadeResult::failure('not_found', 'Issue location not found.');
			}
		}

		$posted = [];
		$notifyItemIds = [];
		$failedSku = '';
		try {
			$this->db->beginTransaction();
			foreach ($needed as $row) {
				$failedSku = $row['sku'];
				// Re-check idempotency inside TX
				$existing = $this->movementMapper->findByRefAndItemId($req->refType, $req->refId, $row['itemId']);
				if ($existing !== null) {
					$posted[] = [
						'sku' => $row['sku'],
						'qty' => QtyScale::toDisplay($this->settings, $row['qty']),
						'movementId' => (int)$existing->getId(),
						'locationId' => (int)$existing->getLocationId(),
					];
					continue;
				}
				$result = $this->movements->issueWithRef(
					$req->actorUid,
					$row['itemId'],
					$locationId,
					$row['qty'],
					$this->reasonFor($req),
					$req->refType,
					$req->refId,
					false, // defer notify until outer TX commits (no phantom alerts)
				);
				$mov = $result['movements'][0] ?? null;
				$posted[] = [
					'sku' => $row['sku'],
					'qty' => QtyScale::toDisplay($this->settings, $row['qty']),
					'movementId' => (int)($mov['id'] ?? 0),
					'locationId' => $locationId,
				];
				$notifyItemIds[] = $row['itemId'];
			}
			$this->db->commit();
		} catch (InsufficientStockException $e) {
			if ($this->db->inTransaction()) {
				$this->db->rollBack();
			}
			return FacadeResult::failure('insufficient_stock', $e->getMessage(), [
				'sku' => $failedSku,
			]);
		} catch (ValidationException $e) {
			if ($this->db->inTransaction()) {
				$this->db->rollBack();
			}
			return FacadeResult::failure('validation_failed', $e->getMessage());
		} catch (NotFoundException $e) {
			if ($this->db->inTransaction()) {
				$this->db->rollBack();
			}
			return FacadeResult::failure('not_found', $e->getMessage());
		} catch (DbException $e) {
			if ($this->db->inTransaction()) {
				$this->db->rollBack();
			}
			// Concurrent F6: unique (ref_type, ref_id, item_id) won — treat as idempotent.
			if ($e->getReason() === DbException::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
				return $this->replayAfterUniqueRace($req, $replay);
			}
			return FacadeResult::failure('inventory_sync_failed', $e->getMessage());
		} catch (\Throwable $e) {
			if ($this->db->inTransaction()) {
				$this->db->rollBack();
			}
			if ($this->isUniqueConstraintViolation($e)) {
				return $this->replayAfterUniqueRace($req, $replay);
			}
			return FacadeResult::failure('inventory_sync_failed', $e->getMessage());
		}

		foreach (array_values(array_unique($notifyItemIds)) as $itemId) {
			$this->movements->notifyLowStockAfterChange($itemId);
		}

		return FacadeResult::success(['movements' => array_merge($replay, $posted)]);
	}

	/**
	 * @param list<array{sku: string, qty: int, movementId: int, locationId: int}> $replay
	 */
	private function replayAfterUniqueRace(StockIssueRequest $req, array $replay): FacadeResult
	{
		$posted = [];
		foreach ($this->movementMapper->findByRef($req->refType, $req->refId) as $mov) {
			try {
				$item = $this->items->findById((int)$mov->getItemId());
			} catch (\Throwable) {
				continue;
			}
			$posted[] = [
				'sku' => (string)$item->getSku(),
				'qty' => QtyScale::toDisplay($this->settings, abs((int)$mov->getQtyDelta())),
				'movementId' => (int)$mov->getId(),
				'locationId' => (int)$mov->getLocationId(),
			];
		}
		if ($posted === [] && $replay === []) {
			return FacadeResult::failure('inventory_sync_failed', 'Concurrent issue conflict without recoverable rows.');
		}
		return FacadeResult::success(['movements' => array_merge($replay, $posted)], 'idempotent_replay');
	}

	private function isUniqueConstraintViolation(\Throwable $e): bool
	{
		if ($e instanceof DbException && $e->getReason() === DbException::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
			return true;
		}
		$previous = $e->getPrevious();
		return $previous instanceof \Throwable && $this->isUniqueConstraintViolation($previous);
	}

	private function reasonFor(StockIssueRequest $req): string
	{
		return match ($req->refType) {
			StockIssueRequest::REF_PROJECT => 'Project #' . $req->refId,
			default => 'Maint WO #' . $req->refId,
		};
	}

	private function resolveLocation(StockIssueRequest $req): ?int
	{
		$policy = $req->locationPolicy;
		if ($policy === StockIssueRequest::POLICY_EXPLICIT) {
			return ($req->locationId !== null && $req->locationId > 0) ? $req->locationId : null;
		}
		if ($policy === StockIssueRequest::POLICY_FAIL_AMBIGUOUS) {
			// Explicit id from caller wins; otherwise exactly one active location
			// is unambiguous. Zero or two+ active locations → soft-fail.
			if ($req->locationId !== null && $req->locationId > 0) {
				return $req->locationId;
			}
			$probe = $this->locations->search(true, 2, 0);
			if ($probe['total'] === 1 && isset($probe['data'][0])) {
				return (int)$probe['data'][0]->getId();
			}
			return null;
		}
		if ($policy === StockIssueRequest::POLICY_EQUIPMENT_DEFAULT) {
			if ($req->locationId !== null && $req->locationId > 0) {
				return $req->locationId;
			}
			$raw = $this->settings->get(FlangeService::KEY_DEFAULT_ISSUE_LOCATION, '');
			if ($raw === '' || !ctype_digit($raw)) {
				return null;
			}
			return (int)$raw;
		}
		return null;
	}
}
