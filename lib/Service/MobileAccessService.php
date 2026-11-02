<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Service;

use OCA\InventoryCheck\Db\ScanDevice;
use OCA\InventoryCheck\Exception\MobileRequestException;

/** Mobile capabilities use the same workspace policy as the web app. */
class MobileAccessService
{
	public function __construct(
		private readonly AccessControlService $access,
		private readonly WorkspaceSettings $settings,
		private readonly LocationAclService $locationAcl,
		private readonly ProductLookupService $productLookup,
	) {
	}

	public function requireCaller(?string $uid, ?ScanDevice $device): void
	{
		if (($uid === null || $uid === '') && $device === null) {
			throw new MobileRequestException('auth_required');
		}
	}

	public function bootstrap(?string $userId, ?ScanDevice $device): array
	{
		$qtyScale = QtyScale::current($this->settings);
		$companionApi = 5;
		$canWrite = $userId !== null && $userId !== '' && $this->access->canWrite($userId);
		$deviceLocationIds = null;
		$deviceLocationUnbound = false;
		if ($device !== null) {
			$deviceLocationIds = $this->locationAcl->visibleLocationIds('device:' . (int)$device->getId());
			// Empty grant list under ACL = unbound / blocked scanner (fail closed).
			$deviceLocationUnbound = $this->locationAcl->isEnabled()
				&& is_array($deviceLocationIds)
				&& $deviceLocationIds === [];
		}
		return [
			'devicePaired' => $device !== null,
			'companionApi' => $companionApi,
			'requireAdjustReason' => ReasonCodes::isRequired($this->settings),
			'requireLocationScan' => LocationScanPolicy::isRequired($this->settings),
			'locationAclEnabled' => $this->locationAcl->isEnabled(),
			'locationAclDevicesStrict' => $this->locationAcl->isDevicesStrict(),
			'productLookupEnabled' => $this->productLookup->isEnabled(),
			/** null = unrestricted (ACL off or unbound device); list = restricted grants */
			'deviceLocationIds' => $deviceLocationIds,
			/** true when ACL is on, strict is off, and this scanner has zero grants */
			'deviceLocationUnbound' => $deviceLocationUnbound,
			'capabilities' => [
				'csv' => true,
				'photos' => true,
				'itemPhoto' => true,
				'cycleCount' => true,
				'bulkLabels' => true,
				'locationByCode' => true,
				'reasonCodes' => true,
				'favourites' => true,
				'deviceLocationAcl' => true,
				'deviceLocationStrict' => true,
				'productLookup' => true,
				'qtyScale' => $qtyScale,
			],
			'reasonCodes' => ReasonCodes::catalog(),
			'user' => $userId,
			'canWrite' => $canWrite,
		];
	}
}
