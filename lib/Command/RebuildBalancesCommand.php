<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Command;

use OCA\InventoryCheck\Db\Balance;
use OCA\InventoryCheck\Db\BalanceMapper;
use OCA\InventoryCheck\Db\MovementMapper;
use OCA\InventoryCheck\Db\WorkspaceMapper;
use OCA\InventoryCheck\Service\WorkspaceContext;
use OCP\IConfig;
use OCP\IDBConnection;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * SPEC S13: recompute iv_balances from SUM(qty_delta).
 * Exit 0 = no drift, 1 = drift fixed, 2 = refused (no maintenance / no --force).
 */
class RebuildBalancesCommand extends Command
{
	public function __construct(
		private readonly IDBConnection $db,
		private readonly IConfig $config,
		private readonly MovementMapper $movements,
		private readonly BalanceMapper $balances,
		private readonly WorkspaceContext $workspace,
		private readonly WorkspaceMapper $workspaces,
	) {
		parent::__construct();
	}

	protected function configure(): void
	{
		$this->setName('inventorycheck:rebuild-balances')
			->setDescription('Recompute iv_balances from the movement ledger')
			->addOption('force', 'f', InputOption::VALUE_NONE, 'Run even when not in maintenance mode');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int
	{
		$force = (bool)$input->getOption('force');
		$maintenance = $this->config->getSystemValueBool('maintenance', false);
		if (!$maintenance && !$force) {
			$output->writeln('<error>Refused: enable maintenance mode or pass --force (S13).</error>');
			return 2;
		}

		$result = 0;
		foreach ($this->workspaces->allIds() as $id) {
			$result = max($result, $this->workspace->runAsSystem($id, fn () => $this->rebuild($output)));
		}
		return $result;
	}

	private function rebuild(OutputInterface $output): int
	{

		$sums = $this->movements->sumDeltasByPair();
		$drift = 0;
		$now = time();

		// S13: one transaction per item so live traffic on other SKUs is not
		// blocked for the whole rebuild, and a failure mid-run stays bounded.
		$byItem = [];
		foreach ($sums as $key => $total) {
			[$itemId, $locationId] = array_map('intval', explode(':', $key, 2));
			$byItem[$itemId][$locationId] = $total;
		}
		$existing = $this->balances->search(null, null, false, 100000, 0);
		foreach ($existing['data'] as $bal) {
			/** @var Balance $bal */
			$itemId = $bal->getItemId();
			$locId = $bal->getLocationId();
			if (!isset($byItem[$itemId][$locId])) {
				$byItem[$itemId][$locId] = 0;
			}
		}

		foreach ($byItem as $itemId => $locs) {
			$this->db->beginTransaction();
			try {
				foreach ($locs as $locationId => $total) {
					$bal = $this->balances->findPair($itemId, $locationId);
					if ($bal === null) {
						if ($total === 0) {
							continue;
						}
						$this->balances->ensureZeroRow($itemId, $locationId, $now);
						$bal = $this->balances->findPair($itemId, $locationId);
					}
					if ($bal === null) {
						throw new \RuntimeException('balance_ensure_failed');
					}
					if ($bal->getQty() !== $total) {
						$output->writeln(sprintf(
							'Drift item=%d loc=%d balance=%d ledger=%d — fixing',
							$itemId, $locationId, $bal->getQty(), $total,
						));
						$bal->setQty($total);
						$bal->setUpdatedAt($now);
						$this->balances->update($bal);
						$drift++;
					}
				}
				$this->db->commit();
			} catch (\Throwable $e) {
				$this->db->rollBack();
				throw $e;
			}
		}

		if ($drift === 0) {
			$output->writeln('<info>No drift.</info>');
			return 0;
		}
		$output->writeln(sprintf('<comment>Fixed %d balance(s).</comment>', $drift));
		return 1;
	}
}
