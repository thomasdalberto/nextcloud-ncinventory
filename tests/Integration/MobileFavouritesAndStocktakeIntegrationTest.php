<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Tests\Integration;

use OCA\InventoryCheck\AppInfo\Application;
use OCA\InventoryCheck\Controller\MobileController;
use OCA\InventoryCheck\Exception\MobileRequestException;
use OCA\InventoryCheck\Service\AccessControlService;
use OCA\InventoryCheck\Service\BalanceService;
use OCA\InventoryCheck\Service\CycleCountService;
use OCA\InventoryCheck\Service\DevicePairingService;
use OCA\InventoryCheck\Service\ItemPhotoService;
use OCA\InventoryCheck\Service\ItemService;
use OCA\InventoryCheck\Service\ScannerService;
use OCA\InventoryCheck\Service\LocationFavouriteService;
use OCA\InventoryCheck\Service\LocationService;
use OCA\InventoryCheck\Service\MobileAccessService;
use OCA\InventoryCheck\Service\MovementService;
use OCA\InventoryCheck\Service\ProductLookupService;
use OCA\InventoryCheck\Service\ScanIdempotencyService;
use OCA\InventoryCheck\Tests\Support\Iv2TestSigning;
use OCP\IConfig;
use OCP\IL10N;
use OCP\IRequest;
use OCP\ISession;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\Server;
use Test\TestCase;

/**
 * Companion P1/P2 mobile favourites + bootstrap companionApi=5.
 *
 * @group DB
 */
final class MobileFavouritesAndStocktakeIntegrationTest extends TestCase
{
	private const USER = 'iv_mob_fav_u';
	private const PASSWORD = 'Iv-MobFav-9xK!';

	/** @var string|false */
	private $prevEnv;

	protected function setUp(): void
	{
		parent::setUp();
		if (!class_exists(\OC::class) || !isset(\OC::$server)) {
			$this->markTestSkipped('Nextcloud runtime required');
		}
		\OC_User::setIncognitoMode(false);
		$this->prevEnv = getenv('IV_VENDOR_PUBLIC_KEY_B64');
		putenv('IV_VENDOR_PUBLIC_KEY_B64=' . Iv2TestSigning::publicKeyB64());

		$um = Server::get(IUserManager::class);
		if ($um->userExists(self::USER)) {
			$um->get(self::USER)?->delete();
		}
		$um->createUser(self::USER, self::PASSWORD);

		$config = Server::get(IConfig::class);
		$config->setAppValue(Application::APP_ID, AccessControlService::KEY_ACCESS_RESTRICTION, '0');

		$license = (new Application())->getContainer()->get(ScannerService::class);
		$license->apply('admin', Iv2TestSigning::signPayload([
			'v' => 2,
			'product' => 'inventorycheck',
			'customerId' => 'mob-fav-' . bin2hex(random_bytes(2)),
			'issuedAt' => '2026-07-24',
			'validUntil' => '2099-01-01',
			'mobileSeats' => 5,
			'scanDevices' => 2,
		]));
		$license->assignSeat('admin', self::USER);
	}

	protected function tearDown(): void
	{
		if ($this->prevEnv === false) {
			putenv('IV_VENDOR_PUBLIC_KEY_B64');
		} else {
			putenv('IV_VENDOR_PUBLIC_KEY_B64=' . $this->prevEnv);
		}
		if (isset(\OC::$server)) {
			$um = Server::get(IUserManager::class);
			if ($um->userExists(self::USER)) {
				$um->get(self::USER)?->delete();
			}
		}
		parent::tearDown();
	}

	private function controller(?array $params = null): MobileController
	{
		$user = Server::get(IUserManager::class)->get(self::USER);
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$request = $this->createMock(IRequest::class);
		$request->method('getHeader')->willReturn('');
		$request->method('passesCSRFCheck')->willReturn(true);
		$params = $params ?? [];
		$request->method('getParam')->willReturnCallback(static function (string $k, $default = null) use ($params) {
			return $params[$k] ?? $default;
		});
		$request->method('getParams')->willReturn($params);

		return new MobileController(
			$request,
			Server::get(MobileAccessService::class),
			Server::get(ScannerService::class),
			Server::get(DevicePairingService::class),
			Server::get(ItemService::class),
			Server::get(LocationService::class),
			Server::get(BalanceService::class),
			Server::get(MovementService::class),
			Server::get(AccessControlService::class),
			Server::get(LocationFavouriteService::class),
			Server::get(CycleCountService::class),
			Server::get(ItemPhotoService::class),
			Server::get(ScanIdempotencyService::class),
			Server::get(ProductLookupService::class),
			$session,
			Server::get(ISession::class),
			Server::get(IConfig::class),
			Server::get(\OCP\L10N\IFactory::class)->get(Application::APP_ID),
		);
	}

	public function testBootstrapAdvertisesFavouritesAndCompanionApi5(): void
	{
		$data = $this->controller()->bootstrap()->getData();
		self::assertSame(5, $data['companionApi']);
		self::assertTrue($data['capabilities']['favourites']);
		self::assertTrue($data['capabilities']['cycleCount']);
		self::assertTrue($data['capabilities']['itemPhoto']);
		self::assertArrayHasKey('canWrite', $data);
		self::assertSame(self::USER, $data['user']);
	}

	public function testFavouritesRoundTrip(): void
	{
		$locations = Server::get(LocationService::class);
		$loc = $locations->create('admin', [
			'code' => 'BIN-FAV-' . substr(bin2hex(random_bytes(2)), 0, 4),
			'name' => 'Fav bin',
			'kind' => 'shelf',
		]);
		$id = (int)$loc['id'];
		$added = $this->controller(['locationId' => $id])->addFavourite()->getData();
		self::assertNotEmpty($added['data']);
		$list = $this->controller()->favourites()->getData();
		self::assertTrue(count(array_filter($list['data'], static fn ($r) => (int)$r['id'] === $id)) === 1);
		$removed = $this->controller()->removeFavourite($id)->getData();
		self::assertTrue(count(array_filter($removed['data'], static fn ($r) => (int)$r['id'] === $id)) === 0);
	}

	public function testSessionMutationWithoutCsrfIsRejected(): void
	{
		$user = Server::get(IUserManager::class)->get(self::USER);
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$request = $this->createMock(IRequest::class);
		$request->method('getHeader')->willReturn('');
		$request->method('passesCSRFCheck')->willReturn(false);
		$request->method('getParam')->willReturn(0);
		$request->method('getParams')->willReturn(['locationId' => 1]);

		$controller = new MobileController(
			$request,
			Server::get(MobileAccessService::class),
			Server::get(ScannerService::class),
			Server::get(DevicePairingService::class),
			Server::get(ItemService::class),
			Server::get(LocationService::class),
			Server::get(BalanceService::class),
			Server::get(MovementService::class),
			Server::get(AccessControlService::class),
			Server::get(LocationFavouriteService::class),
			Server::get(CycleCountService::class),
			Server::get(ItemPhotoService::class),
			Server::get(ScanIdempotencyService::class),
			Server::get(ProductLookupService::class),
			$session,
			Server::get(ISession::class),
			Server::get(IConfig::class),
			Server::get(\OCP\L10N\IFactory::class)->get(Application::APP_ID),
		);

		$this->expectException(MobileRequestException::class);
		$this->expectExceptionMessage('auth_required');
		$controller->addFavourite();
	}

	public function testSessionMutationWithForgedBearerStillRequiresCsrf(): void
	{
		$user = Server::get(IUserManager::class)->get(self::USER);
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$request = $this->createMock(IRequest::class);
		$request->method('getHeader')->willReturnCallback(static function (string $name): string {
			return strcasecmp($name, 'Authorization') === 0 ? 'Bearer forged-token' : '';
		});
		$request->method('passesCSRFCheck')->willReturn(false);
		$request->method('getParam')->willReturn(0);
		$request->method('getParams')->willReturn(['locationId' => 1]);

		$controller = new MobileController(
			$request,
			Server::get(MobileAccessService::class),
			Server::get(ScannerService::class),
			Server::get(DevicePairingService::class),
			Server::get(ItemService::class),
			Server::get(LocationService::class),
			Server::get(BalanceService::class),
			Server::get(MovementService::class),
			Server::get(AccessControlService::class),
			Server::get(LocationFavouriteService::class),
			Server::get(CycleCountService::class),
			Server::get(ItemPhotoService::class),
			Server::get(ScanIdempotencyService::class),
			Server::get(ProductLookupService::class),
			$session,
			Server::get(ISession::class),
			Server::get(IConfig::class),
			Server::get(\OCP\L10N\IFactory::class)->get(Application::APP_ID),
		);

		$this->expectException(MobileRequestException::class);
		$this->expectExceptionMessage('auth_required');
		$controller->addFavourite();
	}

	public function testCycleCountsListDoesNotRequireOffice(): void
	{
		$data = $this->controller()->cycleCounts()->getData();
		self::assertArrayHasKey('data', $data);
		self::assertIsArray($data['data']);
	}

	/**
	 * Device tokens are field-only — favourites / inventur need a named seat (AF-IV7/16).
	 */
	public function testDeviceTokenCannotAccessFavouritesOrCycleCounts(): void
	{
		$created = Server::get(ScannerService::class)->createDevice('admin', 'fav-device-deny');
		$paired = Server::get(DevicePairingService::class)->pair($created['pairCode']);
		$token = $paired['token'];

		Server::get(IUserSession::class)->setUser(null);
		$request = $this->createMock(IRequest::class);
		$request->method('getHeader')->with('X-IV-Device-Token')->willReturn($token);
		$request->method('getParam')->willReturn(null);
		$request->method('getParams')->willReturn([]);

		$controller = new MobileController(
			$request,
			Server::get(MobileAccessService::class),
			Server::get(ScannerService::class),
			Server::get(DevicePairingService::class),
			Server::get(ItemService::class),
			Server::get(LocationService::class),
			Server::get(BalanceService::class),
			Server::get(MovementService::class),
			Server::get(AccessControlService::class),
			Server::get(LocationFavouriteService::class),
			Server::get(CycleCountService::class),
			Server::get(ItemPhotoService::class),
			Server::get(ScanIdempotencyService::class),
			Server::get(ProductLookupService::class),
			Server::get(IUserSession::class),
			Server::get(ISession::class),
			Server::get(IConfig::class),
			Server::get(\OCP\L10N\IFactory::class)->get(Application::APP_ID),
		);

		try {
			$controller->favourites();
			self::fail('device favourites must throw auth_required');
		} catch (MobileRequestException $e) {
			self::assertSame('auth_required', $e->getErrorCode());
		}

		try {
			$controller->cycleCounts();
			self::fail('device cycleCounts must throw auth_required');
		} catch (MobileRequestException $e) {
			self::assertSame('auth_required', $e->getErrorCode());
		}
	}
}
