<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Tests\Integration;

use OCA\InventoryCheck\AppInfo\Application;
use OCA\InventoryCheck\Exception\NotFoundException;
use OCA\InventoryCheck\Service\DevicePairingService;
use OCA\InventoryCheck\Service\ScannerService;
use OCA\InventoryCheck\Tests\Support\Iv2TestSigning;
use Test\TestCase;

/**
 * §9.3.4 — regenerate pair code on the same active slot.
 *
 * @group DB
 */
final class RegeneratePairCodeIntegrationTest extends TestCase
{
	/** @var string|false */
	private $prevEnv;

	protected function setUp(): void
	{
		parent::setUp();
		$this->prevEnv = getenv('IV_VENDOR_PUBLIC_KEY_B64');
		putenv('IV_VENDOR_PUBLIC_KEY_B64=' . Iv2TestSigning::publicKeyB64());
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

	public function testRegenerateInvalidatesOldTokenAndIssuesNewCode(): void
	{
		$app = new Application();
		$c = $app->getContainer();
		$license = $c->get(ScannerService::class);
		$pairing = $c->get(DevicePairingService::class);

		$license->apply('admin', Iv2TestSigning::signPayload([
			'v' => 2,
			'product' => 'inventorycheck',
			'customerId' => 'regen-' . bin2hex(random_bytes(2)),
			'issuedAt' => '2026-07-24',
			'validUntil' => '2099-01-01',
			'mobileSeats' => 1,
			'scanDevices' => 2,
		]));
		foreach ($license->listDevices(200, 0)['data'] as $row) {
			if (!empty($row['active'])) {
				$license->deactivateDevice((int)$row['id']);
			}
		}

		$created = $license->createDevice('admin', 'Regen scanner');
		$id = (int)$created['device']['id'];
		$paired = $pairing->pair($created['pairCode']);
		$tokenHash = $license->hashSecret($paired['token']);
		$this->assertNotNull($license->findDeviceByTokenHash($tokenHash));

		$regen = $license->regeneratePairCode('admin', $id);
		$this->assertMatchesRegularExpression('/^[A-Z2-9]{8}$/', $regen['pairCode']);
		$this->assertSame('pending', $regen['device']['state']);
		$this->assertNull($license->findDeviceByTokenHash($tokenHash));

		$repaired = $pairing->pair($regen['pairCode']);
		$this->assertSame($id, $repaired['deviceId']);

		$license->deactivateDevice($id);
		try {
			$license->regeneratePairCode('admin', $id);
			$this->fail('inactive slot must not regenerate');
		} catch (NotFoundException) {
			$this->addToAssertionCount(1);
		}
	}
}
