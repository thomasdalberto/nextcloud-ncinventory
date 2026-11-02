<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Service;

use OCA\InventoryCheck\Db\BalanceMapper;
use OCA\InventoryCheck\Db\ItemMapper;
use OCA\InventoryCheck\Db\LocationMapper;
use OCA\InventoryCheck\Db\MovementMapper;
use OCA\InventoryCheck\Exception\PermissionDeniedException;
use OCA\InventoryCheck\Exception\ValidationException;
use OCA\InventoryCheck\Util\Csv;
use OCA\InventoryCheck\Service\WorkspaceSettings;

/**
 * CSV export for items, locations, balances, movements (Wave A1 / B5).
 *
 * Wave C1: quantity columns are written as display values so a round-trip
 * through {@see CsvImportService} stays correct under qty_scale=3.
 */
class CsvExportService
{
	public function __construct(
		private readonly ItemMapper $items,
		private readonly LocationMapper $locations,
		private readonly BalanceMapper $balances,
		private readonly MovementMapper $movements,
		private readonly AccessControlService $access,
		private readonly WorkspaceSettings $settings,
	) {
	}

	/**
	 * @return array{filename: string, body: string, contentType: string}
	 */
	public function export(
		string $actorUid,
		string $kind,
		?int $itemId,
		?int $locationId,
		?int $from,
		?int $to,
		string $lang = 'en',
		?string $reasonCode = null,
	): array {
		$kind = strtolower(trim($kind));
		return match ($kind) {
			'items' => $this->exportItems($lang),
			'locations' => $this->exportLocations($lang),
			'balances' => $this->exportBalances($itemId, $locationId, $lang),
			'movements' => $this->exportMovements($actorUid, $itemId, $locationId, $from, $to, false, $lang),
			'movements_datev' => $this->exportMovements($actorUid, $itemId, $locationId, $from, $to, true, $lang),
			'reorder' => $this->exportReorder($actorUid, $lang),
			'variance' => $this->exportVariance($actorUid, $itemId, $locationId, $from, $to, $lang, $reasonCode),
			default => throw new ValidationException('validation_failed', '', [
				['field' => 'kind', 'code' => 'validation_failed'],
			]),
		};
	}

	/** Wave D5 — office+ */
	private function exportReorder(string $actorUid, string $lang): array
	{
		if (!$this->access->canWrite($actorUid)) {
			throw new PermissionDeniedException();
		}
		$sums = $this->balances->sumQtyByItem(null);
		$all = $this->items->search('', true, Csv::MAX_EXPORT_ROWS, 0);
		$rows = [];
		foreach ($all['data'] as $item) {
			$total = $sums[(int)$item->getId()] ?? 0;
			if (!LowStockQuery::isLowStock(true, $item->getReorderLevel(), $total)) {
				continue;
			}
			$suggested = SuggestedOrder::qty($total, $item->getReorderLevel(), $item->getTargetStock());
			$api = QtyScale::formatItem($item->toApi(), $this->settings);
			$rows[] = [
				$api['sku'],
				$api['name'],
				QtyScale::toDisplay($this->settings, $total),
				$api['reorderLevel'],
				$api['targetStock'] ?? '',
				QtyScale::toDisplay($this->settings, $suggested),
				$api['supplierNote'] ?? '',
				$api['uom'],
			];
		}
		$this->assertExportFits(count($rows));
		$headers = Csv::localizeHeaders(
			['sku', 'name', 'on_hand', 'reorder_level', 'target_stock', 'suggested_qty', 'supplier_note', 'uom'],
			$lang,
		);
		$body = Csv::line($headers);
		foreach ($rows as $row) {
			$body .= Csv::line($row);
		}
		return $this->pack('inventorycheck-reorder.csv', $body);
	}

	/** Wave D6 — office+ adjust movements */
	private function exportVariance(
		string $actorUid,
		?int $itemId,
		?int $locationId,
		?int $from,
		?int $to,
		string $lang,
		?string $reasonCode = null,
	): array {
		if (!$this->access->canWrite($actorUid)) {
			throw new PermissionDeniedException();
		}
		$result = $this->movements->search(
			'adjust',
			$itemId,
			$locationId,
			$from,
			$to,
			null,
			Csv::MAX_EXPORT_ROWS,
			0,
			null,
			$reasonCode,
		);
		$this->assertExportFits($result['total']);
		$headers = Csv::localizeHeaders(
			['id', 'item_id', 'location_id', 'qty_delta', 'qty_after', 'reason_code', 'reason', 'created_at', 'created_by'],
			$lang,
		);
		$body = Csv::line($headers);
		foreach ($result['data'] as $mov) {
			$api = QtyScale::formatMovement($mov->toApi(), $this->settings);
			$body .= Csv::line([
				$api['id'],
				$api['itemId'],
				$api['locationId'],
				$api['qtyDelta'],
				$api['qtyAfter'],
				$api['reasonCode'] ?? '',
				$api['reason'] ?? '',
				$api['createdAt'],
				$api['createdBy'],
			]);
		}
		return $this->pack('inventorycheck-variance.csv', $body);
	}

	/** @return array{filename: string, body: string, contentType: string} */
	private function exportItems(string $lang): array
	{
		$result = $this->items->search('', null, Csv::MAX_EXPORT_ROWS, 0);
		$this->assertExportFits($result['total']);
		$headers = Csv::localizeHeaders(
			['sku', 'scan_code', 'name', 'description', 'uom', 'reorder_level', 'active', 'supplier_note', 'last_price_minor'],
			$lang,
		);
		$body = Csv::line($headers);
		foreach ($result['data'] as $item) {
			$api = QtyScale::formatItem($item->toApi(), $this->settings);
			$body .= Csv::line([
				$api['sku'],
				$api['scanCode'],
				$api['name'],
				$api['description'] ?? '',
				$api['uom'],
				$api['reorderLevel'],
				$api['active'] ? '1' : '0',
				$api['supplierNote'] ?? '',
				$api['lastPriceMinor'] ?? '',
			]);
		}
		return $this->pack('inventorycheck-items.csv', $body);
	}

	/** @return array{filename: string, body: string, contentType: string} */
	private function exportLocations(string $lang): array
	{
		$result = $this->locations->search(null, Csv::MAX_EXPORT_ROWS, 0);
		$this->assertExportFits($result['total']);
		$headers = Csv::localizeHeaders(['code', 'name', 'kind', 'notes', 'active'], $lang);
		$body = Csv::line($headers);
		foreach ($result['data'] as $loc) {
			$api = $loc->toApi();
			$body .= Csv::line([
				$api['code'],
				$api['name'],
				$api['kind'],
				$api['notes'] ?? '',
				$api['active'] ? '1' : '0',
			]);
		}
		return $this->pack('inventorycheck-locations.csv', $body);
	}

	/** @return array{filename: string, body: string, contentType: string} */
	private function exportBalances(?int $itemId, ?int $locationId, string $lang): array
	{
		$result = $this->balances->search($itemId, $locationId, false, Csv::MAX_EXPORT_ROWS, 0);
		$this->assertExportFits($result['total']);
		$headers = Csv::localizeHeaders(['item_id', 'location_id', 'qty', 'updated_at'], $lang);
		$body = Csv::line($headers);
		foreach ($result['data'] as $bal) {
			$api = QtyScale::formatBalance($bal->toApi(), $this->settings);
			$body .= Csv::line([
				$api['itemId'],
				$api['locationId'],
				$api['qty'],
				$api['updatedAt'],
			]);
		}
		return $this->pack('inventorycheck-balances.csv', $body);
	}

	/**
	 * @return array{filename: string, body: string, contentType: string}
	 */
	private function exportMovements(
		string $actorUid,
		?int $itemId,
		?int $locationId,
		?int $from,
		?int $to,
		bool $datevStyle,
		string $lang,
	): array {
		if (!$this->access->canWrite($actorUid)) {
			throw new PermissionDeniedException();
		}
		$result = $this->movements->search(null, $itemId, $locationId, $from, $to, null, Csv::MAX_EXPORT_ROWS, 0);
		$this->assertExportFits($result['total']);
		if ($datevStyle) {
			$headers = Csv::localizeHeaders(
				['movement_id', 'item_id', 'location_id', 'kind', 'qty_delta', 'qty_after', 'created_at'],
				$lang,
			);
			$body = Csv::line($headers);
			foreach ($result['data'] as $mov) {
				$api = QtyScale::formatMovement($mov->toApi(), $this->settings);
				$body .= Csv::line([
					$api['id'],
					$api['itemId'],
					$api['locationId'],
					$api['kind'],
					$api['qtyDelta'],
					$api['qtyAfter'],
					$api['createdAt'],
				]);
			}
			return $this->pack('inventorycheck-movements-qty.csv', $body);
		}
		$headers = Csv::localizeHeaders(
			['id', 'item_id', 'location_id', 'kind', 'qty_delta', 'qty_after', 'transfer_group', 'counterparty_loc_id', 'reason', 'ref_type', 'ref_id', 'created_at', 'created_by'],
			$lang,
		);
		$body = Csv::line($headers);
		foreach ($result['data'] as $mov) {
			$api = QtyScale::formatMovement($mov->toApi(), $this->settings);
			$body .= Csv::line([
				$api['id'],
				$api['itemId'],
				$api['locationId'],
				$api['kind'],
				$api['qtyDelta'],
				$api['qtyAfter'],
				$api['transferGroup'] ?? '',
				$api['counterpartyLocId'] ?? '',
				$api['reason'] ?? '',
				$api['refType'] ?? '',
				$api['refId'] ?? '',
				$api['createdAt'],
				$api['createdBy'],
			]);
		}
		return $this->pack('inventorycheck-movements.csv', $body);
	}

	/**
	 * @return array{filename: string, body: string, contentType: string}
	 */
	private function pack(string $filename, string $body): array
	{
		return [
			'filename' => $filename,
			'body' => Csv::withBom($body),
			'contentType' => 'text/csv; charset=UTF-8',
		];
	}

	/**
	 * Wave A1: never silently truncate. Over-cap exports fail loudly so
	 * offices narrow filters instead of mistaking a partial file for a full audit.
	 */
	private function assertExportFits(int $total): void
	{
		if ($total > Csv::MAX_EXPORT_ROWS) {
			throw new ValidationException('export_too_large', 'Export exceeds ' . Csv::MAX_EXPORT_ROWS . ' rows. Narrow filters and retry.', [
				['field' => 'kind', 'code' => 'export_too_large'],
			]);
		}
	}
}
