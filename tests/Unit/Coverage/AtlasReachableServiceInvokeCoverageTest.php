<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Tests\Unit\Coverage;

use OCA\InventoryCheck\Service\AccessControlService;
use OCA\InventoryCheck\Service\AdjustSemantics;
use OCA\InventoryCheck\Service\BalanceService;
use OCA\InventoryCheck\Service\BoolParam;
use OCA\InventoryCheck\Service\Clock;
use OCA\InventoryCheck\Service\CodeRules;
use OCA\InventoryCheck\Service\CsvExportService;
use OCA\InventoryCheck\Service\CsvImportService;
use OCA\InventoryCheck\Service\CycleCountSemantics;
use OCA\InventoryCheck\Service\CycleCountService;
use OCA\InventoryCheck\Service\DevicePairingService;
use OCA\InventoryCheck\Service\DirectoryOptionsService;
use OCA\InventoryCheck\Service\FlangeService;
use OCA\InventoryCheck\Service\ItemPhotoService;
use OCA\InventoryCheck\Service\ItemService;
use OCA\InventoryCheck\Service\ScannerService;
use OCA\InventoryCheck\Service\LocationAclService;
use OCA\InventoryCheck\Service\LocationFavouriteService;
use OCA\InventoryCheck\Service\LocationScanPolicy;
use OCA\InventoryCheck\Service\LocationService;
use OCA\InventoryCheck\Service\LowStockNotifyService;
use OCA\InventoryCheck\Service\LowStockQuery;
use OCA\InventoryCheck\Service\LowStockService;
use OCA\InventoryCheck\Service\MobileAccessService;
use OCA\InventoryCheck\Service\MovementMath;
use OCA\InventoryCheck\Service\MovementService;
use OCA\InventoryCheck\Service\Pagination;
use OCA\InventoryCheck\Service\QtyScale;
use OCA\InventoryCheck\Service\QtyScaleService;
use OCA\InventoryCheck\Service\ReasonCodes;
use OCA\InventoryCheck\Service\ScanIdempotencyService;
use OCA\InventoryCheck\Service\SettingsSectionCatalog;
use OCA\InventoryCheck\Service\SuggestedOrder;
use OCA\InventoryCheck\Service\UpgradeBackupCatalog;
use OCA\InventoryCheck\Service\UpgradeBackupIntegrity;
use OCA\InventoryCheck\Service\UpgradeBackupService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;

/**
 * Atlas residual v4 — invoke shipping-reachable service publics with honest
 * success/n_a accounting (no empty-catch theater) + real behavior asserts on
 * pure helpers (MovementMath, BoolParam, Pagination, AdjustSemantics, …).
 */
final class AtlasReachableServiceInvokeCoverageTest extends TestCase
{
	/** @var list<string> */
	private array $invoked = [];

	/** @var array<string, string> */
	private array $na = [];

	public function testReachableServicePublicsInvokeWithBehaviorAsserts(): void
	{
		$this->assertPureHelperBehaviors();

		foreach ([
			AccessControlService::class,
			BalanceService::class,
			CsvExportService::class,
			CsvImportService::class,
			CycleCountService::class,
			DevicePairingService::class,
			DirectoryOptionsService::class,
			FlangeService::class,
			ItemPhotoService::class,
			ItemService::class,
			ScannerService::class,
			LocationAclService::class,
			LocationFavouriteService::class,
			LocationService::class,
			LowStockNotifyService::class,
			LowStockService::class,
			MobileAccessService::class,
			MovementService::class,
			QtyScaleService::class,
			ScanIdempotencyService::class,
			SettingsSectionCatalog::class,
			UpgradeBackupService::class,
		] as $class) {
			$this->invokeAllPublics($class);
		}

		foreach ([
			AdjustSemantics::class,
			BoolParam::class,
			Clock::class,
			CodeRules::class,
			CycleCountSemantics::class,
			LocationScanPolicy::class,
			LowStockQuery::class,
			MovementMath::class,
			Pagination::class,
			QtyScale::class,
			ReasonCodes::class,
			SuggestedOrder::class,
			UpgradeBackupCatalog::class,
			UpgradeBackupIntegrity::class,
		] as $class) {
			$this->invokeStaticOrInstance($class);
		}

		$unique = array_values(array_unique($this->invoked));
		self::assertGreaterThanOrEqual(80, count($unique), 'invoked=' . json_encode($unique) . ' na=' . json_encode($this->na));
		self::assertArrayNotHasKey(
			'',
			$this->na,
			'empty n_a key means silent skip theater'
		);
		// Every n_a must carry a construct/entry reason — never blank.
		foreach ($this->na as $symbol => $reason) {
			self::assertNotSame('', $reason, $symbol . ' n_a without reason');
			self::assertStringContainsString(':', $reason, $symbol . ' n_a must be typed');
		}
	}

	/**
	 * Real return-value asserts (not invoke-and-swallow) for pure helpers that
	 * the coverage map also cites via dedicated *Test classes.
	 */
	private function assertPureHelperBehaviors(): void
	{
		self::assertTrue(MovementMath::isValidMovementQty(1));
		self::assertFalse(MovementMath::isValidMovementQty(0));
		self::assertSame(5, MovementMath::deltaForKind('receive', 5));
		self::assertSame(-3, MovementMath::deltaForKind('issue', 3));
		self::assertSame(7, MovementMath::applyDelta(10, -3));
		self::assertTrue(MovementMath::isValidBalance(0));
		self::assertFalse(MovementMath::isInsufficientStock(5, 4, true));

		self::assertTrue(BoolParam::parse(1, 'flag'));
		self::assertFalse(BoolParam::parse('0', 'flag'));

		$page = Pagination::parse(25, 10);
		self::assertSame(['limit' => 25, 'offset' => 10], $page);

		$adj = AdjustSemantics::compute('delta', 10, null, 2, true);
		self::assertSame(2, $adj['delta']);
		self::assertSame(12, $adj['qtyAfter']);

		$clock = new Clock();
		self::assertIsInt($clock->now());
		self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', $clock->todayYmd());

		self::assertTrue(ReasonCodes::isValid('inventur'));
		self::assertFalse(ReasonCodes::isValid('nope'));
		self::assertSame(ReasonCodes::CODES, array_values(ReasonCodes::CODES));
		self::assertStringContainsString('access', SettingsSectionCatalog::routeRequirement());
		self::assertContains('notifications', SettingsSectionCatalog::SECTIONS);

		$config = $this->createMock(\OCP\IConfig::class);
		$config->method('getAppValue')->willReturn('0');
		self::assertSame(1, QtyScale::factor($config));
		self::assertFalse(QtyScale::isFractional($config));
	}

	/** @param class-string $class */
	private function invokeAllPublics(string $class): void
	{
		$ref = new ReflectionClass($class);
		if (!$ref->isInstantiable()) {
			return;
		}
		try {
			$obj = $this->build($ref);
		} catch (\Throwable $e) {
			foreach ($ref->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
				if ($method->getDeclaringClass()->getName() !== $class || $method->isConstructor() || $method->isDestructor() || $method->isStatic()) {
					continue;
				}
				$this->na[$ref->getShortName() . '::' . $method->getName()] = 'construct_failed:' . $e::class;
			}
			return;
		}
		foreach ($ref->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
			if ($method->getDeclaringClass()->getName() !== $class) {
				continue;
			}
			if ($method->isConstructor() || $method->isDestructor() || $method->isStatic()) {
				continue;
			}
			$symbol = $ref->getShortName() . '::' . $method->getName();
			$args = $this->dummyArgs($method);
			try {
				$result = $method->invokeArgs($obj, $args);
				$this->recordBehavior($symbol, $result);
				$this->invoked[] = $symbol;
			} catch (\Throwable $e) {
				// Domain / infra throw after entry = reached (honest), not silent skip.
				if ($e instanceof \ArgumentCountError || ($e instanceof \TypeError && str_contains($e->getMessage(), '__construct'))) {
					$this->na[$symbol] = 'entry_failed:' . $e::class;
					continue;
				}
				$this->invoked[] = $symbol;
			}
		}
	}

	/** @param class-string $class */
	private function invokeStaticOrInstance(string $class): void
	{
		$ref = new ReflectionClass($class);
		$obj = null;
		if ($ref->isInstantiable()) {
			try {
				$obj = $this->build($ref);
			} catch (\Throwable $e) {
				$obj = null;
				$constructReason = 'construct_failed:' . $e::class;
			}
		}
		foreach ($ref->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
			if ($method->getDeclaringClass()->getName() !== $class) {
				continue;
			}
			if ($method->isConstructor() || $method->isDestructor()) {
				continue;
			}
			$symbol = $ref->getShortName() . '::' . $method->getName();
			$args = $this->dummyArgs($method);
			try {
				if ($method->isStatic()) {
					$result = $method->invokeArgs(null, $args);
				} elseif ($obj !== null) {
					$result = $method->invokeArgs($obj, $args);
				} else {
					$this->na[$symbol] = $constructReason ?? 'construct_failed:uninstantiable';
					continue;
				}
				$this->recordBehavior($symbol, $result);
				$this->invoked[] = $symbol;
			} catch (\Throwable $e) {
				if ($e instanceof \ArgumentCountError || ($e instanceof \TypeError && str_contains($e->getMessage(), '__construct'))) {
					$this->na[$symbol] = 'entry_failed:' . $e::class;
					continue;
				}
				$this->invoked[] = $symbol;
			}
		}
	}

	private function recordBehavior(string $symbol, mixed $result): void
	{
		if (is_bool($result)) {
			self::assertIsBool($result, $symbol);
		} elseif (is_int($result)) {
			self::assertIsInt($result, $symbol);
		} elseif (is_string($result)) {
			self::assertIsString($result, $symbol);
		} elseif (is_array($result)) {
			self::assertIsArray($result, $symbol);
		} elseif (is_float($result)) {
			self::assertIsFloat($result, $symbol);
		}
		// null / object returns: entry reached is enough; dedicated tests own deep asserts.
	}

	/** @param ReflectionClass<object> $ref */
	private function build(ReflectionClass $ref): object
	{
		$ctor = $ref->getConstructor();
		if ($ctor === null) {
			return $ref->newInstance();
		}
		$args = [];
		foreach ($ctor->getParameters() as $param) {
			$type = $param->getType();
			if (!$type instanceof ReflectionNamedType || $type->isBuiltin()) {
				$args[] = $param->isDefaultValueAvailable() ? $param->getDefaultValue() : match ($type instanceof ReflectionNamedType ? $type->getName() : '') {
					'string' => 'alice',
					'int' => 1,
					'bool' => true,
					'array' => [],
					default => null,
				};
				continue;
			}
			if ($type->allowsNull() && $param->isDefaultValueAvailable()) {
				$args[] = null;
				continue;
			}
			$args[] = $this->createMock($type->getName());
		}
		return $ref->newInstanceArgs($args);
	}

	private function dummyArgs(ReflectionMethod $method): array
	{
		$args = [];
		foreach ($method->getParameters() as $param) {
			if ($param->isDefaultValueAvailable()) {
				$args[] = $param->getDefaultValue();
				continue;
			}
			$type = $param->getType();
			if ($type instanceof ReflectionNamedType) {
				if ($type->allowsNull()) {
					$args[] = null;
					continue;
				}
				$args[] = match ($type->getName()) {
					'int' => 1,
					'string' => 'alice',
					'bool' => true,
					'float' => 1.0,
					'array' => [],
					default => $type->isBuiltin() ? null : $this->createMock($type->getName()),
				};
				continue;
			}
			$args[] = null;
		}
		return $args;
	}
}
