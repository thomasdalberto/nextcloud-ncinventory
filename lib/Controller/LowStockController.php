<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Controller;

use OCA\InventoryCheck\AppInfo\Application;
use OCA\InventoryCheck\Service\AccessControlService;
use OCA\InventoryCheck\Service\LowStockService;
use OCA\InventoryCheck\Service\Pagination;
use OCA\InventoryCheck\Service\QtyScale;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCA\InventoryCheck\Service\WorkspaceSettings;
use OCP\IRequest;

class LowStockController extends Controller
{
	public function __construct(
		IRequest $request,
		private readonly LowStockService $lowStock,
		private readonly AccessControlService $access,
		private readonly WorkspaceSettings $settings,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	#[NoAdminRequired]
	public function index(): JSONResponse
	{
		$page = Pagination::parse($this->request->getParam('limit'), $this->request->getParam('offset'));
		$result = $this->lowStock->list($this->access->currentUserId(), $page['limit'], $page['offset']);
		$result['data'] = array_map(
			fn (array $row) => QtyScale::formatLowStock($row, $this->settings),
			$result['data'],
		);
		return new JSONResponse($result);
	}

	/** Wave B3: per-location reorder hints (empty unless the app-admin toggle is on). */
	#[NoAdminRequired]
	public function perLocation(): JSONResponse
	{
		$page = Pagination::parse($this->request->getParam('limit'), $this->request->getParam('offset'));
		$result = $this->lowStock->listPerLocation(
			$this->access->currentUserId(),
			$page['limit'],
			$page['offset'],
		);
		$result['data'] = array_map(
			fn (array $row) => QtyScale::formatLowStock($row, $this->settings),
			$result['data'],
		);
		return new JSONResponse($result);
	}
}