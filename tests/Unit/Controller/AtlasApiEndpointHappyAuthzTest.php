<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Tests\Unit\Controller;

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
use OCA\InventoryCheck\Controller\ProductLookupController;
use OCA\InventoryCheck\Exception\AppAccessDeniedException;
use OCA\InventoryCheck\Exception\MobileRequestException;
use OCA\InventoryCheck\Exception\PermissionDeniedException;
use OCA\InventoryCheck\Middleware\AppAccessMiddleware;
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
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\JSONResponse;
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
 * Atlas v3 — per-endpoint happy (2xx / designed status + body) and AuthZ deny proofs.
 * Replaces Response-only invoke theater for api-matrix VERIFIED rows.
 */
final class AtlasApiEndpointHappyAuthzTest extends TestCase
{
	/** @var array<string, MockObject|object> */
	private array $byType = [];

	/** Controllers with shipping HTTP actions (matches routes.php). */
	private const CONTROLLERS = [
		PageController::class,
		\OCA\InventoryCheck\Controller\WorkspaceController::class,
		LocationController::class,
		ItemController::class,
		ProductLookupController::class,
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

	/**
	 * Actions that must prove AuthZ deny (authz_negative_required in api-matrix).
	 *
	 * @var array<string, list<string>>
	 */
	private const AUTHZ_ACTIONS = [
		\OCA\InventoryCheck\Controller\WorkspaceController::class => ['changeRole', 'index', 'create', 'update', 'destroy', 'members', 'invite', 'removeMember', 'respond', 'notificationAction', 'searchUsers'],
		PageController::class => [
			'workspaces', 'dashboard', 'items', 'item', 'locations', 'location', 'movements',
			'stocktake', 'stocktakeNew', 'stocktakeCampaign', 'settings', 'settingsSection',
		],
		LocationController::class => [
			'index', 'byCode', 'show', 'create', 'update', 'destroy',
			'label', 'labelAlias', 'labelPrint', 'bulkLabels',
		],
		ItemController::class => [
			'index', 'byCode', 'show', 'create', 'update', 'destroy',
			'label', 'labelAlias', 'labelPrint', 'bulkLabels',
		],
		ProductLookupController::class => ['lookup'],
		ItemPhotoController::class => ['show', 'upload', 'destroy'],
		BalanceController::class => ['index'],
		MovementController::class => ['index', 'receive', 'issue', 'transfer', 'adjust', 'scan'],
		LowStockController::class => ['index', 'perLocation'],
		ExportController::class => ['index'],
		ImportController::class => ['dryRun', 'commit'],
		CycleCountController::class => ['index', 'show', 'create', 'start', 'setCount', 'close'],
		FavouriteController::class => ['index', 'create', 'destroy'],
		FlangeController::class => ['status', 'issueMaintWo', 'issueProject', 'saveSettings', 'retryInvoicePush'],
		ConfigController::class => ['saveStock', 
			'index', 'saveNotify', 'saveFractional',
			'saveWaveD', 'reasonCodes', 'locationAcl', 'saveLocationAcl', ],
		DirectoryController::class => ['searchUsers', 'searchGroups'],
		AdministrationController::class => ['index', 'save'],
		ScannerController::class => ['index', 'createDevice', 'regeneratePairCode', 'removeDevice',],
		MobileController::class => [
			'bootstrap', 'byCode', 'itemPhoto', 'locationByCode', 'locations',
			'balances', 'movements', 'scan', 'pairDevice', 'favourites',
			'addFavourite', 'removeFavourite', 'cycleCounts', 'cycleCountShow',
			'cycleCountSetCount', 'productLookup',
		],
	];

	/**
	 * Controller actions with no in-action access call — AppAccessMiddleware is the gate.
	 *
	 * @var array<string, list<string>>
	 */
	private const MIDDLEWARE_AUTHZ = [
		PageController::class => [
			'workspaces', 'dashboard', 'items', 'item', 'locations', 'location', 'movements',
			'stocktake', 'stocktakeNew', 'stocktakeCampaign', 'settings',
		],
		ItemPhotoController::class => ['show'],
		ConfigController::class => ['reasonCodes'],
	];

	protected function setUp(): void
	{
		parent::setUp();
		$this->byType = [];
	}

	public function testEveryControllerActionHappyPathIs2xxOrDesignedStatus(): void
	{
		$proved = [];
		$failures = [];
		foreach (self::CONTROLLERS as $class) {
			$this->byType = [];
			$ctrl = $this->buildController($class, allow: true, mode: 'happy');
			$ref = new ReflectionClass($class);
			foreach ($ref->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
				if ($method->getDeclaringClass()->getName() !== $class || $method->getName() === '__construct') {
					continue;
				}
				$symbol = $ref->getShortName() . '::' . $method->getName();
				try {
					$result = $method->invokeArgs($ctrl, $this->dummyArgs($method));
				} catch (\Throwable $e) {
					$failures[] = $symbol . ' threw ' . $e::class . ': ' . $e->getMessage();
					continue;
				}
				if (!$result instanceof Response) {
					$failures[] = $symbol . ' not Response';
					continue;
				}
				$status = $result->getStatus();
				if (!(($status >= 200 && $status < 300) || ($status >= 300 && $status < 400))) {
					$body = '';
					if ($result instanceof DataResponse || $result instanceof JSONResponse) {
						$body = (string)json_encode($result->getData());
					}
					$failures[] = $symbol . ' status=' . $status . ' body=' . $body;
					continue;
				}
				if ($status < 300 && ($result instanceof DataResponse || $result instanceof JSONResponse)) {
					$data = $result->getData();
					if (is_array($data) && array_key_exists('ok', $data) && $data['ok'] !== true) {
						$failures[] = $symbol . ' ok=false body=' . json_encode($data);
						continue;
					}
				}
				$proved[] = $symbol;
			}
		}
		self::assertSame([], $failures, "Happy-path failures:\n" . implode("\n", $failures));
		self::assertGreaterThanOrEqual(95, count($proved), 'expected ~95+ controller actions, got ' . count($proved));
	}

	public function testAuthzNegativePerEndpointAction(): void
	{
		$proved = [];
		$failures = [];
		foreach (self::AUTHZ_ACTIONS as $class => $actions) {
			foreach ($actions as $action) {
				$this->byType = [];
				$ref = new ReflectionClass($class);
				self::assertTrue($ref->hasMethod($action), $class . '::' . $action);
				$symbol = $ref->getShortName() . '::' . $action;

				if ($this->isMiddlewareAuthz($class, $action)) {
					try {
						$this->invokeMiddlewareDeny($class, $action);
						$failures[] = $symbol . ' middleware did not deny';
					} catch (AppAccessDeniedException $e) {
						self::assertSame('app_access_denied', $e->getMessage(), $symbol);
						$proved[] = $symbol . '@middleware';
					} catch (\Throwable $e) {
						$failures[] = $symbol . ' middleware threw ' . $e::class . ': ' . $e->getMessage();
					}
					continue;
				}

				$ctrl = $this->buildController($class, allow: false, mode: 'authz');
				$method = $ref->getMethod($action);
				try {
					$result = $method->invokeArgs($ctrl, $this->dummyArgs($method));
					if (!$result instanceof Response) {
						$failures[] = $symbol . ' not Response';
						continue;
					}
					if ($result->getStatus() < 400) {
						$body = '';
						if ($result instanceof DataResponse || $result instanceof JSONResponse) {
							$body = (string)json_encode($result->getData());
						}
						$failures[] = $symbol . ' deny status=' . $result->getStatus() . ' body=' . $body;
						continue;
					}
					if ($result instanceof DataResponse || $result instanceof JSONResponse) {
						$data = $result->getData();
						if (is_array($data) && array_key_exists('ok', $data) && $data['ok'] !== false) {
							$failures[] = $symbol . ' deny envelope ok!=false';
							continue;
						}
					}
				} catch (PermissionDeniedException|AppAccessDeniedException|MobileRequestException $e) {
					self::assertNotSame('', $e->getMessage(), $symbol);
				} catch (\Throwable $e) {
					$failures[] = $symbol . ' threw ' . $e::class . ': ' . $e->getMessage();
					continue;
				}
				$proved[] = $symbol;
			}
		}
		self::assertSame([], $failures, "AuthZ failures:\n" . implode("\n", $failures));
		self::assertGreaterThanOrEqual(88, count($proved), 'expected ≥88 authz_negative endpoints, got ' . count($proved));
		self::assertContains('MobileController::bootstrap', $proved);
		self::assertContains('MobileController::scan', $proved);
		self::assertContains('MobileController::pairDevice', $proved);
		self::assertContains('LocationController::create', $proved);
		self::assertContains('ItemController::update', $proved);
		self::assertContains('ItemPhotoController::show@middleware', $proved);
	}

	/**
	 * Completeness tripwire (security_contract_list_drift): every action
	 * declared in appinfo/routes.php must appear in AUTHZ_ACTIONS, so a new
	 * route cannot silently escape the per-endpoint deny proof. A route whose
	 * controller is missing from CONTROLLERS fails here as well.
	 */
	public function testEveryRoutesPhpActionIsAuthzPinned(): void
	{
		$byName = [];
		foreach (self::CONTROLLERS as $class) {
			$short = (new ReflectionClass($class))->getShortName();
			$byName[lcfirst(str_replace('Controller', '', $short))] = $class;
		}
		$routes = (string)file_get_contents(dirname(__DIR__, 3) . '/appinfo/routes.php');
		$missing = [];
		foreach (explode("\n", $routes) as $line) {
			if (!preg_match("/'name'\s*=>\s*'([a-zA-Z]+)#([a-zA-Z]+)'/", $line, $nm)) {
				continue;
			}
			$class = $byName[$nm[1]] ?? null;
			if ($class === null) {
				$missing[] = $nm[1] . '#' . $nm[2] . ' (controller not in CONTROLLERS)';
				continue;
			}
			if (!in_array($nm[2], self::AUTHZ_ACTIONS[$class] ?? [], true)) {
				$missing[] = $nm[1] . '#' . $nm[2];
			}
		}
		self::assertSame(
			[],
			$missing,
			'routes.php actions missing from AUTHZ_ACTIONS: ' . implode(', ', $missing),
		);
	}

	/**
	 * @param class-string $class
	 */
	private function isMiddlewareAuthz(string $class, string $action): bool
	{
		return in_array($action, self::MIDDLEWARE_AUTHZ[$class] ?? [], true);
	}

	/**
	 * @param class-string $class
	 */
	private function invokeMiddlewareDeny(string $class, string $action): void
	{
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('bob');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		$access = $this->createMock(AccessControlService::class);
		$access->method('canUseApp')->with('bob')->willReturn(false);
		$access->method('denialReasonWhenCannotUseApp')->with('bob')
			->willReturn(AccessControlService::DENIAL_RESTRICTION);

		$request = $this->createMock(IRequest::class);
		$path = str_contains($class, 'PageController')
			? '/apps/inventorycheck/items/1'
			: '/apps/inventorycheck/api/items/1/photo';
		if ($action === 'reasonCodes') {
			$path = '/apps/inventorycheck/api/reason-codes';
		}
		$request->method('getPathInfo')->willReturn($path);
		$request->method('getMethod')->willReturn('GET');

		$url = $this->createMock(IURLGenerator::class);
		$url->method('linkToDefaultPageUrl')->willReturn('/apps/files');
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $s, array $p = []) => $s);
		$factory = $this->createMock(IFactory::class);
		$factory->method('get')->willReturn($l10n);

		$mw = new AppAccessMiddleware(
			$session,
			$access,
			$request,
			$url,
			$factory,
			$this->createMock(IConfig::class),
			$this->createMock(\Psr\Log\LoggerInterface::class),
			$this->createMock(\OCA\InventoryCheck\Service\WorkspaceContext::class),
		);
		// Real controller instance so get_class() keeps OCA\InventoryCheck\Controller\ prefix.
		$ctrl = $this->buildController($class, allow: true, mode: 'happy');
		$mw->beforeController($ctrl, $action);
	}

	/**
	 * @template T of object
	 * @param class-string<T> $class
	 * @param 'happy'|'authz' $mode
	 * @return T
	 */
	private function buildController(string $class, bool $allow, string $mode): object
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
			$args[] = $this->mockFor($typeName, $allow, $mode, $class);
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

	/**
	 * @param class-string $controllerClass
	 */
	private function mockFor(string $typeName, bool $allow, string $mode, string $controllerClass): object
	{
		$key = $typeName . ':' . ($allow ? '1' : '0') . ':' . $mode . ':' . $controllerClass;
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
				static function () use ($allow, $mode): string {
					if (!$allow && $mode === 'authz') {
						throw new PermissionDeniedException('denied');
					}
					return 'alice';
				}
			);
			$mock->method('isWorkspaceAdmin')->willReturn($allow);
			$mock->method('canWrite')->willReturn($allow);
			$mock->method('isSystemAdmin')->willReturn($allow);
			$mock->method('requireWorkspaceAdmin')->willReturnCallback(
				static function () use ($allow, $mode): void {
					if (!$allow && $mode === 'authz') {
						throw new PermissionDeniedException('admin');
					}
				}
			);
			$mock->method('requireWrite')->willReturnCallback(
				static function () use ($allow, $mode): void {
					if (!$allow && $mode === 'authz') {
						throw new PermissionDeniedException('office');
					}
				}
			);
			$mock->method('allowNegativeStock')->willReturn(false);
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

		if ($typeName === MobileAccessService::class) {
			$mock = $this->createMock(MobileAccessService::class);
			$mock->method('requireCaller')->willReturnCallback(
				static function () use ($allow, $mode): void {
					if (!$allow && $mode === 'authz') {
						throw new MobileRequestException('seat_required');
					}
				}
			);
			$mock->method('bootstrap')->willReturn(['ok' => true, 'licensed' => true, 'seatAssigned' => true]);
			$this->byType[$key] = $mock;
			return $mock;
		}

		if ($typeName === DevicePairingService::class) {
			$mock = $this->createMock(DevicePairingService::class);
			$mock->method('pair')->willReturnCallback(
				static function () use ($allow, $mode): array {
					if (!$allow && $mode === 'authz') {
						throw new MobileRequestException('rate_limited');
					}
					return ['ok' => true, 'deviceId' => 1, 'token' => 'tok'];
				}
			);
			$mock->method('touchLastSeen')->willReturnCallback(static function (): void {});
			$this->byType[$key] = $mock;
			return $mock;
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
			$mock->method('retryInvoicePushes')->willReturn(0);
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
				$obj = $this->buildFinalConcrete($ref, $allow, $mode, $controllerClass);
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
	 * @param class-string $controllerClass
	 */
	private function buildFinalConcrete(ReflectionClass $ref, bool $allow, string $mode, string $controllerClass): object
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
			$args[] = $this->mockFor($typeName, $allow, $mode, $controllerClass);
		}
		return $ref->newInstanceArgs($args);
	}

	private function request(): IRequest
	{
		$req = $this->createMock(IRequest::class);
		$params = [
			'accept' => true,
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
			'qtyCounted' => 1,
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
			'kind' => 'receive',
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
