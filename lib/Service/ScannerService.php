<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Service;

use OCA\InventoryCheck\Db\ScanDevice;
use OCA\InventoryCheck\Db\ScanDeviceMapper;
use OCA\InventoryCheck\Exception\NotFoundException;
use OCA\InventoryCheck\Exception\ValidationException;
use OCP\IConfig;

/** Workspace scanner management; pairing and authentication do not depend on entitlements. */
class ScannerService
{
	public function __construct(
		private readonly ScanDeviceMapper $devices,
		private readonly Clock $clock,
		private readonly IConfig $config,
		private readonly WorkspaceContext $workspace,
	) {
	}

	public function createDevice(string $adminUid, string $label): array
	{
		$label = CodeRules::trim($label);
		if ($label === '' || mb_strlen($label) > 255) {
			throw new ValidationException('validation_failed', '', [
				['field' => 'label', 'code' => 'validation_failed'],
			]);
		}

		$code = $this->generatePairCode();
			$hash = $this->hashSecret($code);
			$now = $this->clock->now();
			$device = new ScanDevice();
			$device->setWorkspaceId($this->workspace->id());
			$device->setLabel($label);
			$device->setPairCodeHash($hash);
			$device->setPairCodeExpires($now + 86400);
			$device->setTokenHash(null);
			$device->setPairedAt(null);
			$device->setLastSeenAt(null);
			$device->setActive(true);
			$device->setCreatedAt($now);
			$device->setCreatedBy($adminUid);
			$device = $this->devices->insert($device);
			return ['device' => $device->toApi(), 'pairCode' => $code];
	}

	/** @return array{data: list<array<string, mixed>>, total: int, limit: int, offset: int} */
	public function listDevices(int $limit, int $offset): array
	{
		$all = array_values(array_filter($this->devices->findAll(), fn (ScanDevice $d) => $d->getWorkspaceId() === $this->workspace->id()));
		$rows = array_map(static fn (ScanDevice $d) => $d->toApi(), $all);
		return [
			'data' => array_slice($rows, $offset, $limit),
			'total' => count($rows),
			'limit' => $limit,
			'offset' => $offset,
		];
	}

	/**
	 * Soft-unpair: keep token_hash so the next device call is identifiable as
	 * this slot and returns 403 `device_required` (SPEC §9.3.4). Pairing codes
	 * are cleared. Regenerating a code clears the token separately.
	 */
	public function deactivateDevice(int $id): void
	{
		$device = $this->devices->findById($id);
		if ($device->getWorkspaceId() !== $this->workspace->id()) {
			throw new NotFoundException();
		}
		$device->setActive(false);
		$device->setPairCodeHash(null);
		$device->setPairCodeExpires(null);
		$this->devices->update($device);
	}

	/**
	 * Rotate a one-time pairing code on an existing active scanner.
	 * Clears any prior token so the device must re-pair.
	 *
	 * @return array{device: array<string, mixed>, pairCode: string}
	 */
	public function regeneratePairCode(string $adminUid, int $id): array
	{
		unset($adminUid);
		$device = $this->devices->findById($id);
		if ($device->getWorkspaceId() !== $this->workspace->id()) {
			throw new NotFoundException();
		}
		if (!$device->getActive()) {
			throw new NotFoundException();
		}
		$code = $this->generatePairCode();
		$now = $this->clock->now();
		$rotated = $this->devices->rotatePairCode(
			$id,
			$this->hashSecret($code),
			$now + 86400,
		);
		if (!$rotated) {
			throw new NotFoundException();
		}
		$device = $this->devices->findById($id);
		if ($device->getWorkspaceId() !== $this->workspace->id()) {
			throw new NotFoundException();
		}
		return ['device' => $device->toApi(), 'pairCode' => $code];
	}

	public function findDeviceByTokenHash(string $hash): ?ScanDevice
	{
		return $this->devices->findByTokenHash($hash);
	}

	/**
	 * Hash pairing codes and device tokens (N5).
	 * HMAC-SHA256 with the instance secret so short pair codes are not
	 * offline-crackable from a leaked iv_scan_devices dump alone.
	 *
	 * Fail closed if the instance secret is missing — a static fallback pepper
	 * would make dump-only cracking trivial again.
	 */
	public function hashSecret(string $secret): string
	{
		$pepper = $this->config->getSystemValueString('secret', '');
		if ($pepper === '') {
			throw new \RuntimeException('instance_secret_missing');
		}
		return hash_hmac('sha256', $secret, $pepper);
	}

	private function generatePairCode(): string
	{
		$alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
		$code = '';
		for ($i = 0; $i < 8; $i++) {
			$code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
		}
		return $code;
	}
}
