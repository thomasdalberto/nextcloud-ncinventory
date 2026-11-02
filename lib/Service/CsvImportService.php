<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Service;

use OCA\InventoryCheck\Db\Item;
use OCA\InventoryCheck\Db\ItemMapper;
use OCA\InventoryCheck\Db\LocationMapper;
use OCA\InventoryCheck\Db\UniqueViolation;
use OCA\InventoryCheck\Exception\ConflictException;
use OCA\InventoryCheck\Exception\ValidationException;
use OCA\InventoryCheck\Util\Csv;
use OCA\InventoryCheck\Service\WorkspaceSettings;
use OCP\IDBConnection;
use OCP\Lock\ILockingProvider;
use OCP\Lock\LockedException;

/**
 * CSV item import with dry-run and atomic commit (Wave A2).
 *
 * Opening balances post as receive movements — ledger invariant preserved.
 * Wave C1: reorder_level and opening_qty are display values converted to
 * storage via {@see QtyScale::toStorage}.
 */
class CsvImportService
{
	public function __construct(
		private readonly IDBConnection $db,
		private readonly ItemMapper $items,
		private readonly LocationMapper $locations,
		private readonly MovementService $movements,
		private readonly AccessControlService $access,
		private readonly Clock $clock,
		private readonly ILockingProvider $locking,
		private readonly WorkspaceSettings $settings,
	) {
	}

	/**
	 * @return array{ok: int, errors: list<array{line: int, code: string, message: string}>}
	 */
	public function dryRun(string $actorUid, string $csvText): array
	{
		$this->access->requireWrite($actorUid);
		$report = $this->validateAll($csvText, false);
		return ['ok' => $report['ok'], 'errors' => $report['errors']];
	}

	/**
	 * @return array{ok: int, created: int, received: int, skipped: int, errors: list<array{line: int, code: string, message: string}>}
	 */
	public function commit(string $actorUid, string $csvText, bool $skipErrors = false): array
	{
		$this->access->requireWrite($actorUid);
		$report = $this->validateAll($csvText, true);
		if ($report['errors'] !== [] && !$skipErrors) {
			return [
				'ok' => 0,
				'created' => 0,
				'received' => 0,
				'skipped' => 0,
				'errors' => $report['errors'],
			];
		}

		$created = 0;
		$received = 0;
		$skipped = count($report['errors']);
		/** @var list<array{sku: string, scan_code: string, name: string, description: ?string, uom: string, reorder_level: int, active: bool, supplier_note: ?string, last_price_minor: ?int, opening_qty: ?int, opening_location_id: ?int}> $rows */
		$rows = $report['rows'] ?? [];
		/** @var list<int> $notifyItemIds */
		$notifyItemIds = [];

		$this->withCodesLock(function () use ($actorUid, $rows, &$created, &$received, &$notifyItemIds): void {
			$this->db->beginTransaction();
			try {
				foreach ($rows as $row) {
					$now = $this->clock->now();
					$item = new Item();
					$item->setSku($row['sku']);
					$item->setScanCode($row['scan_code']);
					$item->setName($row['name']);
					$item->setDescription($row['description']);
					$item->setUom($row['uom']);
					$item->setReorderLevel($row['reorder_level']);
					$item->setActive($row['active']);
					$item->setCreatedAt($now);
					$item->setUpdatedAt($now);
					$item->setCreatedBy($actorUid);
					$item->setSupplierNote($row['supplier_note']);
					$item->setLastPriceMinor($row['last_price_minor']);
					try {
						$item = $this->items->insert($item);
					} catch (\Throwable $e) {
						throw UniqueViolation::is($e) ? new ConflictException('code_exists') : $e;
					}
					$created++;
					if ($row['opening_qty'] !== null && $row['opening_location_id'] !== null) {
						// Defer low-stock notify until after the outer import TX
						// commits — same class as inventur/flange (no phantoms).
						$this->movements->receive(
							$actorUid,
							(int)$item->getId(),
							(int)$row['opening_location_id'],
							(int)$row['opening_qty'],
							'CSV opening balance',
							null,
							false,
						);
						$notifyItemIds[] = (int)$item->getId();
						$received++;
					}
				}
				$this->db->commit();
			} catch (\Throwable $e) {
				if ($this->db->inTransaction()) {
					$this->db->rollBack();
				}
				throw $e;
			}
		});

		foreach (array_unique($notifyItemIds) as $itemId) {
			$this->movements->notifyLowStockAfterChange($itemId);
		}

		return [
			'ok' => count($rows),
			'created' => $created,
			'received' => $received,
			'skipped' => $skipped,
			'errors' => $skipErrors ? $report['errors'] : [],
		];
	}

	/**
	 * @return array{
	 *   ok: int,
	 *   errors: list<array{line: int, code: string, message: string}>,
	 *   rows?: list<array{sku: string, scan_code: string, name: string, description: ?string, uom: string, reorder_level: int, active: bool, supplier_note: ?string, last_price_minor: ?int, opening_qty: ?int, opening_location_id: ?int}>
	 * }
	 */
	private function validateAll(string $csvText, bool $includeRows): array
	{
		$parsed = Csv::parse($csvText);
		if ($parsed === []) {
			throw new ValidationException('validation_failed', 'CSV has no data rows.', [
				['field' => 'csv', 'code' => 'empty'],
			]);
		}
		if (count($parsed) > Csv::MAX_IMPORT_ROWS) {
			throw new ValidationException('validation_failed', 'Too many rows.', [
				['field' => 'csv', 'code' => 'too_many_rows'],
			]);
		}

		$existing = $this->items->allCodePairs();
		$seenSku = [];
		$seenScan = [];
		$errors = [];
		$rows = [];

		foreach ($parsed as $i => $raw) {
			$line = $i + 2;
			$sku = CodeRules::trim((string)($raw['sku'] ?? ''));
			$scan = CodeRules::trim((string)($raw['scan_code'] ?? $raw['scancode'] ?? ''));
			if ($scan === '') {
				$scan = $sku;
			}
			$name = CodeRules::trim((string)($raw['name'] ?? ''));
			$uom = CodeRules::trim((string)($raw['uom'] ?? 'pcs'));
			if ($uom === '') {
				$uom = 'pcs';
			}
			$desc = CodeRules::trim((string)($raw['description'] ?? ''));
			$desc = $desc === '' ? null : $desc;
			if ($desc !== null && mb_strlen($desc) > 10000) {
				$errors[] = ['line' => $line, 'code' => 'invalid_description', 'message' => 'description too long'];
				continue;
			}
			$supplierNote = CodeRules::trim((string)($raw['supplier_note'] ?? $raw['lieferant'] ?? ''));
			$supplierNote = $supplierNote === '' ? null : $supplierNote;
			if ($supplierNote !== null && mb_strlen($supplierNote) > 255) {
				$errors[] = ['line' => $line, 'code' => 'invalid_supplier_note', 'message' => 'supplier_note too long'];
				continue;
			}
			$lastPriceRaw = $raw['last_price_minor'] ?? '';
			$lastPriceMinor = null;
			if ($lastPriceRaw !== '') {
				if (!preg_match('/^-?\d+$/', (string)$lastPriceRaw)) {
					$errors[] = ['line' => $line, 'code' => 'invalid_last_price', 'message' => 'last_price_minor must be an integer'];
					continue;
				}
				$lastPriceMinor = (int)$lastPriceRaw;
				if ($lastPriceMinor < 0 || $lastPriceMinor > 100_000_000) {
					$errors[] = ['line' => $line, 'code' => 'invalid_last_price', 'message' => 'last_price_minor must be ≥ 0 and ≤ 100000000'];
					continue;
				}
			}
			$activeRaw = strtolower(trim((string)($raw['active'] ?? '1')));
			$active = !in_array($activeRaw, ['0', 'false', 'no', 'nein', 'inactive'], true);

			$reorderRaw = $raw['reorder_level'] ?? $raw['reorderlevel'] ?? '0';
			try {
				$reorder = QtyScale::toStorage($this->settings, $reorderRaw === '' ? '0' : $reorderRaw);
			} catch (ValidationException) {
				$errors[] = ['line' => $line, 'code' => 'invalid_reorder', 'message' => 'reorder_level out of range'];
				continue;
			}

			if (!CodeRules::isValidSku($sku) || !CodeRules::isValidScanCode($scan)) {
				$errors[] = ['line' => $line, 'code' => 'invalid_code_format', 'message' => 'Invalid sku or scan_code'];
				continue;
			}
			if ($name === '' || mb_strlen($name) > 255) {
				$errors[] = ['line' => $line, 'code' => 'name_required', 'message' => 'Name is required'];
				continue;
			}
			if ($reorder < 0 || $reorder > QtyScale::maxStorage($this->settings)) {
				$errors[] = ['line' => $line, 'code' => 'invalid_reorder', 'message' => 'reorder_level out of range'];
				continue;
			}
			if (isset($seenSku[$sku]) || isset($seenScan[$scan]) || isset($seenSku[$scan]) || isset($seenScan[$sku])) {
				$errors[] = ['line' => $line, 'code' => 'code_exists', 'message' => 'Duplicate sku/scan_code in file'];
				continue;
			}
			if (CodeRules::conflictsWithOthers(null, $sku, $scan, $existing)) {
				$errors[] = ['line' => $line, 'code' => 'code_exists', 'message' => 'sku or scan_code already exists'];
				continue;
			}
			if (CodeRules::conflictsWithLocationCodes($sku, $scan, $this->locations->allCodes())) {
				$errors[] = ['line' => $line, 'code' => 'code_exists', 'message' => 'sku or scan_code collides with a location code'];
				continue;
			}

			$openingLocCode = CodeRules::trim((string)($raw['opening_location_code'] ?? ''));
			$openingQtyRaw = $raw['opening_qty'] ?? '';
			$openingQty = null;
			$openingLocId = null;
			if ($openingLocCode !== '' || $openingQtyRaw !== '') {
				if ($openingLocCode === '' || $openingQtyRaw === '') {
					$errors[] = ['line' => $line, 'code' => 'invalid_opening', 'message' => 'opening_location_code and opening_qty must both be set'];
					continue;
				}
				try {
					$openingQty = QtyScale::toStorage($this->settings, $openingQtyRaw);
				} catch (ValidationException) {
					$errors[] = ['line' => $line, 'code' => 'invalid_opening', 'message' => 'opening_qty must be 1…1000000'];
					continue;
				}
				if ($openingQty < 1 || $openingQty > QtyScale::maxStorage($this->settings)) {
					$errors[] = ['line' => $line, 'code' => 'invalid_opening', 'message' => 'opening_qty must be 1…1000000'];
					continue;
				}
				$loc = $this->locations->findByCode($openingLocCode);
				if ($loc === null || !$loc->getActive()) {
					$errors[] = ['line' => $line, 'code' => 'unknown_location', 'message' => 'opening location not found'];
					continue;
				}
				$openingLocId = (int)$loc->getId();
			}

			$seenSku[$sku] = true;
			$seenScan[$scan] = true;
			$existing[] = ['id' => 0, 'sku' => $sku, 'scanCode' => $scan];
			$rows[] = [
				'sku' => $sku,
				'scan_code' => $scan,
				'name' => $name,
				'description' => $desc,
				'uom' => $uom,
				'reorder_level' => $reorder,
				'active' => $active,
				'supplier_note' => $supplierNote,
				'last_price_minor' => $lastPriceMinor,
				'opening_qty' => $openingQty,
				'opening_location_id' => $openingLocId,
			];
		}

		$out = [
			'ok' => count($rows),
			'errors' => $errors,
		];
		if ($includeRows) {
			$out['rows'] = $rows;
		}
		return $out;
	}

	/**
	 * @template T
	 * @param callable(): T $fn
	 * @return T
	 */
	private function withCodesLock(callable $fn): mixed
	{
		$attempts = 0;
		while (true) {
			try {
				$this->locking->acquireLock(CodeRules::CODES_LOCK, ILockingProvider::LOCK_EXCLUSIVE);
				break;
			} catch (LockedException) {
				if (++$attempts >= 40) {
					throw new ConflictException('conflict');
				}
				usleep(25_000);
			}
		}
		try {
			return $fn();
		} finally {
			$this->locking->releaseLock(CodeRules::CODES_LOCK, ILockingProvider::LOCK_EXCLUSIVE);
		}
	}
}
