<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Tests\Integration;

use OCA\InventoryCheck\AppInfo\Application;
use OCA\InventoryCheck\Exception\ValidationException;
use OCA\InventoryCheck\Service\DevicePairingService;
use OCA\InventoryCheck\Service\ScannerService;
use OCA\InventoryCheck\Tests\Support\Iv2TestSigning;
use Test\TestCase;

/**
 * AC-18: pair once → token authenticates → deactivate → token rejected.
 *
 * @group DB
 */
final class DevicePairingFlowIntegrationTest extends TestCase
{
	private ScannerService $license;
	private DevicePairingService $pairing;
	/** @var string|false */
	private $prevEnv;

	protected function setUp(): void
	{
		parent::setUp();
		$this->prevEnv = getenv('IV_VENDOR_PUBLIC_KEY_B64');
		putenv('IV_VENDOR_PUBLIC_KEY_B64=' . Iv2TestSigning::publicKeyB64());
		$app = new Application();
		$c = $app->getContainer();
		$this->license = $c->get(ScannerService::class);
		$this->pairing = $c->get(DevicePairingService::class);

		$this->license->apply('admin', Iv2TestSigning::signPayload([
			'v' => 2,
			'product' => 'inventorycheck',
			'customerId' => 'pair-' . bin2hex(random_bytes(2)),
			'issuedAt' => '2026-07-24',
			'validUntil' => '2099-01-01',
			'mobileSeats' => 1,
			'scanDevices' => 2,
		]));
		foreach ($this->license->listDevices(200, 0)['data'] as $row) {
			if (!empty($row['active'])) {
				$this->license->deactivateDevice((int)$row['id']);
			}
		}
	}

	protected function tearDown(): void
	{
		if ($this->prevEnv === false) {
			putenv('IV_VENDOR_PUBLIC_KEY_B64');
		} else {
			putenv('IV_VENDOR_PUBLIC_KEY_B64=' . $this->prevEnv);
		}
		parent::tearDown();
	}

	public function testPairTokenAuthAndUnpairRejectsToken(): void
	{
		$created = $this->license->createDevice('admin', 'Van scanner');
		$code = $created['pairCode'];
		$deviceId = (int)$created['device']['id'];
		$this->assertMatchesRegularExpression('/^[A-Z2-9]{8}$/', $code);

		$paired = $this->pairing->pair($code);
		$this->assertSame($deviceId, $paired['deviceId']);
		$token = $paired['token'];
		$this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $token);

		// Code is single-use — second attempt must fail.
		try {
			$this->pairing->pair($code);
			$this->fail('expected invalid_pair_code on reuse');
		} catch (ValidationException $e) {
			$this->assertSame('invalid_pair_code', $e->getErrorCode());
		}

		$hash = $this->license->hashSecret($token);
		$found = $this->license->findDeviceByTokenHash($hash);
		$this->assertNotNull($found);
		$this->assertTrue($found->getActive());
		$this->assertSame($deviceId, (int)$found->getId());

		// Unpair / deactivate: keep token hash so the device is identifiable,
		// but mark inactive → MobileController returns 402 device_required.
		$this->license->deactivateDevice($deviceId);
		$after = $this->license->findDeviceByTokenHash($hash);
		$this->assertNotNull($after, 'token hash retained so unpair maps to device_required');
		$this->assertFalse($after->getActive());
		$this->assertSame($hash, $after->getTokenHash());
	}
}
