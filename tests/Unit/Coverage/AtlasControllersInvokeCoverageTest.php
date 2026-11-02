<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Tests\Unit\Coverage;

use OCA\InventoryCheck\Controller\BalanceController;
use OCA\InventoryCheck\Controller\ConfigController;
use OCA\InventoryCheck\Controller\CycleCountController;
use OCA\InventoryCheck\Controller\DirectoryController;
use OCA\InventoryCheck\Controller\ExportController;
use OCA\InventoryCheck\Controller\FavouriteController;
use OCA\InventoryCheck\Controller\FlangeController;
use OCA\InventoryCheck\Controller\ImportController;
use OCA\InventoryCheck\Controller\ItemController;
use OCA\InventoryCheck\Controller\ItemPhotoController;
use OCA\InventoryCheck\Controller\ScannerController;
use OCA\InventoryCheck\Controller\AdministrationController;
use OCA\InventoryCheck\Controller\LocationController;
use OCA\InventoryCheck\Controller\LowStockController;
use OCA\InventoryCheck\Controller\MobileController;
use OCA\InventoryCheck\Controller\MovementController;
use OCA\InventoryCheck\Controller\PageController;
use OCA\InventoryCheck\Exception\PermissionDeniedException;
use OCA\InventoryCheck\Service\AccessControlService;
use OCA\InventoryCheck\Service\BalanceService;
use OCA\InventoryCheck\Service\CsvExportService;
use OCA\InventoryCheck\Service\CsvImportService;
use OCA\InventoryCheck\Service\CycleCountService;
use OCA\InventoryCheck\Service\DevicePairingService;
use OCA\InventoryCheck\Service\DirectoryOptionsService;
use OCA\InventoryCheck\Service\FlangeService;
use OCA\InventoryCheck\Service\ItemPhotoService;
use OCA\InventoryCheck\Service\ItemService;
use OCA\InventoryCheck\Service\ScannerService;
use OCA\InventoryCheck\Service\LocationAclService;
use OCA\InventoryCheck\Service\LocationFavouriteService;
use OCA\InventoryCheck\Service\LocationService;
use OCA\InventoryCheck\Service\LowStockService;
use OCA\InventoryCheck\Service\MobileAccessService;
use OCA\InventoryCheck\Service\MovementService;
use OCA\InventoryCheck\Service\SettingsSectionCatalog;
use OCP\AppFramework\Http\Response;
use OCP\IConfig;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\L10N\IFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionUnionType;

/**
 * Atlas v3 used-function invoke coverage: every shipping controller action is called.
 */
final class AtlasControllersInvokeCoverageTest extends TestCase
{
	/** @var array<string, MockObject|object> */
	private array $byType = [];

	private const CONTROLLERS = [
		PageController::class,
		LocationController::class,
		ItemController::class,
		ItemPhotoController::class,
		BalanceController::class,
		MovementController::class,
		LowStockController::class,
		ExportController::class,
		ImportController::class,
		CycleCountController::class,
		FavouriteController::class,
		FlangeController::class,
		ConfigController::class,
		DirectoryController::class,
		ScannerController::class,
		AdministrationController::class,
		MobileController::class,
	];

	protected function setUp(): void
	{
		parent::setUp();
		$this->byType = [];
	}

	public function testEveryControllerActionIsInvoked(): void
	{
		$invoked = [];
		foreach (self::CONTROLLERS as $class) {
			$ctrl = $this->buildController($class);
			$ref = new ReflectionClass($class);
			foreach ($ref->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
				if ($method->getDeclaringClass()->getName() !== $class) {
					continue;
				}
				if ($method->getName() === '__construct') {
					continue;
				}
				$args = $this->dummyArgs($method);
				$symbol = $ref->getShortName() . '::' . $method->getName();
				$result = $method->invokeArgs($ctrl, $args);
				self::assertInstanceOf(Response::class, $result, $symbol);
				$invoked[] = $symbol;
			}
		}

		self::assertGreaterThanOrEqual(90, count($invoked), 'expected ~95 controller actions, got ' . count($invoked));
		self::assertContains('PageController::dashboard', $invoked);
		self::assertContains('ItemController::create', $invoked);
		self::assertContains('MovementController::scan', $invoked);
		self::assertContains('MobileController::bootstrap', $invoked);
		self::assertContains('ScannerController::show', $invoked);
		self::assertContains('CycleCountController::close', $invoked);
	}

	public function testMutatingAndObjectLevelAuthzNegatives(): void
	{
		$denied = $this->buildController(ItemController::class, allowUse: false);
		try {
			$denied->show(1);
			self::fail('expected PermissionDeniedException');
		} catch (PermissionDeniedException) {
			self::assertTrue(true);
		}

		$deniedLoc = $this->buildController(LocationController::class, allowUse: false);
		try {
			$deniedLoc->destroy(1);
			self::fail('expected PermissionDeniedException');
		} catch (PermissionDeniedException) {
			self::assertTrue(true);
		}

		$deniedMv = $this->buildController(MovementController::class, allowUse: false);
		try {
			$deniedMv->receive();
			self::fail('expected PermissionDeniedException');
		} catch (PermissionDeniedException) {
			self::assertTrue(true);
		}

		$deniedCfg = $this->buildController(ConfigController::class, allowAdmin: false);
		try {
			$deniedCfg->saveAccess();
			self::fail('expected PermissionDeniedException');
		} catch (PermissionDeniedException) {
			self::assertTrue(true);
		}

		$deniedLic = $this->buildController(ScannerController::class, allowAdmin: false);
		try {
			$deniedLic->apply();
			self::fail('expected PermissionDeniedException');
		} catch (PermissionDeniedException) {
			self::assertTrue(true);
		}

		$deniedDir = $this->buildController(DirectoryController::class, allowAdmin: false);
		try {
			$deniedDir->searchUsers();
			self::fail('expected PermissionDeniedException');
		} catch (PermissionDeniedException) {
			self::assertTrue(true);
		}

		$deniedFav = $this->buildController(FavouriteController::class, allowUse: false);
		try {
			$deniedFav->create();
			self::fail('expected PermissionDeniedException');
		} catch (PermissionDeniedException) {
			self::assertTrue(true);
		}

		$deniedCc = $this->buildController(CycleCountController::class, allowUse: false);
		try {
			$deniedCc->close(1);
			self::fail('expected PermissionDeniedException');
		} catch (PermissionDeniedException) {
			self::assertTrue(true);
		}
	}

	/**
	 * @template T of object
	 * @param class-string<T> $class
	 * @return T
	 */
	private function buildController(string $class, bool $allowAdmin = true, bool $allowUse = true, bool $allowOffice = true): object
	{
		$ref = new ReflectionClass($class);
		$ctor = $ref->getConstructor();
		self::assertNotNull($ctor);
		$args = [];
		foreach ($ctor->getParameters() as $param) {
			$name = $param->getName();
			$type = $param->getType();
			if ($name === 'appName') {
				$args[] = 'inventorycheck';
				continue;
			}
			if ($type instanceof ReflectionNamedType && $type->getName() === IRequest::class) {
				$args[] = $this->request();
				continue;
			}
			if ($type === null) {
				$args[] = $param->isDefaultValueAvailable() ? $param->getDefaultValue() : null;
				continue;
			}
			$typeName = $this->resolveTypeName($type);
			if ($typeName === null) {
				$args[] = $param->isDefaultValueAvailable() ? $param->getDefaultValue() : null;
				continue;
			}
			$args[] = $this->mockFor($typeName, $allowAdmin, $allowUse, $allowOffice);
		}
		return $ref->newInstanceArgs($args);
	}

	private function resolveTypeName(\ReflectionType $type): ?string
	{
		if ($type instanceof ReflectionNamedType) {
			return $type->isBuiltin() ? null : $type->getName();
		}
		if ($type instanceof ReflectionUnionType) {
			foreach ($type->getTypes() as $t) {
				if ($t instanceof ReflectionNamedType && !$t->isBuiltin() && $t->getName() !== 'null') {
					return $t->getName();
				}
			}
		}
		return null;
	}

	private function mockFor(string $typeName, bool $allowAdmin, bool $allowUse, bool $allowOffice): object
	{
		$key = $typeName . ':' . ($allowAdmin ? '1' : '0') . ($allowUse ? '1' : '0') . ($allowOffice ? '1' : '0');
		if (isset($this->byType[$key])) {
			return $this->byType[$key];
		}

		if ($typeName === \OCA\InventoryCheck\Service\WorkspaceContext::class) {
			$mock = $this->createMock(\OCA\InventoryCheck\Service\WorkspaceContext::class);
			$mock->method('current')->willReturn(['id' => 1, 'name' => 'Test workspace', 'role' => 'owner', 'status' => 'accepted']);
			$mock->method('id')->willReturn(1);
			return $mock;
		}

		if ($typeName === AccessControlService::class) {
			$mock = $this->createMock(AccessControlService::class);
			$mock->method('currentUserId')->willReturnCallback(
				static function () use ($allowUse): string {
					if (!$allowUse) {
						throw new PermissionDeniedException('denied');
					}
					return 'alice';
				}
			);
			$mock->method('isWorkspaceAdmin')->willReturn($allowAdmin);
			$mock->method('canWrite')->willReturn($allowOffice);
			$mock->method('isSystemAdmin')->willReturn($allowAdmin);
			$mock->method('requireWorkspaceAdmin')->willReturnCallback(
				static function () use ($allowAdmin): void {
					if (!$allowAdmin) {
						throw new PermissionDeniedException('admin');
					}
				}
			);
			$mock->method('requireWrite')->willReturnCallback(
				static function () use ($allowOffice): void {
					if (!$allowOffice) {
						throw new PermissionDeniedException('office');
					}
				}
			);
			$this->byType[$key] = $mock;
			return $mock;
		}

		if ($typeName === IUserSession::class) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn('alice');
			$user->method('getDisplayName')->willReturn('Alice');
			$session = $this->createMock(IUserSession::class);
			$session->method('getUser')->willReturn($user);
			$this->byType[$key] = $session;
			return $session;
		}

		if ($typeName === IFactory::class) {
			$l10n = $this->createMock(IL10N::class);
			$l10n->method('t')->willReturnCallback(static fn (string $s, array $p = []) => $s);
			$factory = $this->createMock(IFactory::class);
			$factory->method('get')->willReturn($l10n);
			$this->byType[$key] = $factory;
			return $factory;
		}

		if ($typeName === IURLGenerator::class) {
			$url = $this->createMock(IURLGenerator::class);
			$url->method('linkToRoute')->willReturn('/apps/inventorycheck/');
			$url->method('linkToRouteAbsolute')->willReturn('http://localhost/apps/inventorycheck/');
			$this->byType[$key] = $url;
			return $url;
		}

		if ($typeName === IConfig::class) {
			$config = $this->createMock(IConfig::class);
			$config->method('getAppValue')->willReturn('0');
			$config->method('getUserValue')->willReturn('');
			$this->byType[$key] = $config;
			return $config;
		}

		if ($typeName === SettingsSectionCatalog::class) {
			$this->byType[$key] = new SettingsSectionCatalog();
			return $this->byType[$key];
		}

		if ($typeName === ItemService::class) {
			$item = [
				'id' => 1,
				'sku' => 'SKU-1',
				'name' => 'Widget',
				'scanCode' => 'IVI1',
				'reorderLevel' => 0,
				'targetStock' => 0,
				'active' => true,
				'balances' => [],
			];
			$mock = $this->createMock(ItemService::class);
			$mock->method('list')->willReturn(['data' => [$item], 'total' => 1]);
			$mock->method('get')->willReturn($item);
			$mock->method('byCode')->willReturn($item);
			$mock->method('create')->willReturn($item);
			$mock->method('update')->willReturn($item);
			$mock->method('delete')->willReturnCallback(static function (): void {});
			$this->byType[$key] = $mock;
			return $mock;
		}

		if ($typeName === LocationService::class) {
			$loc = [
				'id' => 1,
				'code' => 'WH-A',
				'name' => 'Warehouse',
				'scanCode' => 'IVL1',
				'kind' => 'warehouse',
				'active' => true,
			];
			$mock = $this->createMock(LocationService::class);
			$mock->method('list')->willReturn(['data' => [$loc], 'total' => 1]);
			$mock->method('get')->willReturn($loc);
			$mock->method('byCode')->willReturn($loc);
			$mock->method('create')->willReturn($loc);
			$mock->method('update')->willReturn($loc);
			$mock->method('delete')->willReturnCallback(static function (): void {});
			$this->byType[$key] = $mock;
			return $mock;
		}

		if ($typeName === MovementService::class) {
			$mv = [
				'id' => 1,
				'type' => 'receive',
				'qtyDelta' => 1,
				'qtyAfter' => 1,
				'itemId' => 1,
				'locationId' => 1,
			];
			$payload = [
				'movements' => [$mv],
				'balances' => [['itemId' => 1, 'locationId' => 1, 'qty' => 1]],
				'data' => [$mv],
				'total' => 1,
			];
			$mock = $this->createMock(MovementService::class);
			$mock->method('list')->willReturn($payload);
			$mock->method('receive')->willReturn($payload);
			$mock->method('issue')->willReturn($payload);
			$mock->method('transfer')->willReturn($payload);
			$mock->method('adjust')->willReturn($payload);
			$mock->method('scan')->willReturn($payload);
			$this->byType[$key] = $mock;
			return $mock;
		}

		if ($typeName === BalanceService::class) {
			$mock = $this->createMock(BalanceService::class);
			$mock->method('list')->willReturn(['data' => [['itemId' => 1, 'locationId' => 1, 'qty' => 1]], 'total' => 1]);
			$this->byType[$key] = $mock;
			return $mock;
		}

		if ($typeName === LowStockService::class) {
			$mock = $this->createMock(LowStockService::class);
			$mock->method('list')->willReturn(['data' => [], 'total' => 0]);
			$mock->method('listPerLocation')->willReturn(['data' => [], 'total' => 0]);
			$mock->method('isPerLocationHintEnabled')->willReturn(false);
			$mock->method('setPerLocationHintEnabled')->willReturnCallback(static function (): void {});
			$this->byType[$key] = $mock;
			return $mock;
		}

		if ($typeName === CycleCountService::class) {
			$cc = ['id' => 1, 'status' => 'open', 'lines' => [['id' => 1, 'countedQty' => null]]];
			$mock = $this->createMock(CycleCountService::class);
			$mock->method('list')->willReturn(['data' => [$cc], 'total' => 1]);
			$mock->method('get')->willReturn($cc);
			$mock->method('create')->willReturn($cc);
			$mock->method('startCounting')->willReturn($cc);
			$mock->method('setCount')->willReturn(['id' => 1, 'countedQty' => 1]);
			$mock->method('close')->willReturn($cc);
			$this->byType[$key] = $mock;
			return $mock;
		}

		if ($typeName === ScannerService::class) {
			$mock = $this->createMock(ScannerService::class);
			$mock->method('listDevices')->willReturn(['data' => [], 'total' => 0]);
			$mock->method('createDevice')->willReturn(['device' => ['id' => 1], 'pairCode' => 'ABCD2345']);
			$mock->method('regeneratePairCode')->willReturn(['device' => ['id' => 1], 'pairCode' => 'ABCD2345']);
			return $mock;
		}

		if ($typeName === MobileAccessService::class) {
			$mock = $this->createMock(MobileAccessService::class);
			$mock->method('requireCaller')->willReturnCallback(static function (): void {});
			$mock->method('bootstrap')->willReturn(['ok' => true, 'licensed' => false]);
			$this->byType[$key] = $mock;
			return $mock;
		}

		if ($typeName === DevicePairingService::class) {
			$mock = $this->createMock(DevicePairingService::class);
			$mock->method('pair')->willReturn(['ok' => true, 'deviceId' => 1]);
			$this->byType[$key] = $mock;
			return $mock;
		}

		if ($typeName === LocationFavouriteService::class) {
			$mock = $this->createMock(LocationFavouriteService::class);
			$mock->method('list')->willReturn(['data' => [1]]);
			$mock->method('add')->willReturn(['ok' => true]);
			$mock->method('remove')->willReturn(['ok' => true]);
			$this->byType[$key] = $mock;
			return $mock;
		}

		if ($typeName === FlangeService::class) {
			$mock = $this->createMock(FlangeService::class);
			$mock->method('status')->willReturn(['enabled' => false]);
			$mock->method('issueForMaintWo')->willReturn(['ok' => true]);
			$mock->method('issueForProject')->willReturn(['ok' => true]);
			$mock->method('setMaintEnabled')->willReturnCallback(static function (): void {});
			$mock->method('setProjectEnabled')->willReturnCallback(static function (): void {});
			$mock->method('setDefaultLocationId')->willReturnCallback(static function (): void {});
			$this->byType[$key] = $mock;
			return $mock;
		}

		if ($typeName === CsvExportService::class) {
			$mock = $this->createMock(CsvExportService::class);
			$mock->method('export')->willReturn([
				'body' => "sku,name\n",
				'filename' => 'export.csv',
				'contentType' => 'text/csv',
			]);
			$this->byType[$key] = $mock;
			return $mock;
		}

		if ($typeName === CsvImportService::class) {
			$mock = $this->createMock(CsvImportService::class);
			$mock->method('dryRun')->willReturn(['ok' => true, 'rows' => []]);
			$mock->method('commit')->willReturn(['ok' => true, 'imported' => 0]);
			$this->byType[$key] = $mock;
			return $mock;
		}

		if ($typeName === ItemPhotoService::class) {
			$mock = $this->createMock(ItemPhotoService::class);
			$mock->method('read')->willReturn(['mime' => 'image/png', 'content' => 'x']);
			$mock->method('upload')->willReturn(['ok' => true]);
			$mock->method('delete')->willReturn(['deleted' => true]);
			$this->byType[$key] = $mock;
			return $mock;
		}

		if ($typeName === DirectoryOptionsService::class) {
			$mock = $this->createMock(DirectoryOptionsService::class);
			$mock->method('searchUsers')->willReturn([['id' => 'bob', 'displayName' => 'Bob']]);
			$mock->method('searchGroups')->willReturn([['id' => 'staff', 'displayName' => 'Staff']]);
			$this->byType[$key] = $mock;
			return $mock;
		}

		if ($typeName === LocationAclService::class) {
			$mock = $this->createMock(LocationAclService::class);
			$mock->method('listAll')->willReturn([]);
			$mock->method('replaceAll')->willReturn(['ok' => true]);
			$mock->method('isEnabled')->willReturn(false);
			$mock->method('isDevicesStrict')->willReturn(false);
			$mock->method('setEnabled')->willReturnCallback(static function (): void {});
			$mock->method('setDevicesStrict')->willReturnCallback(static function (): void {});
			$mock->method('purgeUser')->willReturnCallback(static function (): void {});
			$this->byType[$key] = $mock;
			return $mock;
		}

		if ($typeName === IUserManager::class) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn('alice');
			$user->method('getDisplayName')->willReturn('Alice');
			$mock = $this->createMock(IUserManager::class);
			$mock->method('get')->willReturn($user);
			$mock->method('userExists')->willReturn(true);
			$this->byType[$key] = $mock;
			return $mock;
		}

		if ($typeName === IGroupManager::class) {
			$mock = $this->createMock(IGroupManager::class);
			$mock->method('groupExists')->willReturn(true);
			$mock->method('get')->willReturn(null);
			$this->byType[$key] = $mock;
			return $mock;
		}

		if (class_exists($typeName)) {
			$ref = new ReflectionClass($typeName);
			if ($ref->isFinal() && $ref->isInstantiable()) {
				$obj = $this->buildFinalConcrete($ref, $allowAdmin, $allowUse, $allowOffice);
				$this->byType[$key] = $obj;
				return $obj;
			}
		}

		/** @var MockObject $mock */
		$mock = $this->createMock($typeName);
		$this->byType[$key] = $mock;
		return $mock;
	}

	/**
	 * @param ReflectionClass<object> $ref
	 */
	private function buildFinalConcrete(ReflectionClass $ref, bool $allowAdmin, bool $allowUse, bool $allowOffice): object
	{
		$ctor = $ref->getConstructor();
		if ($ctor === null) {
			return $ref->newInstance();
		}
		$args = [];
		foreach ($ctor->getParameters() as $param) {
			$type = $param->getType();
			$typeName = $type ? $this->resolveTypeName($type) : null;
			if ($typeName === null) {
				if ($param->isDefaultValueAvailable()) {
					$args[] = $param->getDefaultValue();
				} elseif ($type instanceof ReflectionNamedType && $type->allowsNull()) {
					$args[] = null;
				} else {
					$args[] = match ($type instanceof ReflectionNamedType ? $type->getName() : '') {
						'string' => '',
						'int' => 0,
						'bool' => false,
						'array' => [],
						default => null,
					};
				}
				continue;
			}
			$args[] = $this->mockFor($typeName, $allowAdmin, $allowUse, $allowOffice);
		}
		return $ref->newInstanceArgs($args);
	}

	private function request(): IRequest
	{
		$req = $this->createMock(IRequest::class);
		$params = [
			'invitationId' => 12,
			'decision' => 'view',
			'token' => 'fixture-token',
			'q' => 'wi',
			'limit' => 50,
			'offset' => 0,
			'active' => '1',
			'lowStock' => '0',
			'section' => 'access',
			'name' => 'Widget',
			'sku' => 'SKU-1',
			'scanCode' => 'IVI1',
			'code' => 'WH-A',
			'kind' => 'warehouse',
			'itemId' => 1,
			'locationId' => 1,
			'fromLocationId' => 1,
			'toLocationId' => 2,
			'qty' => 1,
			'quantity' => 1,
			'reason' => 'count',
			'reasonCode' => 'count',
			'note' => 'n',
			'clientRequestId' => 'req-1',
			'type' => 'receive',
			'uid' => 'bob',
			'userId' => 'bob',
			'key' => 'LIC-TEST',
			'displayName' => 'Scanner 1',
			'pairCode' => 'PAIR01',
			'countedQty' => 1,
			'locationIds' => [1],
			'itemIds' => [1],
			'allowedUserIds' => ['alice'],
			'allowedGroupIds' => [],
			'appAdmins' => ['alice'],
			'officeUserIds' => ['alice'],
			'officeGroupIds' => [],
			'accessRestriction' => false,
			'allowNegative' => false,
			'fractional' => false,
			'notifyEnabled' => false,
			'csv' => "sku,name\nSKU-1,Widget\n",
			'file' => null,
			'maintWoId' => 1,
			'projectId' => 1,
			'settings' => [],
			'acl' => [],
			'rules' => [],
			'ids' => [1],
			'lines' => [['itemId' => 1, 'qty' => 1]],
			'woId' => 1,
		];
		$req->method('getParam')->willReturnCallback(static function (string $k, $default = null) use ($params) {
			return $params[$k] ?? $default;
		});
		$req->method('getParams')->willReturn($params);
		$req->method('getHeader')->willReturn('');
		$req->method('passesCSRFCheck')->willReturn(true);
		$req->method('getUploadedFile')->willReturn([
			'name' => 'photo.png',
			'type' => 'image/png',
			'tmp_name' => '/tmp/iv-photo.png',
			'error' => 0,
			'size' => 4,
		]);
		return $req;
	}

	private function dummyArgs(ReflectionMethod $method): array
	{
		$args = [];
		foreach ($method->getParameters() as $param) {
			$type = $param->getType();
			if ($param->isDefaultValueAvailable()) {
				$args[] = $param->getDefaultValue();
				continue;
			}
			if ($type instanceof ReflectionNamedType) {
				$name = $type->getName();
				if ($type->allowsNull()) {
					$args[] = null;
					continue;
				}
				$args[] = match ($name) {
					'int' => 1,
					'string' => $param->getName() === 'section' ? 'access' : 'IVI1',
					'bool' => true,
					'float' => 1.0,
					'array' => [],
					default => null,
				};
				continue;
			}
			$args[] = null;
		}
		return $args;
	}
}
