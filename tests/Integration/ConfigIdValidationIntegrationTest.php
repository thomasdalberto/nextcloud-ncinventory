<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Tests\Integration;

use OCA\InventoryCheck\AppInfo\Application;
use OCA\InventoryCheck\Controller\ConfigController;
use OCA\InventoryCheck\Exception\ValidationException;
use OCA\InventoryCheck\Middleware\AppAccessMiddleware;
use OCA\InventoryCheck\Service\AccessControlService;
use OCA\InventoryCheck\Service\LocationAclService;
use OCA\InventoryCheck\Service\LowStockService;
use OCA\InventoryCheck\Service\ProductLookupService;
use OCA\InventoryCheck\Service\QtyScaleService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IConfig;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\Server;
use Test\TestCase;

/**
 * Live directory validation — typos in allow/office lists must 422 without writing.
 *
 * @group DB
 */
final class ConfigIdValidationIntegrationTest extends TestCase
{
	private const PASSWORD = 'Iv-CfgVal-9xK!zz';
	private const ADMIN = 'iv_cfg_admin';
	private const MEMBER = 'iv_cfg_member';

	/** @var array<string, string> */
	private array $prevConfig = [];

	private const KEYS = [
		AccessControlService::KEY_ACCESS_RESTRICTION,
		AccessControlService::KEY_ACCESS_ALLOWED_USER_IDS,
		AccessControlService::KEY_ACCESS_ALLOWED_GROUP_IDS,
		AccessControlService::KEY_APP_ADMINS,
		AccessControlService::KEY_OFFICE_USER_IDS,
		AccessControlService::KEY_OFFICE_GROUP_IDS,
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
		$um = Server::get(IUserManager::class);
		$um->createUser(self::ADMIN, self::PASSWORD);
		$um->createUser(self::MEMBER, self::PASSWORD);

		$gm = Server::get(IGroupManager::class);
		$adminGroup = $gm->get('admin') ?? $gm->createGroup('admin');
		$adminGroup->addUser($um->get(self::ADMIN));

		$config->setAppValue(Application::APP_ID, AccessControlService::KEY_ACCESS_RESTRICTION, '0');
		$config->setAppValue(Application::APP_ID, AccessControlService::KEY_APP_ADMINS, '[]');
		$config->setAppValue(Application::APP_ID, AccessControlService::KEY_ACCESS_ALLOWED_USER_IDS, '[]');
		$config->setAppValue(Application::APP_ID, AccessControlService::KEY_ACCESS_ALLOWED_GROUP_IDS, '[]');
		$config->setAppValue(Application::APP_ID, AccessControlService::KEY_OFFICE_USER_IDS, '[]');
		$config->setAppValue(Application::APP_ID, AccessControlService::KEY_OFFICE_GROUP_IDS, '[]');
	}

	protected function tearDown(): void
	{
		$config = Server::get(IConfig::class);
		foreach ($this->prevConfig as $key => $value) {
			if ($value === '') {
				$config->deleteAppValue(Application::APP_ID, $key);
			} else {
				$config->setAppValue(Application::APP_ID, $key, $value);
			}
		}
		$this->deleteUsers();
		parent::tearDown();
	}

	private function deleteUsers(): void
	{
		$um = Server::get(IUserManager::class);
		foreach ([self::ADMIN, self::MEMBER] as $uid) {
			$user = $um->get($uid);
			if ($user !== null) {
				$user->delete();
			}
		}
	}

	private function loginAs(string $uid): void
	{
		$session = Server::get(IUserSession::class);
		$user = Server::get(IUserManager::class)->get($uid);
		$this->assertNotNull($user);
		$session->setUser($user);
	}

	/**
	 * @param array<string, mixed> $params
	 */
	private function controllerWithParams(array $params): ConfigController
	{
		$request = $this->createMock(IRequest::class);
		$request->method('getParams')->willReturn($params);
		return new ConfigController(
			$request,
			Server::get(AccessControlService::class),
			Server::get(IUserManager::class),
			Server::get(IGroupManager::class),
			Server::get(LowStockService::class),
			Server::get(QtyScaleService::class),
			Server::get(LocationAclService::class),
			Server::get(ProductLookupService::class),
			Server::get(IConfig::class),
			Server::get(\Psr\Log\LoggerInterface::class),
		);
	}

	private function middleware(): AppAccessMiddleware
	{
		return Server::get(AppAccessMiddleware::class);
	}

	public function testUnknownAllowedUserDoesNotWriteAndReturns422(): void
	{
		$this->loginAs(self::ADMIN);
		$acl = Server::get(AccessControlService::class);
		$before = $acl->getJsonIdList(AccessControlService::KEY_ACCESS_ALLOWED_USER_IDS);

		$config = $this->controllerWithParams([
			'allowedUsers' => [self::MEMBER, 'definitely-not-a-user-zz'],
		]);

		try {
			$config->saveAccess();
			$this->fail('expected ValidationException');
		} catch (ValidationException $e) {
			$response = $this->middleware()->afterException($config, 'saveAccess', $e);
			$this->assertInstanceOf(JSONResponse::class, $response);
			$this->assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());
			$data = $response->getData();
			$this->assertSame('unknown_user', $data['error']['code']);
			$this->assertSame('allowedUsers', $data['error']['details'][0]['field']);
		}

		$this->assertSame($before, $acl->getJsonIdList(AccessControlService::KEY_ACCESS_ALLOWED_USER_IDS));
	}

	public function testUnknownGroupDoesNotWrite(): void
	{
		$this->loginAs(self::ADMIN);
		$acl = Server::get(AccessControlService::class);
		$before = $acl->getJsonIdList(AccessControlService::KEY_ACCESS_ALLOWED_GROUP_IDS);

		$config = $this->controllerWithParams([
			'allowedGroups' => ['no-such-group-iv-zz'],
		]);

		try {
			$config->saveAccess();
			$this->fail('expected ValidationException');
		} catch (ValidationException $e) {
			$this->assertSame('unknown_group', $e->getErrorCode());
			$response = $this->middleware()->afterException($config, 'saveAccess', $e);
			$this->assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());
			$this->assertSame('unknown_group', $response->getData()['error']['code']);
		}

		$this->assertSame($before, $acl->getJsonIdList(AccessControlService::KEY_ACCESS_ALLOWED_GROUP_IDS));
	}

	public function testValidMemberCanBeAllowListed(): void
	{
		$this->loginAs(self::ADMIN);
		$config = $this->controllerWithParams(['allowedUsers' => [self::MEMBER]]);
		$response = $config->saveAccess();
		$this->assertSame(200, $response->getStatus());
		$acl = Server::get(AccessControlService::class);
		$this->assertSame([self::MEMBER], $acl->getJsonIdList(AccessControlService::KEY_ACCESS_ALLOWED_USER_IDS));
	}

	public function testOfficeUnknownUserRejected(): void
	{
		$this->loginAs(self::ADMIN);
		$config = $this->controllerWithParams(['officeUsers' => ['ghost-office-user']]);

		try {
			$config->saveOffice();
			$this->fail('expected ValidationException');
		} catch (ValidationException $e) {
			$this->assertSame('unknown_user', $e->getErrorCode());
			$this->assertSame('officeUsers', $e->getDetails()[0]['field']);
		}
	}

	public function testSystemAdminCanSetAppAdminToExistingUser(): void
	{
		$this->loginAs(self::ADMIN);
		$config = $this->controllerWithParams(['appAdmins' => [self::MEMBER]]);
		$response = $config->saveAccess();
		$this->assertSame(200, $response->getStatus());
		$acl = Server::get(AccessControlService::class);
		$this->assertSame([self::MEMBER], $acl->getJsonIdList(AccessControlService::KEY_APP_ADMINS));
		$this->assertTrue($acl->isWorkspaceAdmin(self::MEMBER));
	}

	public function testPartialPayloadDoesNotWriteWhenSecondListInvalid(): void
	{
		$this->loginAs(self::ADMIN);
		$acl = Server::get(AccessControlService::class);
		$acl->setJsonIdList(AccessControlService::KEY_ACCESS_ALLOWED_USER_IDS, [self::MEMBER]);
		$beforeUsers = $acl->getJsonIdList(AccessControlService::KEY_ACCESS_ALLOWED_USER_IDS);
		$beforeGroups = $acl->getJsonIdList(AccessControlService::KEY_ACCESS_ALLOWED_GROUP_IDS);

		$config = $this->controllerWithParams([
			'allowedUsers' => [self::MEMBER],
			'allowedGroups' => ['no-such-group-iv-partial'],
		]);

		try {
			$config->saveAccess();
			$this->fail('expected ValidationException');
		} catch (ValidationException $e) {
			$this->assertSame('unknown_group', $e->getErrorCode());
		}

		$this->assertSame($beforeUsers, $acl->getJsonIdList(AccessControlService::KEY_ACCESS_ALLOWED_USER_IDS));
		$this->assertSame($beforeGroups, $acl->getJsonIdList(AccessControlService::KEY_ACCESS_ALLOWED_GROUP_IDS));
	}

	public function testStringZeroDoesNotEnableRestriction(): void
	{
		$this->loginAs(self::ADMIN);
		$acl = Server::get(AccessControlService::class);
		$acl->setAccessRestrictionEnabled(true);

		$config = $this->controllerWithParams([
			'accessRestrictionEnabled' => '0',
		]);
		$response = $config->saveAccess();
		$this->assertSame(200, $response->getStatus());
		$this->assertFalse($acl->isAccessRestrictionEnabled());
	}
}
