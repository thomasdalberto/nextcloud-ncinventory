<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/** Old instance-wide rows stay unassigned (workspace 0) and are never exposed to users. */
class Version1010Date20261022090000 extends SimpleMigrationStep
{
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
	{
		$schema = $schemaClosure();
		if (!$schema->hasTable('iv_workspaces')) {
			$t = $schema->createTable('iv_workspaces');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('name', Types::STRING, ['length' => 255, 'notnull' => true]);
			$t->addColumn('owner_uid', Types::STRING, ['length' => 64, 'notnull' => true]);
			$t->addColumn('demo_uid', Types::STRING, ['length' => 64, 'notnull' => false]);
			$t->addColumn('created_at', Types::INTEGER, ['notnull' => true]);
			$t->setPrimaryKey(['id'], 'iv_ws_pk');
			$t->addIndex(['owner_uid'], 'iv_ws_owner_idx');
			$t->addUniqueIndex(['demo_uid'], 'iv_ws_demo_uq');
		}
		if (!$schema->hasTable('iv_ws_members')) {
			$t = $schema->createTable('iv_ws_members');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('workspace_id', Types::BIGINT, ['notnull' => true]);
			$t->addColumn('user_id', Types::STRING, ['length' => 64, 'notnull' => true]);
			$t->addColumn('role', Types::STRING, ['length' => 16, 'notnull' => true]);
			$t->addColumn('status', Types::STRING, ['length' => 16, 'notnull' => true]);
			$t->setPrimaryKey(['id'], 'iv_wsm_pk');
			$t->addUniqueIndex(['workspace_id', 'user_id'], 'iv_wsm_user_uq');
			$t->addIndex(['user_id', 'status'], 'iv_wsm_user_idx');
		}
		// Frozen migration snapshot; future tables get their columns in their own migration.
		foreach ([
			'iv_balances', 'iv_cc_line', 'iv_cc_camp', 'iv_inv_push', 'iv_loc_acl',
			'iv_loc_fav', 'iv_movements', 'iv_notif_log', 'iv_scan_idem', 'iv_scan_devices',
			'iv_items', 'iv_locations',
		] as $name) {
			$t = $schema->getTable($name);
			if (!$t->hasColumn('workspace_id')) {
				$t->addColumn('workspace_id', Types::BIGINT, ['notnull' => true, 'default' => 0]);
				$t->addIndex(['workspace_id'], substr($name, 0, 20) . '_ws_idx');
			}
		}
		// Codes and replay keys are unique inside a workspace, never across users.
		foreach ([
			['iv_items', 'iv_item_sku_uq', ['sku']],
			['iv_items', 'iv_item_scan_uq', ['scan_code']],
			['iv_locations', 'iv_loc_code_uq', ['code']],
			['iv_movements', 'iv_mov_ref_uq', ['ref_type', 'ref_id', 'item_id']],
			['iv_scan_idem', 'iv_scan_idem_uq', ['actor_uid', 'request_id']],
		] as [$name, $index, $columns]) {
			$t = $schema->getTable($name);
			if ($t->hasIndex($index)) {
				$t->dropIndex($index);
			}
			$t->addUniqueIndex(array_merge(['workspace_id'], $columns), $index);
		}
		return $schema;
	}
}
