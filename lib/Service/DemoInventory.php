<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Service;

use OCP\IDBConnection;

/** A private copy of the original demo, created once for each account. */
class DemoInventory
{
	public const LOCATIONS = [
		['code' => 'WH', 'name' => 'Warehouse', 'kind' => 'warehouse'],
		['code' => 'VAN-1', 'name' => 'Van 1', 'kind' => 'van'],
		['code' => 'SITE-A', 'name' => 'Site box A', 'kind' => 'site'],
	];
	public const ITEMS = [
		['sku' => 'FILTER-42', 'name' => 'Air filter 42', 'uom' => 'pcs', 'reorder' => 5],
		['sku' => 'SEAL-10', 'name' => 'Seal kit 10', 'uom' => 'pcs', 'reorder' => 10],
		['sku' => 'CABLE-5M', 'name' => 'Cable 5 m', 'uom' => 'pcs', 'reorder' => 20],
	];

	public function __construct(private readonly IDBConnection $db) {
	}

	public function seed(int $workspaceId, string $uid): void
	{
		$common = ['workspace_id' => $workspaceId, 'active' => true,
			'created_at' => time(), 'updated_at' => time(), 'created_by' => $uid];
		foreach (self::LOCATIONS as $row) {
			$this->db->insertIgnoreConflict('iv_locations', array_merge($common, $row));
		}
		foreach (self::ITEMS as $row) {
			$reorder = $row['reorder'];
			unset($row['reorder']);
			$this->db->insertIgnoreConflict('iv_items', array_merge($common, $row,
				['scan_code' => $row['sku'], 'reorder_level' => $reorder]));
		}
	}
}
