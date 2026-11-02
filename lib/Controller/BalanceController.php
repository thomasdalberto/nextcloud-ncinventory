<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Controller;

use OCA\InventoryCheck\AppInfo\Application;
use OCA\InventoryCheck\Service\AccessControlService;
use OCA\InventoryCheck\Service\BalanceService;
use OCA\InventoryCheck\Service\Pagination;
use OCA\InventoryCheck\Service\QtyScale;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCA\InventoryCheck\Service\WorkspaceSettings;
use OCP\IRequest;

class BalanceController extends Controller
{
	public function __construct(
		IRequest $request,
		private readonly BalanceService $balances,
		private readonly AccessControlService $access,
		private readonly WorkspaceSettings $settings,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	#[NoAdminRequired]
	public function index(): JSONResponse
	{
		$page = Pagination::parse($this->request->getParam('limit'), $this->request->getParam('offset'));
		$itemId = $this->request->getParam('itemId');
		$locationId = $this->request->getParam('locationId');
		$nonZero = filter_var($this->request->getParam('nonZero', '0'), FILTER_VALIDATE_BOOLEAN);
		$negativeOnly = filter_var($this->request->getParam('negative', '0'), FILTER_VALIDATE_BOOLEAN);
		$result = $this->balances->list(
			$this->access->currentUserId(),
			$itemId !== null && $itemId !== '' ? (int)$itemId : null,
			$locationId !== null && $locationId !== '' ? (int)$locationId : null,
			$nonZero,
			$page['limit'],
			$page['offset'],
			$negativeOnly,
		);
		$result['data'] = array_map(fn (array $b) => QtyScale::formatBalance($b, $this->settings), $result['data']);
		return new JSONResponse($result);
	}
}
