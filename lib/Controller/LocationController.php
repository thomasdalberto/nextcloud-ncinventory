<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Controller;

use OCA\InventoryCheck\AppInfo\Application;
use OCA\InventoryCheck\Exception\NotFoundException;
use OCA\InventoryCheck\Exception\ValidationException;
use OCA\InventoryCheck\Service\AccessControlService;
use OCA\InventoryCheck\Service\LocationService;
use OCA\InventoryCheck\Service\Pagination;
use OCA\InventoryCheck\Service\WorkspaceContext;
use OCA\InventoryCheck\Util\LabelSheet;
use OCA\InventoryCheck\Util\LabelSvg;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IRequest;
use OCP\IURLGenerator;

class LocationController extends Controller
{
	public function __construct(
		IRequest $request,
		private readonly LocationService $locations,
		private readonly AccessControlService $access,
		private readonly IURLGenerator $urlGenerator,
		private readonly WorkspaceContext $workspace,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	#[NoAdminRequired]
	public function index(): JSONResponse
	{
		$page = Pagination::parse($this->request->getParam('limit'), $this->request->getParam('offset'));
		$activeRaw = $this->request->getParam('active');
		$active = $activeRaw === null || $activeRaw === '' ? null : filter_var($activeRaw, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
		$q = trim((string)$this->request->getParam('q', ''));
		return new JSONResponse($this->locations->list($this->access->currentUserId(), $active, $page['limit'], $page['offset'], $q));
	}

	#[NoAdminRequired]
	public function show(int $id): JSONResponse
	{
		return new JSONResponse($this->locations->get($this->access->currentUserId(), $id));
	}

	/** Wave D2 */
	#[NoAdminRequired]
	public function byCode(string $code): JSONResponse
	{
		return new JSONResponse($this->locations->byCode($this->access->currentUserId(), rawurldecode($code)));
	}

	#[NoAdminRequired]
	public function create(): JSONResponse
	{
		return new JSONResponse($this->locations->create($this->access->currentUserId(), $this->request->getParams()));
	}

	#[NoAdminRequired]
	public function update(int $id): JSONResponse
	{
		return new JSONResponse($this->locations->update($this->access->currentUserId(), $id, $this->request->getParams()));
	}

	#[NoAdminRequired]
	public function destroy(int $id): JSONResponse
	{
		$this->locations->delete($this->access->currentUserId(), $id);
		return new JSONResponse(['ok' => true]);
	}

	/** Wave D1 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function label(int $id): DataDownloadResponse
	{
		$loc = $this->locations->get($this->access->currentUserId(), $id);
		$svg = LabelSvg::forLocation(
			(string)$loc['code'],
			(string)$loc['name'],
			(string)($loc['kind'] ?? 'other'),
		);
		return new DataDownloadResponse($svg, 'location-label-' . $id . '.svg', 'image/svg+xml');
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function labelAlias(int $id): DataDownloadResponse
	{
		return $this->label($id);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function labelPrint(int $id): TemplateResponse
	{
		$loc = $this->locations->get($this->access->currentUserId(), $id);
		$svg = LabelSvg::forLocation(
			(string)$loc['code'],
			(string)$loc['name'],
			(string)($loc['kind'] ?? 'other'),
		);
		$response = new TemplateResponse(
			Application::APP_ID,
			'label-print',
			[
				'item' => [
					'name' => $loc['name'],
					'scanCode' => $loc['code'],
					'sku' => $loc['kind'] ?? 'other',
				],
				'svg' => $svg,
				'backUrl' => $this->urlGenerator->linkToRoute('inventorycheck.page.location', ['id' => $id, 'workspaceId' => $this->workspace->id()]),
				'downloadUrl' => $this->urlGenerator->linkToRoute('inventorycheck.location.label', ['id' => $id]),
			],
		);
		$response->renderAs(TemplateResponse::RENDER_AS_BLANK);
		return $response;
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function bulkLabels(): TemplateResponse
	{
		$idsParam = $this->request->getParam('ids', []);
		if (is_string($idsParam)) {
			$idsParam = explode(',', $idsParam);
		}
		$ids = is_array($idsParam)
			? array_values(array_unique(array_filter(array_map('intval', $idsParam), static fn (int $id): bool => $id > 0)))
			: [];
		if ($ids === []) {
			throw new ValidationException('validation_failed', '', [['field' => 'ids', 'code' => 'validation_failed']]);
		}
		if (count($ids) > LabelSheet::MAX_BULK_LABELS) {
			throw new ValidationException('validation_failed', '', [['field' => 'ids', 'code' => 'too_many']]);
		}

		$uid = $this->access->currentUserId();
		$locs = [];
		foreach ($ids as $id) {
			try {
				$locs[] = $this->locations->get($uid, $id);
			} catch (\Throwable) {
				continue;
			}
		}
		if ($locs === []) {
			throw new NotFoundException('unknown_location');
		}

		$html = LabelSheet::htmlLocations(array_map(static fn (array $l): array => [
			'code' => (string)$l['code'],
			'name' => (string)$l['name'],
			'kind' => (string)($l['kind'] ?? 'other'),
		], $locs));

		$response = new TemplateResponse(
			Application::APP_ID,
			'label-sheet-print',
			[
				'labelsHtml' => $html,
				'count' => count($locs),
				'backUrl' => $this->urlGenerator->linkToRoute('inventorycheck.page.locations', ['workspaceId' => $this->workspace->id()]),
			],
		);
		$response->renderAs(TemplateResponse::RENDER_AS_BLANK);
		return $response;
	}
}
