<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Controller;

use OCA\InventoryCheck\AppInfo\Application;
use OCA\InventoryCheck\Service\AccessControlService;
use OCA\InventoryCheck\Service\CycleCountService;
use OCA\InventoryCheck\Service\Pagination;
use OCA\InventoryCheck\Service\QtyScale;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCA\InventoryCheck\Service\WorkspaceSettings;
use OCP\IRequest;

/**
 * Cycle-count / Inventur campaigns (Wave B1). Reads are open to any
 * logged-in user (dashboard-style visibility); mutations are office-only,
 * enforced inside {@see CycleCountService}.
 *
 * Wave C1: qtyCounted is display → storage at this boundary; line payloads
 * are storage → display on the way out.
 */
class CycleCountController extends Controller
{
	public function __construct(
		IRequest $request,
		private readonly CycleCountService $cycles,
		private readonly AccessControlService $access,
		private readonly WorkspaceSettings $settings,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	#[NoAdminRequired]
	public function index(): JSONResponse
	{
		$page = Pagination::parse($this->request->getParam('limit'), $this->request->getParam('offset'));
		$status = (string)$this->request->getParam('status', '');
		return new JSONResponse($this->cycles->list(
			$this->access->currentUserId(),
			$status !== '' ? $status : null,
			$page['limit'],
			$page['offset'],
		));
	}

	#[NoAdminRequired]
	public function show(int $id): JSONResponse
	{
		$page = Pagination::parse($this->request->getParam('limit'), $this->request->getParam('offset'));
		return new JSONResponse($this->formatCampaign($this->cycles->get(
			$this->access->currentUserId(),
			$id,
			$page['limit'],
			$page['offset'],
		)));
	}

	#[NoAdminRequired]
	public function create(): JSONResponse
	{
		$p = $this->request->getParams();
		return new JSONResponse($this->formatCampaign($this->cycles->create(
			$this->access->currentUserId(),
			(int)($p['locationId'] ?? 0),
			(string)($p['name'] ?? ''),
		)));
	}

	#[NoAdminRequired]
	public function start(int $id): JSONResponse
	{
		return new JSONResponse($this->formatCampaign($this->cycles->startCounting($this->access->currentUserId(), $id)));
	}

	#[NoAdminRequired]
	public function setCount(int $lineId): JSONResponse
	{
		$p = $this->request->getParams();
		$line = $this->cycles->setCount(
			$this->access->currentUserId(),
			$lineId,
			QtyScale::toStorage($this->settings, $p['qtyCounted'] ?? 0),
		);
		return new JSONResponse(QtyScale::formatCycleLine($line, $this->settings));
	}

	#[NoAdminRequired]
	public function close(int $id): JSONResponse
	{
		$abandon = filter_var($this->request->getParam('abandonUncounted', '0'), FILTER_VALIDATE_BOOLEAN);
		$acknowledge = filter_var($this->request->getParam('acknowledgeConflicts', '0'), FILTER_VALIDATE_BOOLEAN);
		return new JSONResponse($this->formatCampaign($this->cycles->close(
			$this->access->currentUserId(),
			$id,
			$abandon,
			$acknowledge,
		)));
	}

	/**
	 * @param array<string, mixed> $campaign
	 * @return array<string, mixed>
	 */
	private function formatCampaign(array $campaign): array
	{
		if (isset($campaign['lines']) && is_array($campaign['lines'])) {
			$campaign['lines'] = array_map(
				fn (array $line) => QtyScale::formatCycleLine($line, $this->settings),
				$campaign['lines'],
			);
		}
		return $campaign;
	}
}
