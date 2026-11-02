<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Service;

use OCA\InventoryCheck\AppInfo\Application;
use OCA\InventoryCheck\Db\BalanceMapper;
use OCA\InventoryCheck\Db\ItemMapper;
use OCA\InventoryCheck\Service\WorkspaceSettings;

class LowStockService
{
	/** Wave B3: per-location reorder hints, off by default (server-wide S10 stays the default view). */
	public const KEY_LOCATION_REORDER_HINT_ENABLED = 'location_reorder_hint_enabled';

	public function __construct(
		private readonly ItemMapper $items,
		private readonly BalanceMapper $balances,
		private readonly WorkspaceSettings $settings,
		private readonly LocationAclService $locationAcl,
	) {
	}

	/**
	 * S10: active items with reorder_level > 0 and SUM(qty) < reorder_level.
	 * Wave C3: SUM only over locations the actor may see.
	 *
	 * @return array{data: list<array<string, mixed>>, total: int, limit: int, offset: int}
	 */
	public function list(string $actorUid, int $limit, int $offset): array
	{
		$visible = $this->locationAcl->visibleLocationIds($actorUid);
		$sums = $this->balances->sumQtyByItem($visible);
		$rows = [];
		foreach ($this->items->iterateActive() as $item) {
			$total = $sums[(int)$item->getId()] ?? 0;
			if (!LowStockQuery::isLowStock(true, $item->getReorderLevel(), $total)) {
				continue;
			}
			$rows[] = [
				'item' => $item->toApi(),
				'totalQty' => $total,
				'reorderLevel' => $item->getReorderLevel(),
				'targetStock' => $item->getTargetStock(),
				'suggestedQty' => SuggestedOrder::qty($total, $item->getReorderLevel(), $item->getTargetStock()),
				'deficit' => LowStockQuery::deficit($total, $item->getReorderLevel()),
			];
		}
		usort($rows, static function (array $a, array $b): int {
			if ($a['deficit'] !== $b['deficit']) {
				return $a['deficit'] <=> $b['deficit'];
			}
			return $a['item']['id'] <=> $b['item']['id'];
		});
		$total = count($rows);
		$data = array_slice($rows, $offset, $limit);
		return ['data' => $data, 'total' => $total, 'limit' => $limit, 'offset' => $offset];
	}

	public function isPerLocationHintEnabled(): bool
	{
		return $this->settings->get(self::KEY_LOCATION_REORDER_HINT_ENABLED, '0') === '1';
	}

	public function setPerLocationHintEnabled(bool $enabled): void
	{
		$this->settings->set(self::KEY_LOCATION_REORDER_HINT_ENABLED, $enabled ? '1' : '0');
	}

	/**
	 * Wave B3: same S10 predicate but evaluated per (item, location) pair
	 * instead of the item-wide sum. Only active when the app-admin toggle is on.
	 * Wave C3: field users only see locations they can access.
	 *
	 * @return array{data: list<array<string, mixed>>, total: int, limit: int, offset: int}
	 */
	public function listPerLocation(string $actorUid, int $limit, int $offset): array
	{
		if (!$this->isPerLocationHintEnabled()) {
			return ['data' => [], 'total' => 0, 'limit' => $limit, 'offset' => $offset];
		}
		$visible = $this->locationAcl->visibleLocationIds($actorUid);
		if ($visible !== null && $visible === []) {
			return ['data' => [], 'total' => 0, 'limit' => $limit, 'offset' => $offset];
		}
		$perLocationQty = $this->balances->sumQtyByItemAndLocation();
		$rows = [];
		foreach ($this->items->iterateActive() as $item) {
			$itemId = (int)$item->getId();
			$reorder = $item->getReorderLevel();
			if ($reorder <= 0 || !isset($perLocationQty[$itemId])) {
				continue;
			}
			foreach ($perLocationQty[$itemId] as $locationId => $qty) {
				if ($visible !== null && !in_array($locationId, $visible, true)) {
					continue;
				}
				if (!LowStockQuery::isLowStock(true, $reorder, $qty)) {
					continue;
				}
				$rows[] = [
					'item' => $item->toApi(),
					'locationId' => $locationId,
					'qty' => $qty,
					'reorderLevel' => $reorder,
					'deficit' => LowStockQuery::deficit($qty, $reorder),
				];
			}
		}
		usort($rows, static function (array $a, array $b): int {
			if ($a['deficit'] !== $b['deficit']) {
				return $a['deficit'] <=> $b['deficit'];
			}
			if ($a['item']['id'] !== $b['item']['id']) {
				return $a['item']['id'] <=> $b['item']['id'];
			}
			return $a['locationId'] <=> $b['locationId'];
		});
		$total = count($rows);
		$data = array_slice($rows, $offset, $limit);
		return ['data' => $data, 'total' => $total, 'limit' => $limit, 'offset' => $offset];
	}
}
