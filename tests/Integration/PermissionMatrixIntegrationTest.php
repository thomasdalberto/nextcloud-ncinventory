<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Tests\Integration;

use OCA\InventoryCheck\AppInfo\Application;
use OCA\InventoryCheck\Controller\ConfigController;
use OCA\InventoryCheck\Controller\ItemController;
use OCA\InventoryCheck\Controller\ScannerController;
use OCA\InventoryCheck\Controller\LocationController;
use OCA\InventoryCheck\Controller\LowStockController;
use OCA\InventoryCheck\Controller\MovementController;
use OCA\InventoryCheck\Controller\PageController;
use OCA\InventoryCheck\Exception\AppAccessDeniedException;
use OCA\InventoryCheck\Exception\PermissionDeniedException;
use OCA\InventoryCheck\Middleware\AppAccessMiddleware;
use OCA\InventoryCheck\Service\AccessControlService;
use OCA\InventoryCheck\Service\ItemService;
use OCA\InventoryCheck\Service\LocationService;
use OCA\InventoryCheck\Service\MovementService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IConfig;
use OCP\IRequest;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\Server;
use Test\TestCase;

/**
 * AC-2 / AC-3 / SPEC §3 + §14.2-I2: one HTTP-shaped assertion per P-matrix row.
 *
 * Controllers throw domain exceptions; AppAccessMiddleware maps them to the
 * SPEC §7.1 envelope. Sessions are real; config is restored in tearDown.
 *
 * @group DB
 */
final class PermissionMatrixIntegrationTest extends TestCase
{
	private const PASSWORD = 'Iv-PMatrix-9xK!zz';

	private const SYS = 'iv_pm_sys';
	private const ADMIN = 'iv_pm_admin';
	private const OFFICE = 'iv_pm_office';
	private const FIELD = 'iv_pm_field';
	private const OUTSIDER = 'iv_pm_out';

	/** @var array<string, string> */
	private array $prevConfig = [];

	private const KEYS = [
		AccessControlService::KEY_ACCESS_RESTRICTION,
		AccessControlService::KEY_ACCESS_ALLOWED_USER_IDS,
		AccessControlService::KEY_ACCESS_ALLOWED_GROUP_IDS,
		AccessControlService::KEY_APP_ADMINS,
		AccessControlService::KEY_OFFICE_USER_IDS,
		AccessControlService::KEY_OFFICE_GROUP_IDS,
		AccessControlService::KEY_ALLOW_NEGATIVE,
	];

	protected function setUp(): void
	{
		parent::setUp();
		if (!class_exists(\OC::class) || !isset(\OC::$server)) {
			$this->markTestSkipped('Nextcloud runtime required');
		}
		\OC_User::setIncognitoMode(false);

		$config = Server::get(IConfig::class);
		foreach (self::KEYS as $key) {
			$this->prevConfig[$key] = $config->getAppValue(Application::APP_ID, $key, '');
		}

		$this->deleteUsers();
		$userManager = Server::get(IUserManager::class);
		foreach ([self::SYS, self::ADMIN, self::OFFICE, self::FIELD, self::OUTSIDER] as $uid) {
			$userManager->createUser($uid, self::PASSWORD);
		}

		$groupManager = Server::get(\OCP\IGroupManager::class);
		$adminGroup = $groupManager->get('admin') ?? $groupManager->createGroup('admin');
		$adminGroup->addUser($userManager->get(self::SYS));

		$config->setAppValue(Application::APP_ID, AccessControlService::KEY_ACCESS_RESTRICTION, '0');
		$config->setAppValue(Application::APP_ID, AccessControlService::KEY_ACCESS_ALLOWED_USER_IDS, '[]');
		$config->setAppValue(Application::APP_ID, AccessControlService::KEY_ACCESS_ALLOWED_GROUP_IDS, '[]');
		$config->setAppValue(
			Application::APP_ID,
			AccessControlService::KEY_APP_ADMINS,
			json_encode([self::ADMIN], JSON_THROW_ON_ERROR),
		);
		$config->setAppValue(
			Application::APP_ID,
			AccessControlService::KEY_OFFICE_USER_IDS,
			json_encode([self::OFFICE], JSON_THROW_ON_ERROR),
		);
		$config->setAppValue(Application::APP_ID, AccessControlService::KEY_OFFICE_GROUP_IDS, '[]');
		$config->setAppValue(Application::APP_ID, AccessControlService::KEY_ALLOW_NEGATIVE, '0');
	}

	protected function tearDown(): void
	{
		try {
			if (!isset(\OC::$server)) {
				return;
			}
			$config = Server::get(IConfig::class);
			foreach ($this->prevConfig as $key => $value) {
				if ($value === '') {
					$config->deleteAppValue(Application::APP_ID, $key);
				} else {
					$config->setAppValue(Application::APP_ID, $key, $value);
				}
			}
			$this->deleteUsers();
			Server::get(IUserSession::class)->setUser(null);
			\OC_User::setIncognitoMode(false);
		} finally {
			parent::tearDown();
		}

	}

	private function deleteUsers(): void
	{
		$userManager = Server::get(IUserManager::class);
		foreach ([self::SYS, self::ADMIN, self::OFFICE, self::FIELD, self::OUTSIDER] as $uid) {
			if ($userManager->userExists($uid)) {
				$userManager->get($uid)?->delete();
			}
		}
	}

	private function loginAs(string $uid): void
	{
		$user = Server::get(IUserManager::class)->get($uid);
		$this->assertNotNull($user, $uid . ' must exist');
		$session = Server::get(IUserSession::class);
		$session->setUser($user);
		$this->assertSame($uid, $session->getUser()?->getUID());
	}

	private function apiMiddleware(): AppAccessMiddleware
	{
		$request = $this->createMock(IRequest::class);
		$request->method('getPathInfo')->willReturn('/apps/inventorycheck/api/items');
		$request->method('getMethod')->willReturn('GET');
		return new AppAccessMiddleware(
			Server::get(IUserSession::class),
			Server::get(AccessControlService::class),
			$request,
			Server::get(\OCP\IURLGenerator::class),
			Server::get(\OCP\L10N\IFactory::class),
			Server::get(\OCP\IConfig::class),
			Server::get(\Psr\Log\LoggerInterface::class),
		);
	}

	private function pageMiddleware(): AppAccessMiddleware
	{
		$request = $this->createMock(IRequest::class);
		$request->method('getPathInfo')->willReturn('/apps/inventorycheck/');
		$request->method('getMethod')->willReturn('GET');
		return new AppAccessMiddleware(
			Server::get(IUserSession::class),
			Server::get(AccessControlService::class),
			$request,
			Server::get(\OCP\IURLGenerator::class),
			Server::get(\OCP\L10N\IFactory::class),
			Server::get(\OCP\IConfig::class),
			Server::get(\Psr\Log\LoggerInterface::class),
		);
	}

	/**
	 * @param callable(): mixed $call
	 */
	private function invokeExpectingPermissionDenied(object $controller, string $method, callable $call): JSONResponse
	{
		$middleware = $this->apiMiddleware();
		$middleware->beforeController($controller, $method);
		try {
			$call();
			$this->fail('Expected PermissionDeniedException for ' . get_class($controller) . '::' . $method);
		} catch (PermissionDeniedException $e) {
			$response = $middleware->afterException($controller, $method, $e);
			$this->assertInstanceOf(JSONResponse::class, $response);
			$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
			$this->assertSame('permission_denied', $response->getData()['error']['code']);
			return $response;
		}
	}

	// ── P1 open app ─────────────────────────────────────────────────────

	public function testP1AnonymousHasNoSessionUser(): void
	{
		Server::get(IUserSession::class)->setUser(null);
		$controller = Server::get(ItemController::class);
		// Middleware leaves anonymous to Nextcloud auth (401) — no AppAccessDenied.
		$this->apiMiddleware()->beforeController($controller, 'index');
		$this->assertNull(Server::get(IUserSession::class)->getUser());
		$this->assertFalse(Server::get(AccessControlService::class)->canUseApp(''));
	}

	public function testP1AllRolesCanOpenWhenUnrestricted(): void
	{
		foreach ([self::SYS, self::ADMIN, self::OFFICE, self::FIELD] as $uid) {
			$this->loginAs($uid);
			$controller = Server::get(ItemController::class);
			$this->apiMiddleware()->beforeController($controller, 'index');
			$this->addToAssertionCount(1);
		}
	}

	public function testP1RestrictionBlocksOutsiderWithJsonAndPageTemplate(): void
	{
		$config = Server::get(IConfig::class);
		$config->setAppValue(Application::APP_ID, AccessControlService::KEY_ACCESS_RESTRICTION, '1');
		$config->setAppValue(
			Application::APP_ID,
			AccessControlService::KEY_ACCESS_ALLOWED_USER_IDS,
			json_encode([self::FIELD], JSON_THROW_ON_ERROR),
		);

		$this->loginAs(self::OUTSIDER);
		$controller = Server::get(ItemController::class);
		try {
			$this->apiMiddleware()->beforeController($controller, 'index');
			$this->fail('Outsider must be denied on API');
		} catch (AppAccessDeniedException $e) {
			$response = $this->apiMiddleware()->afterException($controller, 'index', $e);
			$this->assertInstanceOf(JSONResponse::class, $response);
			$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
			$this->assertSame('app_access_denied', $response->getData()['error']['code']);
		}

		$pageController = Server::get(PageController::class);
		try {
			$this->pageMiddleware()->beforeController($pageController, 'dashboard');
			$this->fail('Outsider must be denied on page');
		} catch (AppAccessDeniedException $e) {
			$response = $this->pageMiddleware()->afterException($pageController, 'dashboard', $e);
			$this->assertInstanceOf(TemplateResponse::class, $response);
			$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		}

		$this->loginAs(self::FIELD);
		$this->apiMiddleware()->beforeController($controller, 'index');
		$this->addToAssertionCount(1);
	}

	// ── P2 reads ────────────────────────────────────────────────────────

	public function testP2FieldCanReadItemsLocationsMovementsLowStock(): void
	{
		$this->loginAs(self::FIELD);
		$mw = $this->apiMiddleware();
		$items = Server::get(ItemController::class);
		$locations = Server::get(LocationController::class);
		$movements = Server::get(MovementController::class);
		$lowStock = Server::get(LowStockController::class);

		$mw->beforeController($items, 'index');
		$this->assertInstanceOf(JSONResponse::class, $items->index());
		$mw->beforeController($locations, 'index');
		$this->assertInstanceOf(JSONResponse::class, $locations->index());
		$mw->beforeController($movements, 'index');
		$this->assertInstanceOf(JSONResponse::class, $movements->index());
		$mw->beforeController($lowStock, 'index');
		$this->assertInstanceOf(JSONResponse::class, $lowStock->index());
	}

	// ── P2 contract: shared catalog must not leak cost to field ─────────

	public function testP2FieldCannotSeeSupplierNoteAndLastPrice(): void
	{
		$itemsService = Server::get(ItemService::class);
		$itemsController = Server::get(ItemController::class);

		// Seed a catalog row with confidential keys.
		$created = $itemsService->create(self::OFFICE, [
			'sku' => 'PM-COST-' . bin2hex(random_bytes(3)),
			'name' => 'Cost Item',
			'supplierNote' => 'internal supplier note',
			'lastPriceMinor' => 12345,
		]);
		$itemId = (int)$created['id'];
		$code = (string)$created['scanCode'];

		// Field user: must not receive cost-ish properties.
		$this->loginAs(self::FIELD);
		$mw = $this->apiMiddleware();

		$mw->beforeController($itemsController, 'show');
		$show = $itemsController->show($itemId)->getData();
		$this->assertArrayNotHasKey('supplierNote', $show);
		$this->assertArrayNotHasKey('lastPriceMinor', $show);

		$mw->beforeController($itemsController, 'byCode');
		$byCode = $itemsController->byCode($code)->getData();
		$this->assertArrayNotHasKey('supplierNote', $byCode);
		$this->assertArrayNotHasKey('lastPriceMinor', $byCode);
	}

	// ── P3 issue / transfer (field+) ─────────────────────────────────────

	public function testP3FieldCanIssueAndTransfer(): void
	{
		$suffix = bin2hex(random_bytes(3));
		$locations = Server::get(LocationService::class);
		$items = Server::get(ItemService::class);
		$movements = Server::get(MovementService::class);

		$a = $locations->create(self::OFFICE, [
			'code' => 'PM-A-' . $suffix, 'name' => 'A', 'kind' => 'warehouse',
		]);
		$b = $locations->create(self::OFFICE, [
			'code' => 'PM-B-' . $suffix, 'name' => 'B', 'kind' => 'van',
		]);
		$item = $items->create(self::OFFICE, [
			'sku' => 'PM-I-' . $suffix, 'name' => 'Item',
		]);
		$itemId = (int)$item['id'];
		$fromId = (int)$a['id'];
		$toId = (int)$b['id'];
		$movements->receive(self::OFFICE, $itemId, $fromId, 10, null);

		$this->loginAs(self::FIELD);
		$ctrl = Server::get(MovementController::class);
		$mw = $this->apiMiddleware();
		$mw->beforeController($ctrl, 'issue');

		$issued = $movements->issue(self::FIELD, $itemId, $fromId, 2, 'field issue');
		$this->assertSame(8, $issued['balances'][0]['qty']);

		$xfer = $movements->transfer(self::FIELD, $itemId, $fromId, $toId, 3, 'field xfer');
		$this->assertCount(2, $xfer['movements']);
	}

	// ── P4 receive / adjust office-only ─────────────────────────────────

	public function testP4FieldDeniedReceiveAndAdjust(): void
	{
		$this->loginAs(self::FIELD);
		$movements = Server::get(MovementController::class);
		$this->invokeExpectingPermissionDenied(
			$movements,
			'receive',
			fn () => $movements->receive(),
		);
		$this->invokeExpectingPermissionDenied(
			$movements,
			'adjust',
			fn () => $movements->adjust(),
		);
	}

	public function testP4OfficeCanReceive(): void
	{
		$suffix = bin2hex(random_bytes(3));
		$locations = Server::get(LocationService::class);
		$items = Server::get(ItemService::class);
		$movements = Server::get(MovementService::class);

		$loc = $locations->create(self::OFFICE, [
			'code' => 'PM-R-' . $suffix, 'name' => 'Recv', 'kind' => 'warehouse',
		]);
		$item = $items->create(self::OFFICE, [
			'sku' => 'PM-RI-' . $suffix, 'name' => 'Recv item',
		]);
		$result = $movements->receive(self::OFFICE, (int)$item['id'], (int)$loc['id'], 5, null);
		$this->assertSame(5, $result['balances'][0]['qty']);
	}

	// ── P5 CRUD items / locations office-only ───────────────────────────

	public function testP5FieldDeniedItemCreate(): void
	{
		$this->loginAs(self::FIELD);
		$items = Server::get(ItemController::class);
		$this->invokeExpectingPermissionDenied($items, 'create', fn () => $items->create());
	}

	public function testP5OfficeCanCreateItem(): void
	{
		$suffix = bin2hex(random_bytes(3));
		$created = Server::get(ItemService::class)->create(self::OFFICE, [
			'sku' => 'PM-C-' . $suffix,
			'name' => 'Created by office',
		]);
		$this->assertArrayHasKey('id', $created);
	}

	// ── P6 access / office / negative policy — app admin only ───────────

	public function testP6OfficeDeniedConfigEdit(): void
	{
		$this->loginAs(self::OFFICE);
		$config = Server::get(ConfigController::class);
		$this->invokeExpectingPermissionDenied($config, 'saveAccess', fn () => $config->saveAccess());
		$this->invokeExpectingPermissionDenied($config, 'saveOffice', fn () => $config->saveOffice());
	}

	public function testP6FieldDeniedConfigEdit(): void
	{
		$this->loginAs(self::FIELD);
		$config = Server::get(ConfigController::class);
		$this->invokeExpectingPermissionDenied($config, 'saveAccess', fn () => $config->saveAccess());
		$this->invokeExpectingPermissionDenied($config, 'saveOffice', fn () => $config->saveOffice());
	}

	public function testP6AppAdminCanReadAndEditConfig(): void
	{
		$this->loginAs(self::ADMIN);
		$config = Server::get(ConfigController::class);
		$this->apiMiddleware()->beforeController($config, 'index');
		$response = $config->index();
		$this->assertArrayHasKey('accessRestrictionEnabled', $response->getData());
		$this->assertTrue($response->getData()['isWorkspaceAdmin']);
		$this->assertArrayHasKey('isSystemAdmin', $response->getData());
		$this->assertArrayHasKey('appAdmins', $response->getData());
		$this->assertIsArray($response->getData()['appAdmins']);

		$acl = Server::get(AccessControlService::class);
		$before = $acl->allowNegativeStock();
		$acl->requireWorkspaceAdmin(self::ADMIN);
		$acl->setAllowNegativeStock(!$before);
		$this->assertSame(!$before, $acl->allowNegativeStock());
		$acl->setAllowNegativeStock($before);
	}

	// ── P7 license — app admin only ─────────────────────────────────────

	public function testP7OfficeDeniedLicense(): void
	{
		$this->loginAs(self::OFFICE);
		$license = Server::get(ScannerController::class);
		$this->invokeExpectingPermissionDenied($license, 'show', fn () => $license->show());
		$this->invokeExpectingPermissionDenied($license, 'apply', fn () => $license->apply());
	}

	public function testP7AppAdminCanReadLicenseStatus(): void
	{
		$this->loginAs(self::ADMIN);
		$license = Server::get(ScannerController::class);
		$this->apiMiddleware()->beforeController($license, 'show');
		$response = $license->show();
		$this->assertArrayHasKey('mobileAppStatus', $response->getData());
	}

	public function testP7SystemAdminPassesLicenseGate(): void
	{
		$this->loginAs(self::SYS);
		$license = Server::get(ScannerController::class);
		$this->apiMiddleware()->beforeController($license, 'show');
		$this->assertInstanceOf(JSONResponse::class, $license->show());
	}

	// ── P9 Support & us only on admin settings ──────────────────────────

	public function testP9FieldDeniedSettingsPage(): void
	{
		$this->loginAs(self::FIELD);
		$page = Server::get(PageController::class);
		$this->invokeExpectingPermissionDenied($page, 'settings', fn () => $page->settings());
	}

	public function testP9OfficeDeniedSettingsPage(): void
	{
		$this->loginAs(self::OFFICE);
		$page = Server::get(PageController::class);
		$this->invokeExpectingPermissionDenied($page, 'settings', fn () => $page->settings());
	}

	public function testP9AppAdminSettingsIncludesSupportUs(): void
	{
		$this->loginAs(self::ADMIN);
		$page = Server::get(PageController::class);
		// Page URL generation depends on request context (pathInfo / app webroot).
		// Using apiMiddleware() with an /api/* path makes linkToRouteAbsolute fragile in CLI PHPUnit.
		$this->pageMiddleware()->beforeController($page, 'settingsSection');
		$response = $page->settingsSection('support');
		$this->assertInstanceOf(TemplateResponse::class, $response);
		$params = $response->getParams();
		$this->assertTrue($params['isWorkspaceAdmin']);
		$this->assertSame('support', $params['settingsSection']);
		$this->assertArrayHasKey('supportUsLicenseUrl', $params);
		$this->assertStringContainsString('#iv-license', (string)$params['supportUsLicenseUrl']);
		$this->assertStringContainsString('/settings/license', (string)$params['supportUsLicenseUrl']);
	}

	public function testP9SettingsIndexRedirectsToAccess(): void
	{
		$this->loginAs(self::ADMIN);
		$page = Server::get(PageController::class);
		$this->pageMiddleware()->beforeController($page, 'settings');
		$response = $page->settings();
		$this->assertInstanceOf(\OCP\AppFramework\Http\RedirectResponse::class, $response);
		$this->assertStringContainsString('/settings/access', $response->getRedirectURL());
	}
}
