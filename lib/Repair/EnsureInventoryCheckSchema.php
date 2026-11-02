<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Repair;

use OCA\InventoryCheck\Service\DemoInventory;
use OCA\InventoryCheck\Service\ReasonCodes;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use OCP\Server;
use OC\DB\Connection;
use OC\DB\MigrationService;

final class EnsureInventoryCheckSchema implements IRepairStep
{
	public const DEMO_SEEDED_KEY = 'demo_seeded';

	public const SEED_LOCATIONS = DemoInventory::LOCATIONS;
	public const SEED_ITEMS = DemoInventory::ITEMS;

	public function __construct(
		private readonly IDBConnection $connection,
		private readonly IConfig $config,
	) {
	}

	public function getName(): string
	{
		return 'Ensure InventoryCheck database schema is complete';
	}

	public function run(IOutput $output): void
	{
		$this->config->deleteAppValue(UninstallDropTables::APP_ID, UninstallDropTables::REPAIR_PASS_KEY);

		$missingBefore = $this->missingTables();
		if ($missingBefore !== []) {
			$output->info(sprintf(
				'InventoryCheck: %d table(s) missing (%s); running pending migrations.',
				count($missingBefore),
				implode(', ', $missingBefore),
			));

			$migrationService = new MigrationService(
				UninstallDropTables::APP_ID,
				Server::get(Connection::class),
			);
			$migrationService->migrate('latest', false);

			$missingAfter = $this->missingTables();
			if ($missingAfter !== []) {
				throw new \RuntimeException(sprintf(
					'InventoryCheck schema is still incomplete after migrate("latest"). Missing: %s.',
					implode(', ', $missingAfter),
				));
			}
		}

		$seeded = 0; // DemoInventory seeds a private workspace once per account.
		$output->info(sprintf(
			'InventoryCheck: all %d tables are present; seeded %d demo row(s).',
			count(UninstallDropTables::TABLES),
			$seeded,
		));
	}


	/** @return list<string> */
	private function missingTables(): array
	{
		$missing = [];
		foreach (UninstallDropTables::TABLES as $table) {
			if (!$this->connection->tableExists($table)) {
				$missing[] = $table;
			}
		}
		return $missing;
	}
}
