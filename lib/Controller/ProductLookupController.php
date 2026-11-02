<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Controller;

use OCA\InventoryCheck\AppInfo\Application;
use OCA\InventoryCheck\Service\AccessControlService;
use OCA\InventoryCheck\Service\Lookup\LookupException;
use OCA\InventoryCheck\Service\ProductLookupService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;

/**
 * Web product-lookup API. Session + CSRF by default (no NoCSRFRequired);
 * office permission on top of the middleware's canUseApp check.
 */
class ProductLookupController extends Controller
{
	public function __construct(
		IRequest $request,
		private readonly AccessControlService $access,
		private readonly ProductLookupService $lookup,
		private readonly IL10N $l10n,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	#[NoAdminRequired]
	public function lookup(): JSONResponse
	{
		$uid = $this->access->currentUserId();
		$this->access->requireWrite($uid);
		$barcode = $this->request->getParam('barcode', '');
		try {
			if (!is_string($barcode)) {
				throw new LookupException('invalid_barcode', 'invalid', 422);
			}
			return new JSONResponse($this->lookup->lookup($barcode, $uid));
		} catch (LookupException $e) {
			$message = match ($e->errorCode) {
				'invalid_barcode' => $this->l10n->t('Enter a valid EAN or UPC barcode (8, 12, 13 or 14 digits).'),
				'rate_limited' => $this->l10n->t('Please wait a few seconds before searching again.'),
				'feature_disabled' => $this->l10n->t('Online product lookup is disabled.'),
				default => $this->l10n->t('Online product lookup is unavailable right now. Enter the item manually or try the web search link.'),
			};
			return new JSONResponse(['error' => ['code' => $e->errorCode, 'message' => $message, 'details' => []]], $e->status);
		}
	}
}
