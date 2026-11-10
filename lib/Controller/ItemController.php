<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Controller;

use OCA\InventoryCheck\AppInfo\Application;
use OCA\InventoryCheck\Exception\NotFoundException;
use OCA\InventoryCheck\Exception\ValidationException;
use OCA\InventoryCheck\Service\AccessControlService;
use OCA\InventoryCheck\Service\ItemService;
use OCA\InventoryCheck\Service\Pagination;
use OCA\InventoryCheck\Service\QtyScale;
use OCA\InventoryCheck\Service\WorkspaceContext;
use OCA\InventoryCheck\Util\LabelSheet;
use OCA\InventoryCheck\Util\LabelSvg;
use OCA\InventoryCheck\Util\CodeElements;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\TemplateResponse;
use OCA\InventoryCheck\Service\WorkspaceSettings;
use OCP\IRequest;
use OCP\IURLGenerator;

/**
 * Wave C1: reorderLevel is converted display → storage before it reaches
 * {@see ItemService} and storage → display again on every response — the
 * service itself only ever works in plain integer storage units.
 */
class ItemController extends Controller
{
	public function __construct(
		IRequest $request,
		private readonly ItemService $items,
		private readonly AccessControlService $access,
		private readonly IURLGenerator $urlGenerator,
		private readonly WorkspaceSettings $settings,
		private readonly WorkspaceContext $workspace,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	#[NoAdminRequired]
	public function index(): JSONResponse
	{
		$page = Pagination::parse($this->request->getParam('limit'), $this->request->getParam('offset'));
		$q = trim((string)$this->request->getParam('q', ''));
		$activeRaw = $this->request->getParam('active');
		$active = $activeRaw === null || $activeRaw === '' ? null : filter_var($activeRaw, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
		$lowStock = filter_var($this->request->getParam('lowStock', '0'), FILTER_VALIDATE_BOOLEAN);
		$result = $this->items->list($this->access->currentUserId(), $q, $active, $lowStock, $page['limit'], $page['offset']);
		$result['data'] = array_map(fn (array $i) => QtyScale::formatItem($i, $this->settings), $result['data']);
		return new JSONResponse($result);
	}

	#[NoAdminRequired]
	public function show(int $id): JSONResponse
	{
		return new JSONResponse(QtyScale::formatItem($this->items->get($this->access->currentUserId(), $id), $this->settings));
	}

	#[NoAdminRequired]
	public function byCode(string $code): JSONResponse
	{
		return new JSONResponse($this->formatItemWithBalances(
			$this->items->byCode($this->access->currentUserId(), rawurldecode($code)),
		));
	}

	/** SVG download for archival / external printers. */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function label(int $id): DataDownloadResponse
	{
		$item = $this->items->get($this->access->currentUserId(), $id);
		$svg = LabelSvg::forItem(
			(string)$item['scanCode'],
			(string)$item['sku'],
			(string)$item['name'],
			$this->settings->getList('label_elements'),
		);
		return new DataDownloadResponse($svg, 'label-' . $id . '.svg', 'image/svg+xml');
	}

	/** Reusable standalone QR element, also used by the label composer. */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function qr(int $id): DataDownloadResponse
	{
		$item = $this->items->get($this->access->currentUserId(), $id);
		return new DataDownloadResponse(CodeElements::qr((string)$item['scanCode']), 'qr-' . $id . '.svg', 'image/svg+xml');
	}

	/** Reusable standalone Code 128 element for API consumers and labels. */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function barcode(int $id): DataDownloadResponse
	{
		$item = $this->items->get($this->access->currentUserId(), $id);
		return new DataDownloadResponse(CodeElements::barcode((string)$item['scanCode']), 'barcode-' . $id . '.svg', 'image/svg+xml');
	}

	/** SPEC §7.3 canonical path — same SVG payload as label.svg. */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function labelAlias(int $id): DataDownloadResponse
	{
		return $this->label($id);
	}

	/**
	 * Printable label view (UJ-1 / A12) — browser print applies @media print.
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function labelPrint(int $id): TemplateResponse
	{
		$item = $this->items->get($this->access->currentUserId(), $id);
		$svg = LabelSvg::forItem(
			(string)$item['scanCode'],
			(string)$item['sku'],
			(string)$item['name'],
			$this->settings->getList('label_elements'),
		);
		$response = new TemplateResponse(
			Application::APP_ID,
			'label-print',
			[
				'item' => $item,
				'svg' => $svg,
				'backUrl' => $this->urlGenerator->linkToRoute('inventorycheck.page.item', ['id' => $id, 'workspaceId' => $this->workspace->id()]),
				'downloadUrl' => $this->urlGenerator->linkToRoute('inventorycheck.item.label', ['id' => $id]),
			],
		);
		$response->renderAs(TemplateResponse::RENDER_AS_BLANK);
		return $response;
	}

	/**
	 * Bulk label sheet (Wave A5) — A4 grid of ≥12 labels via {@see LabelSheet}.
	 * Opened as a browser navigation (print window), so NoCSRFRequired like
	 * the single-item label print view.
	 */
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

		$items = [];
		$actorUid = $this->access->currentUserId();
		foreach ($ids as $id) {
			try {
				$items[] = $this->items->get($actorUid, $id);
			} catch (\Throwable) {
				continue;
			}
		}
		if ($items === []) {
			throw new NotFoundException('unknown_item');
		}

		$html = LabelSheet::html(array_map(static fn (array $i): array => [
			'scanCode' => (string)$i['scanCode'],
			'sku' => (string)$i['sku'],
			'name' => (string)$i['name'],
			], $items), $this->settings->getList('label_elements'));

		$response = new TemplateResponse(
			Application::APP_ID,
			'label-sheet-print',
			[
				'labelsHtml' => $html,
				'count' => count($items),
				'backUrl' => $this->urlGenerator->linkToRoute('inventorycheck.page.items', ['workspaceId' => $this->workspace->id()]),
			],
		);
		$response->renderAs(TemplateResponse::RENDER_AS_BLANK);
		return $response;
	}

	#[NoAdminRequired]
	public function create(): JSONResponse
	{
		$input = $this->toStorageInput($this->request->getParams());
		return new JSONResponse(QtyScale::formatItem($this->items->create($this->access->currentUserId(), $input), $this->settings));
	}

	#[NoAdminRequired]
	public function update(int $id): JSONResponse
	{
		$input = $this->toStorageInput($this->request->getParams());
		return new JSONResponse(QtyScale::formatItem($this->items->update($this->access->currentUserId(), $id, $input), $this->settings));
	}

	#[NoAdminRequired]
	public function destroy(int $id): JSONResponse
	{
		$this->items->delete($this->access->currentUserId(), $id);
		return new JSONResponse(['ok' => true]);
	}

	/**
	 * @param array<string, mixed> $input
	 * @return array<string, mixed>
	 */
	private function toStorageInput(array $input): array
	{
		foreach (['reorderLevel', 'reorder_level', 'targetStock', 'target_stock'] as $key) {
			if (array_key_exists($key, $input) && $input[$key] !== null && $input[$key] !== '') {
				$input[$key] = QtyScale::toStorage($this->settings, $input[$key]);
			}
		}
		return $input;
	}

	/** @param array<string, mixed> $item */
	private function formatItemWithBalances(array $item): array
	{
		$item = QtyScale::formatItem($item, $this->settings);
		if (isset($item['balances']) && is_array($item['balances'])) {
			$item['balances'] = array_map(fn (array $b) => QtyScale::formatBalance($b, $this->settings), $item['balances']);
		}
		return $item;
	}
}
