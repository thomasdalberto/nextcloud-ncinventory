<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Controller;

use OCA\InventoryCheck\AppInfo\Application;
use OCA\InventoryCheck\Service\AccessControlService;
use OCA\InventoryCheck\Service\DirectoryOptionsService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * App-admin-only directory search for the Settings pickers: allow/office/
 * notify lists, delegated app administrators, per-location ACL grants, and
 * mobile seat assignment.
 *
 * Portfolio rule (planning/check-productivity-suite/ACCESS-AND-DIRECTORY-PICKERS.md
 * §1 "Never ask humans to type raw IDs"): search + pick only, never a raw
 * uid/gid text field. Every consumer of a picked id still re-validates it
 * server-side (ConfigController::validated*Ids, LicenseService::assignSeat,
 * LocationAclService::setForSubject/replaceAll) — this endpoint only narrows
 * what a human has to type.
 *
 * Gated to app admins only (never every canUseApp user): the lists this feeds
 * are policy surfaces (access/office/notify/app-admin/ACL/seats), matching the
 * portfolio anti-pattern "Directory search endpoints callable by every
 * canUseApp user without manager/admin scope".
 */
class DirectoryController extends Controller
{
	private const MIN_QUERY_LENGTH = 2;
	private const RESULT_LIMIT = 20;

	public function __construct(
		IRequest $request,
		private readonly AccessControlService $access,
		private readonly DirectoryOptionsService $directory,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	#[NoAdminRequired]
	public function searchUsers(): JSONResponse
	{
		$this->access->requireWorkspaceAdmin($this->access->currentUserId());
		$query = trim((string)$this->request->getParam('q', ''));
		if (mb_strlen($query) < self::MIN_QUERY_LENGTH) {
			return new JSONResponse(['users' => []]);
		}
		return new JSONResponse(['users' => $this->directory->searchUsers($query, self::RESULT_LIMIT)]);
	}

	#[NoAdminRequired]
	public function searchGroups(): JSONResponse
	{
		$this->access->requireWorkspaceAdmin($this->access->currentUserId());
		$query = trim((string)$this->request->getParam('q', ''));
		if (mb_strlen($query) < self::MIN_QUERY_LENGTH) {
			return new JSONResponse(['groups' => []]);
		}
		return new JSONResponse(['groups' => $this->directory->searchGroups($query, self::RESULT_LIMIT)]);
	}
}
