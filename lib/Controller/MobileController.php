<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Controller;

use OCA\InventoryCheck\AppInfo\Application;
use OCA\InventoryCheck\Db\ScanDevice;
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
use OCA\InventoryCheck\Service\Lookup\LookupException;
use OCA\InventoryCheck\Service\MobileAccessService;
use OCA\InventoryCheck\Service\MovementService;
use OCA\InventoryCheck\Service\Pagination;
use OCA\InventoryCheck\Service\ProductLookupService;
use OCA\InventoryCheck\Service\QtyScale;
use OCA\InventoryCheck\Service\ScanIdempotencyService;
use OCA\InventoryCheck\Service\WorkspaceContext;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\DataDisplayResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCA\InventoryCheck\Service\WorkspaceSettings;
use OCP\IL10N;
use OCP\IRequest;
use OCP\ISession;
use OCP\IUserSession;

/**
 * Mobile / scan API (SPEC §9).
 *
 * Device callers authenticate with X-IV-Device-Token only (no NC session),
 * so every route is PublicPage + NoCSRFRequired. Auth is enforced inside
 * resolveCaller / MobileAccessService — never by SecurityMiddleware session.
 *
 * Session-backed mutating routes still call {@see assertSafeMutationChannel}
 * so a bare cookie cannot drive scan/favourites/inventur without CSRF or a
 * device token (parity with CustomerCheck companion hardening). Forged
 * Authorization headers are ignored — only X-IV-Device-Token or CSRF count.
 */
class MobileController extends Controller
{
	public function __construct(
		IRequest $request,
		private readonly MobileAccessService $gate,
		private readonly ScannerService $scanners,
		private readonly DevicePairingService $pairing,
		private readonly ItemService $items,
		private readonly LocationService $locations,
		private readonly BalanceService $balances,
		private readonly MovementService $movements,
		private readonly AccessControlService $access,
		private readonly LocationFavouriteService $favourites,
		private readonly CycleCountService $cycles,
		private readonly ItemPhotoService $photos,
		private readonly ScanIdempotencyService $scanIdempotency,
		private readonly ProductLookupService $productLookup,
		private readonly IUserSession $userSession,
		private readonly ISession $session,
		private readonly WorkspaceSettings $settings,
		private readonly IL10N $l10n,
		private readonly WorkspaceContext $workspace,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	/**
	 * Session users keep their uid; device tokens always use device:{id}
	 * so Wave C3 / device location grants apply consistently on every route.
	 */
	private function actorUid(?string $uid, ?ScanDevice $device): string
	{
		if ($uid !== null && $uid !== '') {
			return $uid;
		}
		if ($device !== null) {
			return 'device:' . (int)$device->getId();
		}
		return '';
	}

	#[PublicPage]
	#[NoCSRFRequired]
	#[NoAdminRequired]
	public function bootstrap(): JSONResponse
	{
		// SPEC §9.1: bootstrap skips rungs 3–6 only — auth + canUseApp still apply.
		[$uid, $device] = $this->resolveCaller(true);
		$result = $this->gate->bootstrap($uid, $device);
		$result['workspaceId'] = $this->workspace->id();
		$result['capabilities']['workspaces'] = true;
		return new JSONResponse($result);
	}

	#[PublicPage]
	#[NoCSRFRequired]
	#[NoAdminRequired]
	public function byCode(string $code): JSONResponse
	{
		[$uid, $device] = $this->resolveCaller(true);
		$this->gate->requireCaller($uid, $device);
		$item = QtyScale::formatItem(
			$this->items->byCode($this->actorUid($uid, $device), rawurldecode($code)),
			$this->settings,
		);
		if (isset($item['balances']) && is_array($item['balances'])) {
			$item['balances'] = array_map(
				fn (array $b) => QtyScale::formatBalance($b, $this->settings),
				$item['balances'],
			);
		}
		return new JSONResponse($item);
	}

	/**
	 * Companion read-only item photo (devices + session seats). No upload on mobile.
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[NoAdminRequired]
	public function itemPhoto(int $id): DataDisplayResponse
	{
		[$uid, $device] = $this->resolveCaller(true);
		$this->gate->requireCaller($uid, $device);
		// Release the PHP session lock before streaming — auth already ran.
		$this->session->close();
		$photo = $this->photos->read($id);
		$response = new DataDisplayResponse($photo['content'], 200, ['Content-Type' => $photo['mime']]);
		$response->cacheFor(3600, false, true);
		return $response;
	}

	/** Wave D2 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[NoAdminRequired]
	public function locationByCode(string $code): JSONResponse
	{
		[$uid, $device] = $this->resolveCaller(true);
		$this->gate->requireCaller($uid, $device);
		return new JSONResponse(
			$this->locations->byCode($this->actorUid($uid, $device), rawurldecode($code)),
		);
	}

	#[PublicPage]
	#[NoCSRFRequired]
	#[NoAdminRequired]
	public function locations(): JSONResponse
	{
		[$uid, $device] = $this->resolveCaller(true);
		$this->gate->requireCaller($uid, $device);
		$page = Pagination::parse($this->request->getParam('limit'), $this->request->getParam('offset'));
		return new JSONResponse($this->locations->list($this->actorUid($uid, $device), true, $page['limit'], $page['offset']));
	}

	#[PublicPage]
	#[NoCSRFRequired]
	#[NoAdminRequired]
	public function balances(): JSONResponse
	{
		[$uid, $device] = $this->resolveCaller(true);
		$this->gate->requireCaller($uid, $device);
		$page = Pagination::parse($this->request->getParam('limit'), $this->request->getParam('offset'));
		$itemId = $this->request->getParam('itemId');
		$locationId = $this->request->getParam('locationId');
		$result = $this->balances->list(
			$this->actorUid($uid, $device),
			$itemId !== null && $itemId !== '' ? (int)$itemId : null,
			$locationId !== null && $locationId !== '' ? (int)$locationId : null,
			filter_var($this->request->getParam('nonZero', '0'), FILTER_VALIDATE_BOOLEAN),
			$page['limit'],
			$page['offset'],
		);
		$result['data'] = array_map(
			fn (array $b) => QtyScale::formatBalance($b, $this->settings),
			$result['data'],
		);
		return new JSONResponse($result);
	}

	#[PublicPage]
	#[NoCSRFRequired]
	#[NoAdminRequired]
	public function movements(): JSONResponse
	{
		[$uid, $device] = $this->resolveCaller(true);
		$this->gate->requireCaller($uid, $device);
		$page = Pagination::parse($this->request->getParam('limit'), $this->request->getParam('offset'));
		$result = $this->movements->list(
			$this->actorUid($uid, $device),
			null, null, null, null, null, null, $page['limit'], $page['offset'],
		);
		$result['data'] = array_map(
			fn (array $m) => QtyScale::formatMovement($m, $this->settings),
			$result['data'],
		);
		return new JSONResponse($result);
	}

	#[PublicPage]
	#[NoCSRFRequired]
	#[NoAdminRequired]
	public function scan(): JSONResponse
	{
		$this->assertSafeMutationChannel();
		[$uid, $device] = $this->resolveCaller(true);
		$this->gate->requireCaller($uid, $device);
		$p = $this->request->getParams();
		$asOffice = $device === null && $uid !== null && $this->access->canWrite($uid);
		$actor = $this->actorUid($uid, $device);
		$clientRequestId = $this->scanIdempotency->normalizeClientRequestId(
			$p['clientRequestId'] ?? ($p['client_request_id'] ?? null),
		);
		$payloadHash = $this->scanIdempotency->fingerprintFromParams($p);
		if ($clientRequestId !== null) {
			$prior = $this->scanIdempotency->lookup($actor, $clientRequestId);
			if ($prior['status'] === ScanIdempotencyService::STATUS_DONE && is_array($prior['response'])) {
				if (!$this->scanIdempotency->payloadMatches($prior['payloadHash'], $payloadHash)) {
					throw new MobileRequestException('idempotency_payload_mismatch');
				}
				return new JSONResponse($prior['response']);
			}
			if ($prior['status'] === ScanIdempotencyService::STATUS_PENDING) {
				if (!$this->scanIdempotency->releaseStalePending($actor, $clientRequestId)) {
					throw new MobileRequestException('idempotency_in_flight');
				}
			}
			if (!$this->scanIdempotency->tryClaim($actor, $clientRequestId, $payloadHash)) {
				$again = $this->scanIdempotency->lookup($actor, $clientRequestId);
				if ($again['status'] === ScanIdempotencyService::STATUS_DONE && is_array($again['response'])) {
					if (!$this->scanIdempotency->payloadMatches($again['payloadHash'], $payloadHash)) {
						throw new MobileRequestException('idempotency_payload_mismatch');
					}
					return new JSONResponse($again['response']);
				}
				if ($again['status'] === ScanIdempotencyService::STATUS_PENDING
					&& $this->scanIdempotency->releaseStalePending($actor, $clientRequestId)
					&& $this->scanIdempotency->tryClaim($actor, $clientRequestId, $payloadHash)) {
					// Reclaimed stale twin — proceed with scan.
				} else {
					throw new MobileRequestException('idempotency_in_flight');
				}
			}
		}
		$lotCode = null;
		if (isset($p['lotCode']) && $p['lotCode'] !== '') {
			$lotCode = (string)$p['lotCode'];
		}
		try {
			$result = $this->movements->scan(
				$actor,
				(string)($p['code'] ?? ''),
				(string)($p['kind'] ?? ''),
				(int)($p['locationId'] ?? 0),
				isset($p['toLocationId']) ? (int)$p['toLocationId'] : null,
				isset($p['qty']) ? QtyScale::toStorage($this->settings, $p['qty']) : null,
				isset($p['qtyDelta']) ? QtyScale::toStorage($this->settings, $p['qtyDelta']) : null,
				isset($p['reason']) ? (string)$p['reason'] : null,
				$asOffice,
				$lotCode,
				isset($p['reasonCode']) ? (string)$p['reasonCode'] : (isset($p['reason_code']) ? (string)$p['reason_code'] : null),
				isset($p['locationCode']) ? (string)$p['locationCode'] : (isset($p['location_code']) ? (string)$p['location_code'] : null),
				isset($p['toLocationCode']) ? (string)$p['toLocationCode'] : (isset($p['to_location_code']) ? (string)$p['to_location_code'] : null),
			);
		} catch (\Throwable $e) {
			if ($clientRequestId !== null) {
				$this->scanIdempotency->release($actor, $clientRequestId);
			}
			throw $e;
		}

		// Ledger is committed. Never leave the claim pending past this point —
		// a format/complete failure must still record DONE so retries can ACK
		// instead of hanging on idempotency_in_flight forever.
		$formatted = $result;
		try {
			$formatted['movements'] = array_map(
				fn (array $m) => QtyScale::formatMovement($m, $this->settings),
				$result['movements'],
			);
			$formatted['balances'] = array_map(
				fn (array $b) => QtyScale::formatBalance($b, $this->settings),
				$result['balances'],
			);
		} catch (\Throwable $e) {
			if ($clientRequestId !== null) {
				$this->scanIdempotency->complete($actor, $clientRequestId, $result);
			}
			throw $e;
		}
		if ($clientRequestId !== null) {
			$this->scanIdempotency->complete($actor, $clientRequestId, $formatted);
		}
		return new JSONResponse($formatted);
	}

	#[PublicPage]
	#[NoCSRFRequired]
	#[NoAdminRequired]
	public function pairDevice(): JSONResponse
	{
		$code = (string)$this->request->getParam('code', '');
		return new JSONResponse($this->pairing->pair($code));
	}

	/** Companion P1 — favourites (session users only; devices have no favourites). */
	#[PublicPage]
	#[NoCSRFRequired]
	#[NoAdminRequired]
	public function favourites(): JSONResponse
	{
		[$uid] = $this->requireSessionUser();
		$this->gate->requireCaller($uid, null);
		return new JSONResponse(['data' => $this->favourites->list($uid)]);
	}

	#[PublicPage]
	#[NoCSRFRequired]
	#[NoAdminRequired]
	public function addFavourite(): JSONResponse
	{
		$this->assertSafeMutationChannel();
		[$uid] = $this->requireSessionUser();
		$this->gate->requireCaller($uid, null);
		$locationId = (int)$this->request->getParam('locationId', 0);
		return new JSONResponse(['data' => $this->favourites->add($uid, $locationId)]);
	}

	#[PublicPage]
	#[NoCSRFRequired]
	#[NoAdminRequired]
	public function removeFavourite(int $locationId): JSONResponse
	{
		$this->assertSafeMutationChannel();
		[$uid] = $this->requireSessionUser();
		$this->gate->requireCaller($uid, null);
		return new JSONResponse(['data' => $this->favourites->remove($uid, $locationId)]);
	}

	/** Companion P2 — stocktake (session users; devices cannot inventur). */
	#[PublicPage]
	#[NoCSRFRequired]
	#[NoAdminRequired]
	public function cycleCounts(): JSONResponse
	{
		[$uid] = $this->requireSessionUser();
		$this->gate->requireCaller($uid, null);
		$page = Pagination::parse($this->request->getParam('limit'), $this->request->getParam('offset'));
		$status = (string)$this->request->getParam('status', '');
		return new JSONResponse($this->cycles->list(
			$uid,
			$status !== '' ? $status : null,
			$page['limit'],
			$page['offset'],
		));
	}

	#[PublicPage]
	#[NoCSRFRequired]
	#[NoAdminRequired]
	public function cycleCountShow(int $id): JSONResponse
	{
		[$uid] = $this->requireSessionUser();
		$this->gate->requireCaller($uid, null);
		$blind = filter_var($this->request->getParam('blind', '0'), FILTER_VALIDATE_BOOLEAN);
		$page = Pagination::parse($this->request->getParam('limit'), $this->request->getParam('offset'));
		$campaign = $this->formatCycleCampaign(
			$this->cycles->get($uid, $id, $page['limit'], $page['offset']),
			$blind,
		);
		return new JSONResponse($campaign);
	}

	#[PublicPage]
	#[NoCSRFRequired]
	#[NoAdminRequired]
	public function cycleCountSetCount(int $lineId): JSONResponse
	{
		$this->assertSafeMutationChannel();
		[$uid] = $this->requireSessionUser();
		$this->gate->requireCaller($uid, null);
		$p = $this->request->getParams();
		$blind = filter_var($p['blind'] ?? $this->request->getParam('blind', '0'), FILTER_VALIDATE_BOOLEAN);
		$line = $this->cycles->setCount(
			$uid,
			$lineId,
			QtyScale::toStorage($this->settings, $p['qtyCounted'] ?? 0),
		);
		$formatted = QtyScale::formatCycleLine($line, $this->settings);
		if ($blind) {
			// AF-IV13: mutation responses must not leak book qty either.
			unset($formatted['systemQty'], $formatted['currentQty'], $formatted['conflict']);
			$formatted['blind'] = true;
		}
		return new JSONResponse($formatted);
	}

	/**
	 * Companion product lookup: identify an unknown barcode for a field user.
	 * Read-only — no item is created. Same gate as every companion route
	 * (seat or device token) plus the admin feature toggle.
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[NoAdminRequired]
	public function productLookup(): JSONResponse
	{
		$this->assertSafeMutationChannel();
		[$uid, $device] = $this->resolveCaller(true);
		$this->gate->requireCaller($uid, $device);
		$barcode = $this->request->getParam('barcode', '');
		try {
			if (!is_string($barcode)) {
				throw new LookupException('invalid_barcode', 'invalid', 422);
			}
			return new JSONResponse(
				$this->productLookup->lookup($barcode, $this->actorUid($uid, $device)),
			);
		} catch (LookupException $e) {
			$message = match ($e->errorCode) {
				'invalid_barcode' => $this->l10n->t('Enter a valid EAN or UPC barcode (8, 12, 13 or 14 digits).'),
				'rate_limited' => $this->l10n->t('Please wait a few seconds before searching again.'),
				'feature_disabled' => $this->l10n->t('Online product lookup is disabled.'),
				default => $this->l10n->t('Online product lookup is unavailable right now. Enter the item manually or try the web search link.'),
			};
			return new JSONResponse(
				['error' => ['code' => $e->errorCode, 'message' => $message, 'details' => []]],
				$e->status,
			);
		}
	}

	/**
	 * Session-backed mutations need a CSRF requesttoken. Device-token callers
	 * skip CSRF (PublicPage companions); a forged Authorization header must
	 * never short-circuit this check — resolveCaller ignores Authorization.
	 */
	private function assertSafeMutationChannel(): void
	{
		$token = trim((string)$this->request->getHeader('X-IV-Device-Token'));
		if ($token !== '') {
			return;
		}
		if ($this->request->passesCSRFCheck()) {
			return;
		}
		throw new MobileRequestException('auth_required');
	}

	/**
	 * @return array{0: string, 1: null}
	 */
	private function requireSessionUser(): array
	{
		[$uid, $device] = $this->resolveCaller(true);
		if ($device !== null || $uid === null) {
			// Device tokens are field-only; inventur/favourites need a named seat.
			throw new MobileRequestException('auth_required');
		}
		return [$uid, null];
	}

	/**
	 * @param array<string, mixed> $campaign
	 * @return array<string, mixed>
	 */
	private function formatCycleCampaign(array $campaign, bool $blind): array
	{
		if (isset($campaign['lines']) && is_array($campaign['lines'])) {
			$campaign['lines'] = array_map(
				function (array $line) use ($blind): array {
					$formatted = QtyScale::formatCycleLine($line, $this->settings);
					if ($blind) {
						// AF-IV13: never leak system/current qty OR conflict side-channels.
						unset(
							$formatted['systemQty'],
							$formatted['currentQty'],
							$formatted['conflict'],
						);
						$formatted['blind'] = true;
					}
					return $formatted;
				},
				$campaign['lines'],
			);
		}
		if ($blind) {
			unset($campaign['hasConflicts'], $campaign['conflictCount']);
		}
		$campaign['blind'] = $blind;
		return $campaign;
	}

	/**
	 * @return array{0: ?string, 1: ?\OCA\InventoryCheck\Db\ScanDevice}
	 */
	private function resolveCaller(bool $requireAuth): array
	{
		$token = (string)$this->request->getHeader('X-IV-Device-Token');
		if ($token !== '') {
			$hash = $this->scanners->hashSecret($token);
			$device = $this->scanners->findDeviceByTokenHash($hash);
			// SPEC §9.1: unknown / garbage token → 401 auth (rung 1).
			// Deactivated slot that still holds this hash → 402 device_required (rung 5 / §9.3.4).
			if ($device === null) {
				throw new MobileRequestException('auth_required');
			}
			if (!$device->getActive()) {
				throw new MobileRequestException('device_required');
			}
			$this->workspace->forDevice($device->getWorkspaceId());
			$this->pairing->touchLastSeen($device);
			return [null, $device];
		}
		$user = $this->userSession->getUser();
		if ($user === null) {
			if ($requireAuth) {
				throw new MobileRequestException('auth_required');
			}
			return [null, null];
		}
		$this->workspace->current();
		if (!in_array($this->request->getMethod(), ['GET', 'HEAD', 'OPTIONS'], true)) {
			$this->workspace->requireWrite();
		}
		return [$user->getUID(), null];
	}
}
