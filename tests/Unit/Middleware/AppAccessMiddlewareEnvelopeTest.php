<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Controller {
	/**
	 * Test-only stubs so get_class() retains the Controller namespace prefix
	 * that AppAccessMiddleware requires (PHPUnit mocks do not).
	 */
	final class EnvelopeTestItemController
	{
	}

	final class EnvelopeTestMobileController
	{
	}
}

namespace OCA\InventoryCheck\Tests\Unit\Middleware {

use OCA\InventoryCheck\Controller\EnvelopeTestItemController;
use OCA\InventoryCheck\Controller\EnvelopeTestMobileController;
use OCA\InventoryCheck\Exception\AppAccessDeniedException;
use OCA\InventoryCheck\Exception\ConflictException;
use OCA\InventoryCheck\Exception\InsufficientStockException;
use OCA\InventoryCheck\Exception\MobileRequestException;
use OCA\InventoryCheck\Exception\NotFoundException;
use OCA\InventoryCheck\Exception\PermissionDeniedException;
use OCA\InventoryCheck\Exception\ValidationException;
use OCA\InventoryCheck\Middleware\AppAccessMiddleware;
use OCA\InventoryCheck\Service\AccessControlService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\Files\EntityTooLargeException as FilesEntityTooLargeException;
use OCP\Files\ForbiddenException as FilesForbiddenException;
use OCP\Files\GenericFileException;
use OCP\Files\LockNotAcquiredException;
use OCP\Files\NotEnoughSpaceException;
use OCP\Files\NotPermittedException as FilesNotPermittedException;
use OCP\Files\StorageNotAvailableException;
use OCP\Files\StorageTimeoutException;
use OCP\IL10N;
use OCA\InventoryCheck\Service\WorkspaceSettings;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserSession;
use OCP\L10N\IFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * SPEC §7.1 error envelope + AC-2 page vs JSON access denial.
 * Pure unit — no DB.
 */
final class AppAccessMiddlewareEnvelopeTest extends TestCase
{
	/** @var IUserSession&MockObject */
	private IUserSession $userSession;
	/** @var AccessControlService&MockObject */
	private AccessControlService $access;
	/** @var IRequest&MockObject */
	private IRequest $request;
	private AppAccessMiddleware $middleware;
	private EnvelopeTestItemController $itemController;
	private EnvelopeTestMobileController $mobileController;

	protected function setUp(): void
	{
		parent::setUp();
		$this->userSession = $this->createMock(IUserSession::class);
		$this->access = $this->createMock(AccessControlService::class);
		$this->request = $this->createMock(IRequest::class);

		$url = $this->createMock(IURLGenerator::class);
		$url->method('linkToDefaultPageUrl')->willReturn('/index.php/apps/files');

		$l = $this->createMock(IL10N::class);
		$l->method('t')->willReturnCallback(static function (string $text, $params = []): string {
			if (is_array($params) && $params !== []) {
				return $text . '|' . implode(',', array_map('strval', $params));
			}
			return $text;
		});
		$factory = $this->createMock(IFactory::class);
		$factory->method('get')->willReturn($l);

		$this->middleware = new AppAccessMiddleware(
			$this->userSession,
			$this->access,
			$this->request,
			$url,
			$factory,
			$this->createMock(WorkspaceSettings::class),
			$this->createMock(\Psr\Log\LoggerInterface::class),
			$this->createMock(\OCA\InventoryCheck\Service\WorkspaceContext::class),
		);

		$this->itemController = new EnvelopeTestItemController();
		$this->mobileController = new EnvelopeTestMobileController();
	}

	private function apiPath(): void
	{
		$this->request->method('getPathInfo')->willReturn('/apps/inventorycheck/api/items');
		$this->request->method('getMethod')->willReturn('GET');
	}

	private function pagePath(): void
	{
		$this->request->method('getPathInfo')->willReturn('/apps/inventorycheck/');
		$this->request->method('getMethod')->willReturn('GET');
	}

	private function sessionUser(string $uid): void
	{
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$this->userSession->method('getUser')->willReturn($user);
	}

	public function testPairDeviceSkipsAccessGate(): void
	{
		$this->sessionUser('anyone');
		$this->middleware->beforeController($this->mobileController, 'pairDevice');
		$this->middleware->beforeController($this->mobileController, 'scan');
		$this->addToAssertionCount(1);
	}

	public function testBeforeControllerPassesWhenCanUseApp(): void
	{
		$this->sessionUser('alice');
		$this->middleware->beforeController($this->itemController, 'index');
		$this->addToAssertionCount(1);
	}

	public function testPostWithoutApiPathIsJsonRoute(): void
	{
		$this->request->method('getPathInfo')->willReturn('/apps/inventorycheck/items');
		$this->request->method('getMethod')->willReturn('POST');
		$response = $this->middleware->afterException(
			$this->itemController,
			'create',
			new AppAccessDeniedException(AccessControlService::DENIAL_RESTRICTION),
		);
		$this->assertInstanceOf(JSONResponse::class, $response);
	}

	public function testNotFoundCodeNotFound(): void
	{
		$this->apiPath();
		$response = $this->middleware->afterException(
			$this->itemController,
			'byCode',
			new NotFoundException('code_not_found'),
		);
		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		$this->assertSame('code_not_found', $response->getData()['error']['code']);
	}

	public function testInsufficientStockConflict(): void
	{
		$this->apiPath();
		$response = $this->middleware->afterException(
			$this->itemController,
			'issue',
			new InsufficientStockException(3, 'VAN-1'),
		);
		$this->assertSame(Http::STATUS_CONFLICT, $response->getStatus());
		$this->assertSame('insufficient_stock', $response->getData()['error']['code']);
		$this->assertSame(
			'Only %s left in %s.|3,VAN-1',
			$response->getData()['error']['message'],
			'UJ-3 toast must name available qty and location (not plural %n misuse)',
		);
	}

	public function testInsufficientStockWithoutLocationLabel(): void
	{
		$this->apiPath();
		$response = $this->middleware->afterException(
			$this->itemController,
			'issue',
			new InsufficientStockException(7),
		);
		$this->assertSame(
			'Not enough stock at this location (available: %s).|7',
			$response->getData()['error']['message'],
		);
	}

	public function testUnknownItemNotFound(): void
	{
		$this->apiPath();
		$response = $this->middleware->afterException(
			$this->itemController,
			'show',
			new NotFoundException('unknown_item'),
		);
		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		$this->assertSame('unknown_item', $response->getData()['error']['code']);
	}

	public function testUnknownLocationNotFound(): void
	{
		$this->apiPath();
		$response = $this->middleware->afterException(
			$this->itemController,
			'show',
			new NotFoundException('unknown_location'),
		);
		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		$this->assertSame('unknown_location', $response->getData()['error']['code']);
	}

	public function testCodeExistsConflict(): void
	{
		$this->apiPath();
		$response = $this->middleware->afterException(
			$this->itemController,
			'create',
			new ConflictException('code_exists'),
		);
		$this->assertSame(Http::STATUS_CONFLICT, $response->getStatus());
		$this->assertSame('code_exists', $response->getData()['error']['code']);
	}

	/** Transient track_mode lock race — 423 so companions can retry like ApiError.isLocked. */
	public function testItemLockConflictReturns423Locked(): void
	{
		$this->apiPath();
		$response = $this->middleware->afterException(
			$this->itemController,
			'receive',
			new ConflictException('item_lock_conflict'),
		);
		$this->assertSame(Http::STATUS_LOCKED, $response->getStatus());
		$this->assertSame(423, $response->getStatus());
		$this->assertSame('item_lock_conflict', $response->getData()['error']['code']);
		$this->assertNotSame('', $response->getData()['error']['message']);
	}

	public function testValidationWithDetails(): void
	{
		$this->apiPath();
		$response = $this->middleware->afterException(
			$this->itemController,
			'receive',
			new ValidationException('invalid_qty', '', [['field' => 'qty', 'code' => 'invalid_qty']]),
		);
		$this->assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());
		$data = $response->getData();
		$this->assertSame('invalid_qty', $data['error']['code']);
		$this->assertSame([['field' => 'qty', 'code' => 'invalid_qty']], $data['error']['details']);
	}

	/** AF-IV20: web session controllers must never map MobileRequestException to 402. */
	/** AF-IV20: license_missing on a non-mobile controller must not become 402 either. */
	public function testPhotoNotFoundEnvelope(): void
	{
		$this->apiPath();
		$response = $this->middleware->afterException(
			$this->itemController,
			'show',
			new NotFoundException('photo_not_found'),
		);
		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		$this->assertSame('photo_not_found', $response->getData()['error']['code']);
	}

	public function testMobileGateAuthRequiredUnauthorized(): void
	{
		$this->request->method('getPathInfo')->willReturn('/apps/inventorycheck/mobile/v1/bootstrap');
		$this->request->method('getMethod')->willReturn('GET');
		$response = $this->middleware->afterException(
			$this->mobileController,
			'bootstrap',
			new MobileRequestException('auth_required'),
		);
		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		$this->assertSame('auth_required', $response->getData()['error']['code']);
	}

	public function testMobileGateRateLimited(): void
	{
		$this->request->method('getPathInfo')->willReturn('/apps/inventorycheck/mobile/pair');
		$this->request->method('getMethod')->willReturn('POST');
		$response = $this->middleware->afterException(
			$this->mobileController,
			'pairDevice',
			new MobileRequestException('rate_limited'),
		);
		$this->assertSame(429, $response->getStatus());
		$this->assertSame('rate_limited', $response->getData()['error']['code']);
	}

	public function testUnknownExceptionRethrown(): void
	{
		$this->apiPath();
		$this->expectException(\RuntimeException::class);
		$this->middleware->afterException(
			$this->itemController,
			'index',
			new \RuntimeException('boom'),
		);
	}

	/**
	 * must_fix F1: POST /api/items/{id}/photo — an unwritable appdata
	 * item_photos folder threw OCP\Files\NotPermittedException, which escaped
	 * afterException's `throw $exception` tail and surfaced as a raw
	 * framework HTML 500 to JSON clients. Storage/permission faults must now
	 * serialize as the standard error envelope.
	 */
	public function testPhotoUploadStorageNotPermittedReturnsJson403(): void
	{
		$this->request->method('getPathInfo')->willReturn('/apps/inventorycheck/api/items/12/photo');
		$this->request->method('getMethod')->willReturn('POST');
		$response = $this->middleware->afterException(
			$this->itemController,
			'upload',
			new FilesNotPermittedException('appdata item_photos is not writable'),
		);
		$this->assertInstanceOf(JSONResponse::class, $response);
		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		$data = $response->getData();
		$this->assertSame('storage_permission_denied', $data['error']['code']);
		$this->assertSame([], $data['error']['details'], 'SPEC §7.1: details is always present');
		$this->assertNotSame('', (string)$data['error']['message']);
	}

	public function testStorageForbiddenReturnsJson403(): void
	{
		$this->apiPath();
		$response = $this->middleware->afterException(
			$this->itemController,
			'upload',
			new FilesForbiddenException('denied', false),
		);
		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		$this->assertSame('storage_permission_denied', $response->getData()['error']['code']);
	}

	public function testStorageEntityTooLargeReturnsJson413(): void
	{
		$this->apiPath();
		$response = $this->middleware->afterException(
			$this->itemController,
			'upload',
			new FilesEntityTooLargeException('too big'),
		);
		$this->assertSame(Http::STATUS_REQUEST_ENTITY_TOO_LARGE, $response->getStatus());
		$this->assertSame('storage_entity_too_large', $response->getData()['error']['code']);
	}

	public function testStorageLockNotAcquiredReturnsJson423(): void
	{
		$this->apiPath();
		// Ctor calls \OCP\Util::getL10N — bypass it so the test also runs in
		// vendor-only (stub) mode without a live server container.
		$lock = (new \ReflectionClass(LockNotAcquiredException::class))->newInstanceWithoutConstructor();
		$response = $this->middleware->afterException(
			$this->itemController,
			'upload',
			$lock,
		);
		$this->assertSame(Http::STATUS_LOCKED, $response->getStatus());
		$this->assertSame('storage_locked', $response->getData()['error']['code']);
	}

	public function testStorageFullReturnsJson507(): void
	{
		$this->apiPath();
		$response = $this->middleware->afterException(
			$this->itemController,
			'upload',
			new NotEnoughSpaceException('disk full'),
		);
		$this->assertSame(Http::STATUS_INSUFFICIENT_STORAGE, $response->getStatus());
		$this->assertSame('storage_full', $response->getData()['error']['code']);
	}

	public function testStorageUnavailableReturnsJson503(): void
	{
		$this->apiPath();
		$response = $this->middleware->afterException(
			$this->itemController,
			'show',
			new StorageNotAvailableException('backend down'),
		);
		$this->assertSame(Http::STATUS_SERVICE_UNAVAILABLE, $response->getStatus());
		$this->assertSame('storage_unavailable', $response->getData()['error']['code']);
	}

	/** StorageTimeoutException subclasses StorageNotAvailableException — same mapping. */
	public function testStorageTimeoutSubclassReturnsJson503(): void
	{
		$this->apiPath();
		$response = $this->middleware->afterException(
			$this->itemController,
			'show',
			new StorageTimeoutException('backend timed out'),
		);
		$this->assertSame(Http::STATUS_SERVICE_UNAVAILABLE, $response->getStatus());
		$this->assertSame('storage_unavailable', $response->getData()['error']['code']);
	}

	public function testStorageGenericFileErrorReturnsJson500(): void
	{
		$this->apiPath();
		$response = $this->middleware->afterException(
			$this->itemController,
			'upload',
			new GenericFileException('write failed'),
		);
		$this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		$this->assertSame('storage_error', $response->getData()['error']['code']);
		$this->assertSame([], $response->getData()['error']['details']);
	}

	/** The namespace guard still applies: storage faults from foreign controllers rethrow. */
	public function testStorageExceptionOnForeignControllerStillRethrows(): void
	{
		$this->apiPath();
		$this->expectException(FilesNotPermittedException::class);
		$this->middleware->afterException(
			$this, // test-class object — not under OCA\InventoryCheck\Controller\
			'upload',
			new FilesNotPermittedException('denied'),
		);
	}
}

}
