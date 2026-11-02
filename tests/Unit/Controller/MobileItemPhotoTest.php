<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Tests\Unit\Controller;

use OCA\InventoryCheck\Controller\MobileController;
use OCA\InventoryCheck\Exception\MobileRequestException;
use OCA\InventoryCheck\Exception\NotFoundException;
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
use OCA\InventoryCheck\Service\ScanIdempotencyService;
use OCP\AppFramework\Http\DataDisplayResponse;
use OCA\InventoryCheck\Service\WorkspaceSettings;
use OCP\IRequest;
use OCP\ISession;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Companion GET /mobile/v1/items/{id}/photo — auth + photo_not_found.
 */
final class MobileItemPhotoTest extends TestCase
{
	/** @var IRequest&MockObject */
	private IRequest $request;
	/** @var MobileAccessService&MockObject */
	private MobileAccessService $gate;
	/** @var ItemPhotoService&MockObject */
	private ItemPhotoService $photos;
	/** @var IUserSession&MockObject */
	private IUserSession $session;
	/** @var ISession&MockObject */
	private ISession $appSession;
	private MobileController $controller;

	protected function setUp(): void
	{
		parent::setUp();
		$this->request = $this->createMock(IRequest::class);
		$this->gate = $this->createMock(MobileAccessService::class);
		$this->photos = $this->createMock(ItemPhotoService::class);
		$this->session = $this->createMock(IUserSession::class);
		$this->appSession = $this->createMock(ISession::class);

		$this->controller = new MobileController(
			$this->request,
			$this->gate,
			$this->createMock(ScannerService::class),
			$this->createMock(DevicePairingService::class),
			$this->createMock(ItemService::class),
			$this->createMock(LocationService::class),
			$this->createMock(BalanceService::class),
			$this->createMock(MovementService::class),
			$this->createMock(AccessControlService::class),
			$this->createMock(LocationFavouriteService::class),
			$this->createMock(CycleCountService::class),
			$this->photos,
			$this->createMock(ScanIdempotencyService::class),
			$this->createMock(\OCA\InventoryCheck\Service\ProductLookupService::class),
			$this->session,
			$this->appSession,
			$this->createMock(WorkspaceSettings::class),
			$this->createMock(\OCP\IL10N::class),
			$this->createMock(\OCA\InventoryCheck\Service\WorkspaceContext::class),
		);
	}

	public function testItemPhotoRequiresAuth(): void
	{
		$this->request->method('getHeader')->with('X-IV-Device-Token')->willReturn('');
		$this->session->method('getUser')->willReturn(null);
		$this->expectException(MobileRequestException::class);
		try {
			$this->controller->itemPhoto(42);
		} catch (MobileRequestException $e) {
			$this->assertSame('auth_required', $e->getErrorCode());
			throw $e;
		}
	}

	public function testItemPhotoNotFound(): void
	{
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$this->request->method('getHeader')->with('X-IV-Device-Token')->willReturn('');
		$this->session->method('getUser')->willReturn($user);
		$this->gate->expects($this->once())->method('requireCaller')->with('alice', null);
		$this->photos->method('read')->with(99)->willThrowException(new NotFoundException('photo_not_found'));

		$this->expectException(NotFoundException::class);
		try {
			$this->controller->itemPhoto(99);
		} catch (NotFoundException $e) {
			$this->assertSame('photo_not_found', $e->getErrorCode());
			throw $e;
		}
	}

	public function testItemPhotoReturnsImage(): void
	{
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$this->request->method('getHeader')->with('X-IV-Device-Token')->willReturn('');
		$this->session->method('getUser')->willReturn($user);
		$this->gate->expects($this->once())->method('requireCaller')->with('alice', null);
		$this->photos->method('read')->with(7)->willReturn([
			'content' => 'JPEGDATA',
			'mime' => 'image/jpeg',
			'name' => 'item-7.jpg',
		]);

		$response = $this->controller->itemPhoto(7);
		$this->assertInstanceOf(DataDisplayResponse::class, $response);
		$this->assertSame(200, $response->getStatus());
		$this->assertSame('image/jpeg', $response->getHeaders()['Content-Type'] ?? null);
	}
}
