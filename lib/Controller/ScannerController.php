<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Controller;

use OCA\InventoryCheck\AppInfo\Application;
use OCA\InventoryCheck\Service\AccessControlService;
use OCA\InventoryCheck\Service\ScannerService;
use OCA\InventoryCheck\Service\LocationAclService;
use OCA\InventoryCheck\Service\Pagination;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

class ScannerController extends Controller
{
	use PolicyAuditTrait;

	public function __construct(
		IRequest $request,
		private readonly ScannerService $scanners,
		private readonly AccessControlService $access,
		private readonly LocationAclService $locationAcl,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	#[NoAdminRequired]
	public function index(): JSONResponse
	{
		$this->access->requireWorkspaceAdmin($this->access->currentUserId());
		$page = Pagination::parse($this->request->getParam('limit'), $this->request->getParam('offset'));
		return new JSONResponse($this->scanners->listDevices($page['limit'], $page['offset']));
	}

	#[NoAdminRequired]
	public function createDevice(): JSONResponse
	{
		$uid = $this->access->currentUserId();
		$this->access->requireWorkspaceAdmin($uid);
		$label = (string)$this->request->getParam('label', '');

		// Validate bind targets before creating the slot so a bad locationIds
		// payload cannot leave an unbound (org-wide BC) scanner behind.
		$bindIds = null;
		$rawIds = $this->request->getParam('locationIds');
		if (is_array($rawIds) && $rawIds !== []) {
			$bindIds = $this->locationAcl->normalizeLocationIds($rawIds);
		}

		$created = $this->scanners->createDevice($uid, $label);

		if ($bindIds !== null && $bindIds !== []) {
			$deviceId = (int)($created['device']['id'] ?? 0);
			try {
				if ($deviceId <= 0) {
					throw new \RuntimeException('device_create_missing_id');
				}
				$this->locationAcl->setForSubject(
					LocationAclService::TYPE_DEVICE,
					(string)$deviceId,
					$bindIds,
				);
			} catch (\Throwable $e) {
				// Fail closed: never leave a half-bound scanner that is pairable org-wide.
				if ($deviceId > 0) {
					try {
						$this->scanners->deactivateDevice($deviceId);
					} finally {
						// purge even if deactivate throws (slot may still have grants).
						$this->locationAcl->purgeDevice($deviceId);
					}
				}
				throw $e;
			}
		}

		$this->auditPolicyChange($uid, 'device_created', [
			'deviceId' => $created['device']['id'] ?? null,
			'boundLocations' => $bindIds !== null ? count($bindIds) : 0,
		]);
		return new JSONResponse($created);
	}

	#[NoAdminRequired]
	public function regeneratePairCode(int $id): JSONResponse
	{
		$uid = $this->access->currentUserId();
		$this->access->requireWorkspaceAdmin($uid);
		$result = $this->scanners->regeneratePairCode($uid, $id);
		// Audit: pair code rotated — never log the code itself.
		$this->auditPolicyChange($uid, 'device_pair_code_regenerated', ['deviceId' => $id]);
		return new JSONResponse($result);
	}

	#[NoAdminRequired]
	public function removeDevice(int $id): JSONResponse
	{
		$actor = $this->access->currentUserId();
		$this->access->requireWorkspaceAdmin($actor);
		$this->scanners->deactivateDevice($id);
		$this->locationAcl->purgeDevice($id);
		$this->auditPolicyChange($actor, 'device_removed', ['deviceId' => $id]);
		return new JSONResponse(['ok' => true]);
	}
}
