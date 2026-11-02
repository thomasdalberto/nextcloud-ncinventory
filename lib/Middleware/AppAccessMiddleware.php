<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Middleware;

use OCA\InventoryCheck\AppInfo\Application;
use OCA\InventoryCheck\Exception\ConflictException;
use OCA\InventoryCheck\Exception\InsufficientStockException;
use OCA\InventoryCheck\Exception\MobileRequestException;
use OCA\InventoryCheck\Exception\NotFoundException;
use OCA\InventoryCheck\Exception\PermissionDeniedException;
use OCA\InventoryCheck\Exception\ValidationException;
use OCA\InventoryCheck\Service\AccessControlService;
use OCA\InventoryCheck\Service\QtyScale;
use OCA\InventoryCheck\Service\WorkspaceContext;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Middleware;
use OCP\Files\EntityTooLargeException as FilesEntityTooLargeException;
use OCP\Files\ForbiddenException as FilesForbiddenException;
use OCP\Files\GenericFileException;
use OCP\Files\LockNotAcquiredException;
use OCP\Files\NotEnoughSpaceException;
use OCP\Files\NotPermittedException as FilesNotPermittedException;
use OCP\Files\StorageNotAvailableException;
use OCA\InventoryCheck\Service\WorkspaceSettings;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUserSession;
use OCP\L10N\IFactory;
use OCP\Util;
use Psr\Log\LoggerInterface;

class AppAccessMiddleware extends Middleware
{
	public function __construct(
		private readonly IUserSession $userSession,
		private readonly AccessControlService $accessControl,
		private readonly IRequest $request,
		private readonly IURLGenerator $urlGenerator,
		private readonly IFactory $l10nFactory,
		private readonly WorkspaceSettings $settings,
		private readonly LoggerInterface $logger,
		private readonly WorkspaceContext $workspace,
	) {
	}

	public function beforeController($controller, $methodName): void
	{
		$class = is_object($controller) ? get_class($controller) : '';
		if (!str_starts_with($class, 'OCA\\InventoryCheck\\Controller\\')) {
			return;
		}

		// Public mobile routes authenticate their session or scanner token themselves.
		if (str_contains($class, 'MobileController') || str_contains($class, 'AdministrationController')) { return; }
		if ($this->userSession->getUser() === null) { return; }
		if (!str_contains($class, 'WorkspaceController') && !str_contains($class, 'PageController')) {
			$this->workspace->current();
			if (!in_array($this->request->getMethod(), ['GET', 'HEAD', 'OPTIONS'], true)) {
				$this->workspace->requireWrite();
			}
		}
	}

	public function afterException($controller, $methodName, \Exception $exception)
	{
		$class = is_object($controller) ? get_class($controller) : '';
		if (!str_starts_with($class, 'OCA\\InventoryCheck\\Controller\\')) {
			throw $exception;
		}

		$l = $this->l10nFactory->get(Application::APP_ID);

		if ($exception instanceof PermissionDeniedException) {
			return $this->envelope('permission_denied', $l->t('You do not have permission for this action.'), Http::STATUS_FORBIDDEN);
		}
		if ($exception instanceof NotFoundException) {
			$code = $exception->getErrorCode();
			$msg = match ($code) {
				'code_not_found' => $l->t('No active item matches this code.'),
				'unknown_item' => $l->t('This item does not exist.'),
				'unknown_location' => $l->t('This location does not exist.'),
				'photo_not_found' => $l->t('No photo for this item.'),
				default => $l->t('The requested entry does not exist.'),
			};
			return $this->envelope($code, $msg, Http::STATUS_NOT_FOUND);
		}
		if ($exception instanceof InsufficientStockException) {
			// Use plain %s placeholders — IL10N::t() does not accept a plural count
			// (that is n()). Passing qty as %n previously rendered the default
			// plural form (count=1) and shoved the qty into the location slot.
			// Wave C1: show display qty (not milli storage ints).
			$qty = (string)QtyScale::toDisplay($this->settings, $exception->getAvailableQty());
			$msg = $exception->getLocationLabel() !== ''
				? $l->t('Only %s left in %s.', [$qty, $exception->getLocationLabel()])
				: $l->t('Not enough stock at this location (available: %s).', [$qty]);
			return $this->envelope('insufficient_stock', $msg, Http::STATUS_CONFLICT);
		}
		if ($exception instanceof ConflictException) {
			$code = $exception->getErrorCode();
			// item_lock_conflict is a transient lock/retry signal (track_mode flip
			// mid-movement). 423 lets companions treat it like ApiError.isLocked.
			$status = $code === 'item_lock_conflict'
				? Http::STATUS_LOCKED
				: Http::STATUS_CONFLICT;
			return $this->envelope(
				$code,
				$this->conflictMessage($code, $l),
				$status,
			);
		}
		if ($exception instanceof ValidationException) {
			return new JSONResponse([
				'error' => [
					'code' => $exception->getErrorCode(),
					'message' => $this->validationMessage($exception, $l),
					'details' => $exception->getDetails(),
				],
			], Http::STATUS_UNPROCESSABLE_ENTITY);
		}

		if ($exception instanceof MobileRequestException) {
			$code = $exception->getErrorCode();
			$status = match ($code) {
				'auth_required' => Http::STATUS_UNAUTHORIZED,
				'rate_limited' => 429,
				'idempotency_in_flight', 'idempotency_payload_mismatch' => Http::STATUS_CONFLICT,
				default => Http::STATUS_FORBIDDEN,
			};
			$message = match ($code) {
				'auth_required' => $l->t('Authentication required.'),
				'device_required' => $l->t('This scanner is no longer paired or has been deactivated.'),
				'rate_limited' => $l->t('Too many pairing attempts. Try again later.'),
				'idempotency_in_flight' => $l->t('This booking is already being processed. Retry shortly.'),
				'idempotency_payload_mismatch' => $l->t('This booking id was already used with different details. Discard the row and book again.'),
				default => $l->t('You do not have permission for this action.'),
			};
			return $this->envelope($code, $message, $status);
		}

		// must_fix F1: OCP\Files storage/permission faults (e.g. an unwritable
		// appdata item_photos folder on POST /api/items/{id}/photo) must keep
		// the JSON wire contract — never a raw framework HTML 500. Only the
		// storage-fault classes below are mapped; InvalidPathException-family
		// and every other throwable is a programmer/unknown error and still
		// rethrows to the core 500 handler.
		if ($exception instanceof FilesNotPermittedException || $exception instanceof FilesForbiddenException) {
			$this->logger->warning('inventorycheck storage denied the operation', [
				'path' => (string)($this->request->getPathInfo() ?? ''),
				'exception' => $exception,
			]);
			return $this->envelope(
				'storage_permission_denied',
				$l->t('Storage permission denied. Contact your administrator.'),
				Http::STATUS_FORBIDDEN,
			);
		}
		if ($exception instanceof FilesEntityTooLargeException) {
			return $this->envelope(
				'storage_entity_too_large',
				$l->t('The file exceeds the storage size limit.'),
				Http::STATUS_REQUEST_ENTITY_TOO_LARGE,
			);
		}
		if ($exception instanceof LockNotAcquiredException) {
			return $this->envelope(
				'storage_locked',
				$l->t('The file is locked. Try again shortly.'),
				Http::STATUS_LOCKED,
			);
		}
		if ($exception instanceof NotEnoughSpaceException) {
			$this->logger->error('inventorycheck storage is full', [
				'path' => (string)($this->request->getPathInfo() ?? ''),
				'exception' => $exception,
			]);
			return $this->envelope(
				'storage_full',
				$l->t('Storage is full. Free up space and try again.'),
				Http::STATUS_INSUFFICIENT_STORAGE,
			);
		}
		if ($exception instanceof StorageNotAvailableException) {
			$this->logger->error('inventorycheck storage backend unavailable', [
				'path' => (string)($this->request->getPathInfo() ?? ''),
				'exception' => $exception,
			]);
			return $this->envelope(
				'storage_unavailable',
				$l->t('Storage is temporarily unavailable. Try again later.'),
				Http::STATUS_SERVICE_UNAVAILABLE,
			);
		}
		if ($exception instanceof GenericFileException) {
			$this->logger->error('inventorycheck storage write/read failed', [
				'path' => (string)($this->request->getPathInfo() ?? ''),
				'exception' => $exception,
			]);
			return $this->envelope(
				'storage_error',
				$l->t('A storage error occurred. Try again later.'),
				Http::STATUS_INTERNAL_SERVER_ERROR,
			);
		}

		throw $exception;
	}

	private function isJsonRoute(): bool
	{
		$path = (string)($this->request->getPathInfo() ?? '');
		return str_contains($path, '/api/')
			|| str_contains($path, '/mobile/')
			|| $this->request->getMethod() !== 'GET';
	}

	private function envelope(string $code, string $message, int $status): JSONResponse
	{
		// SPEC §7.1: details is always present (empty when not applicable).
		return new JSONResponse(['error' => ['code' => $code, 'message' => $message, 'details' => []]], $status);
	}

	private function conflictMessage(string $code, IL10N $l): string
	{
		return match ($code) {
			'insufficient_stock' => $l->t('Not enough stock at this location.'),
			'workspace_member_exists' => $l->t('This user is already a member or has a pending invitation.'),
			'code_exists' => $l->t('This code is already in use.'),
			'item_has_stock' => $l->t('This item still has stock. Move or adjust it to zero before deactivating.'),
			'track_mode_requires_zero_stock' => $l->t('Clear stock to zero before switching this item to lot or serial tracking.'),
			'location_has_stock' => $l->t('This location still has stock. Move or adjust it to zero before deactivating.'),
			'item_has_movements' => $l->t('This item has movement history and cannot be deleted. Deactivate it instead.'),
			'location_has_movements' => $l->t('This location has movement history and cannot be deleted. Deactivate it instead.'),
			'item_in_open_stocktake' => $l->t('This item is on an open stocktake. Close or finish that stocktake first.'),
			'location_in_open_stocktake' => $l->t('This location has an open stocktake. Close or finish that stocktake first.'),
			'campaign_not_open' => $l->t('This cycle count has already been started or closed.'),
			'campaign_not_counting' => $l->t('This cycle count is not open for counting right now.'),
			'line_already_posted' => $l->t('This line was already posted and cannot be counted again.'),
			'item_lock_conflict' => $l->t('This item changed while you were working. Reload and try again.'),
			default => $l->t('The action conflicts with the current state. Reload and try again.'),
		};
	}

	private function validationMessage(ValidationException $exception, IL10N $l): string
	{
		return match ($exception->getErrorCode()) {
			'invalid_qty' => $l->t('The quantity is not valid.'),
			'qty_out_of_range' => $l->t('The resulting stock would be out of the allowed range.'),
			'same_location' => $l->t('Transfer source and destination must be different.'),
			'inactive_item' => $l->t('This item is deactivated.'),
			'inactive_location' => $l->t('This location is deactivated.'),
			'invalid_code_format' => $l->t('The code format is not valid. Use letters, digits, and . _ / - only.'),
			'invalid_query' => $l->t('The list parameters are not valid.'),
			'unknown_user' => $l->t('This Nextcloud user does not exist.'),
			'unknown_group' => $l->t('This Nextcloud group does not exist.'),
			'unknown_device' => $l->t('This scanner device slot does not exist.'),
			'invalid_pair_code' => $l->t('This pairing code is invalid or expired.'),
			'photo_too_large' => $l->t('The photo is too large. Maximum size is 2 MB.'),
			'photo_type_invalid' => $l->t('Only JPEG, PNG, or WebP photos are allowed.'),
			'upload_failed' => $l->t('The upload failed. Please try again.'),
			'count_incomplete' => $l->t('Every line must be counted before closing, or choose to abandon uncounted lines.'),
			'count_conflict' => $l->t('Stock changed after this stocktake started. Review conflict lines, then close again and confirm you accept the counted quantities.'),
			'track_mode_changed' => $l->t('An item on this stocktake was switched to lot or serial tracking. Remove it from the count or set tracking back to none, then try again.'),
			'stocktake_too_large' => $l->t('Too many active items to include in one stocktake. Deactivate unused items or split the count, then try again.'),
			'favourite_limit' => $l->t('You already have the maximum number of favourite locations.'),
			'location_code_mismatch' => $l->t('The scanned location code does not match the selected location.'),
			default => $l->t('Please check the highlighted fields.'),
		};
	}

}
