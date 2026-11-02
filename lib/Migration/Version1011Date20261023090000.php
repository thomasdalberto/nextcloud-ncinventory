<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/** Preserve existing ledger units and policy while separating workspace settings. */
class Version1011Date20261023090000 extends SimpleMigrationStep
{
	private const WORKSPACE_KEYS = [
		'qty_scale', 'allow_negative_stock', 'require_adjust_reason', 'require_location_scan',
		'location_reorder_hint_enabled', 'location_acl_enabled', 'location_acl_devices_strict',
		'low_stock_notify_user_ids', 'maint_flange_enabled', 'project_flange_enabled',
		'invoice_flange_enabled', 'flange_default_location_id',
	];

	public function __construct(private readonly IDBConnection $db, private readonly IConfig $config) {
	}

	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
	{
		$schema = $schemaClosure();
		if (!$schema->hasTable('iv_ws_settings')) {
			$table = $schema->createTable('iv_ws_settings');
			$table->addColumn('workspace_id', Types::BIGINT, ['notnull' => true]);
			$table->addColumn('setting_key', Types::STRING, ['length' => 64, 'notnull' => true]);
			$table->addColumn('setting_value', Types::TEXT, ['notnull' => true]);
			$table->setPrimaryKey(['workspace_id', 'setting_key'], 'iv_ws_settings_pk');
		}
		foreach (['iv_license_state', 'iv_mobile_seats'] as $table) {
			if ($schema->hasTable($table)) { $schema->dropTable($table); }
		}
		return $schema;
	}

	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void
	{
		$result = $this->db->getQueryBuilder()->select('id')->from('iv_workspaces')->executeQuery();
		$ids = $result->fetchAll();
		$result->closeCursor();
		foreach (self::WORKSPACE_KEYS as $key) {
			$value = $this->config->getAppValue('inventorycheck', $key, '');
			if ($value === '') { continue; }
			foreach ($ids as $row) {
				$this->db->insertIgnoreConflict('iv_ws_settings', [
					'workspace_id' => (int)$row['id'], 'setting_key' => $key, 'setting_value' => $value,
				]);
			}
			$this->config->deleteAppValue('inventorycheck', $key);
		}
		foreach (['app_admin_user_ids', 'access_restriction_enabled', 'access_allowed_user_ids',
			'access_allowed_group_ids', 'office_user_ids', 'office_group_ids', 'low_stock_notify_group_ids'] as $key) {
			$this->config->deleteAppValue('inventorycheck', $key);
		}
	}
}
