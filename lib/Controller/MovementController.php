<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Controller;

use OCA\InventoryCheck\AppInfo\Application;
use OCA\InventoryCheck\Service\AccessControlService;
use OCA\InventoryCheck\Service\MovementService;
use OCA\InventoryCheck\Service\Pagination;
use OCA\InventoryCheck\Service\QtyScale;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCA\InventoryCheck\Service\WorkspaceSettings;
use OCP\IRequest;

/**
 * Wave C1: request qty fields are converted display → storage here (the one
 * boundary every movement passes through), and response bodies are
 * converted storage → display in {@see toDisplay()} — {@see MovementService}
 * itself only ever sees/returns plain integer storage units.
 */
class MovementController extends Controller
{
	public function __construct(
		IRequest $request,
		private readonly MovementService $movements,
		private readonly AccessControlService $access,
		private readonly WorkspaceSettings $settings,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	#[NoAdminRequired]
	public function index(): JSONResponse
	{
		$page = Pagination::parse($this->request->getParam('limit'), $this->request->getParam('offset'));
		$from = $this->request->getParam('from');
		$to = $this->request->getParam('to');
		$itemId = $this->request->getParam('itemId');
		$locationId = $this->request->getParam('locationId');
		$result = $this->movements->list(
			$this->access->currentUserId(),
			$this->request->getParam('kind'),
			$itemId !== null && $itemId !== '' ? (int)$itemId : null,
			$locationId !== null && $locationId !== '' ? (int)$locationId : null,
			$from !== null && $from !== '' ? (int)$from : null,
			$to !== null && $to !== '' ? (int)$to : null,
			$this->request->getParam('transferGroup'),
			$page['limit'],
			$page['offset'],
			($rc = $this->request->getParam('reasonCode')) !== null && $rc !== '' ? (string)$rc : null,
		);
		$result['data'] = array_map(fn (array $m) => QtyScale::formatMovement($m, $this->settings), $result['data']);
		return new JSONResponse($result);
	}

	#[NoAdminRequired]
	public function receive(): JSONResponse
	{
		$p = $this->request->getParams();
		return new JSONResponse($this->toDisplay($this->movements->receive(
			$this->access->currentUserId(),
			(int)($p['itemId'] ?? 0),
			(int)($p['locationId'] ?? 0),
			$this->toStorageQty($p['qty'] ?? 0),
			isset($p['reason']) ? (string)$p['reason'] : null,
			$this->lotCodeParam($p),
		)));
	}

	#[NoAdminRequired]
	public function issue(): JSONResponse
	{
		$p = $this->request->getParams();
		return new JSONResponse($this->toDisplay($this->movements->issue(
			$this->access->currentUserId(),
			(int)($p['itemId'] ?? 0),
			(int)($p['locationId'] ?? 0),
			$this->toStorageQty($p['qty'] ?? 0),
			isset($p['reason']) ? (string)$p['reason'] : null,
			$this->lotCodeParam($p),
			$this->locationCodeParam($p),
		)));
	}

	#[NoAdminRequired]
	public function transfer(): JSONResponse
	{
		$p = $this->request->getParams();
		$from = (int)($p['fromLocationId'] ?? $p['locationId'] ?? 0);
		return new JSONResponse($this->toDisplay($this->movements->transfer(
			$this->access->currentUserId(),
			(int)($p['itemId'] ?? 0),
			$from,
			(int)($p['toLocationId'] ?? 0),
			$this->toStorageQty($p['qty'] ?? 0),
			isset($p['reason']) ? (string)$p['reason'] : null,
			$this->lotCodeParam($p),
			$this->locationCodeParam($p),
			$this->toLocationCodeParam($p),
		)));
	}

	#[NoAdminRequired]
	public function adjust(): JSONResponse
	{
		$p = $this->request->getParams();
		return new JSONResponse($this->toDisplay($this->movements->adjust(
			$this->access->currentUserId(),
			(int)($p['itemId'] ?? 0),
			(int)($p['locationId'] ?? 0),
			(string)($p['mode'] ?? ''),
			isset($p['qty']) ? $this->toStorageQty($p['qty']) : null,
			isset($p['qtyDelta']) ? $this->toStorageQty($p['qtyDelta']) : null,
			isset($p['reason']) ? (string)$p['reason'] : null,
			$this->lotCodeParam($p),
			true,
			isset($p['reasonCode']) ? (string)$p['reasonCode'] : (isset($p['reason_code']) ? (string)$p['reason_code'] : null),
		)));
	}

	#[NoAdminRequired]
	public function scan(): JSONResponse
	{
		$p = $this->request->getParams();
		$uid = $this->access->currentUserId();
		return new JSONResponse($this->toDisplay($this->movements->scan(
			$uid,
			(string)($p['code'] ?? ''),
			(string)($p['kind'] ?? ''),
			(int)($p['locationId'] ?? 0),
			isset($p['toLocationId']) ? (int)$p['toLocationId'] : null,
			isset($p['qty']) ? $this->toStorageQty($p['qty']) : null,
			isset($p['qtyDelta']) ? $this->toStorageQty($p['qtyDelta']) : null,
			isset($p['reason']) ? (string)$p['reason'] : null,
			$this->access->canWrite($uid),
			$this->lotCodeParam($p),
			isset($p['reasonCode']) ? (string)$p['reasonCode'] : (isset($p['reason_code']) ? (string)$p['reason_code'] : null),
			$this->locationCodeParam($p),
			$this->toLocationCodeParam($p),
		)));
	}

	private function toStorageQty(mixed $raw): int
	{
		return QtyScale::toStorage($this->settings, $raw);
	}

	/** @param array<string, mixed> $p */
	private function lotCodeParam(array $p): ?string
	{
		if (!isset($p['lotCode']) || $p['lotCode'] === '') {
			return null;
		}
		return (string)$p['lotCode'];
	}

	/** @param array<string, mixed> $p */
	private function locationCodeParam(array $p): ?string
	{
		if (isset($p['locationCode']) && $p['locationCode'] !== '') {
			return (string)$p['locationCode'];
		}
		if (isset($p['location_code']) && $p['location_code'] !== '') {
			return (string)$p['location_code'];
		}
		return null;
	}

	/** @param array<string, mixed> $p */
	private function toLocationCodeParam(array $p): ?string
	{
		if (isset($p['toLocationCode']) && $p['toLocationCode'] !== '') {
			return (string)$p['toLocationCode'];
		}
		if (isset($p['to_location_code']) && $p['to_location_code'] !== '') {
			return (string)$p['to_location_code'];
		}
		return null;
	}

	/**
	 * @param array{movements: list<array<string, mixed>>, balances: list<array<string, mixed>>} $result
	 * @return array{movements: list<array<string, mixed>>, balances: list<array<string, mixed>>}
	 */
	private function toDisplay(array $result): array
	{
		$result['movements'] = array_map(fn (array $m) => QtyScale::formatMovement($m, $this->settings), $result['movements']);
		$result['balances'] = array_map(fn (array $b) => QtyScale::formatBalance($b, $this->settings), $result['balances']);
		return $result;
	}
}
