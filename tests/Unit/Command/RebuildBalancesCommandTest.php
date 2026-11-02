<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Tests\Unit\Command;

use OCA\InventoryCheck\Command\RebuildBalancesCommand;
use OCA\InventoryCheck\Db\BalanceMapper;
use OCA\InventoryCheck\Db\MovementMapper;
use OCP\IConfig;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class RebuildBalancesCommandTest extends TestCase
{
	public function testRefusesWithoutMaintenanceOrForce(): void
	{
		$config = $this->createMock(IConfig::class);
		$config->method('getSystemValueBool')->with('maintenance', false)->willReturn(false);

		$cmd = new RebuildBalancesCommand(
			$this->createMock(IDBConnection::class),
			$config,
			$this->createMock(MovementMapper::class),
			$this->createMock(BalanceMapper::class),
			$this->scope(),
			$this->workspaces(),
		);
		$tester = new CommandTester($cmd);
		$code = $tester->execute([]);
		self::assertSame(2, $code);
		self::assertStringContainsString('Refused', $tester->getDisplay());
	}

	public function testForceRunsAndReportsNoDrift(): void
	{
		$config = $this->createMock(IConfig::class);
		$config->method('getSystemValueBool')->willReturn(false);

		$db = $this->createMock(IDBConnection::class);
		// Empty ledger → no per-item transactions (S13 scopes TX to items with work).
		$db->expects(self::never())->method('beginTransaction');
		$db->expects(self::never())->method('commit');

		$movements = $this->createMock(MovementMapper::class);
		$movements->method('sumDeltasByPair')->willReturn([]);

		$balances = $this->createMock(BalanceMapper::class);
		$balances->method('search')->willReturn(['data' => [], 'total' => 0]);

		$cmd = new RebuildBalancesCommand($db, $config, $movements, $balances, $this->scope(), $this->workspaces());
		$tester = new CommandTester($cmd);
		$code = $tester->execute(['--force' => true]);
		self::assertSame(0, $code);
		self::assertStringContainsString('No drift', $tester->getDisplay());
	}

	public function testForceOpensOneTransactionPerItem(): void
	{
		$config = $this->createMock(IConfig::class);
		$config->method('getSystemValueBool')->willReturn(true);

		$db = $this->createMock(IDBConnection::class);
		$db->expects(self::exactly(2))->method('beginTransaction');
		$db->expects(self::exactly(2))->method('commit');

		$movements = $this->createMock(MovementMapper::class);
		$movements->method('sumDeltasByPair')->willReturn([
			'10:1' => 5,
			'10:2' => 3,
			'20:1' => 1,
		]);

		$bal10_1 = $this->makeBalance(10, 1, 5);
		$bal10_2 = $this->makeBalance(10, 2, 3);
		$bal20_1 = $this->makeBalance(20, 1, 1);

		$balances = $this->createMock(BalanceMapper::class);
		$balances->method('search')->willReturn(['data' => [$bal10_1, $bal10_2, $bal20_1], 'total' => 3]);
		$balances->method('findPair')->willReturnCallback(
			function (int $itemId, int $locationId) use ($bal10_1, $bal10_2, $bal20_1) {
				return match ($itemId . ':' . $locationId) {
					'10:1' => $bal10_1,
					'10:2' => $bal10_2,
					'20:1' => $bal20_1,
					default => null,
				};
			},
		);

		$cmd = new RebuildBalancesCommand($db, $config, $movements, $balances, $this->scope(), $this->workspaces());
		$tester = new CommandTester($cmd);
		self::assertSame(0, $tester->execute([]));
	}

	private function scope(): \OCA\InventoryCheck\Service\WorkspaceContext
	{
		$scope = $this->createMock(\OCA\InventoryCheck\Service\WorkspaceContext::class);
		$scope->method('runAsSystem')->willReturnCallback(static fn (int $id, callable $run) => $run());
		return $scope;
	}

	private function workspaces(): \OCA\InventoryCheck\Db\WorkspaceMapper
	{
		$mapper = $this->createMock(\OCA\InventoryCheck\Db\WorkspaceMapper::class);
		$mapper->method('allIds')->willReturn([1]);
		return $mapper;
	}

	private function makeBalance(int $itemId, int $locationId, int $qty): \OCA\InventoryCheck\Db\Balance
	{
		$b = new \OCA\InventoryCheck\Db\Balance();
		$b->setId($itemId * 100 + $locationId);
		$b->setItemId($itemId);
		$b->setLocationId($locationId);
		$b->setQty($qty);
		$b->setUpdatedAt(1);
		$b->resetUpdatedFields();
		return $b;
	}
}
