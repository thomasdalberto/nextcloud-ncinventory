<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Tests\Integration;

use OCA\InventoryCheck\AppInfo\Application;
use OCA\InventoryCheck\Service\ScannerService;
use OCA\InventoryCheck\Tests\Support\Iv2TestSigning;
use Test\TestCase;

/**
 * AC-18: concurrent pair of the same code — exactly one token wins.
 *
 * @group DB
 */
final class ConcurrentPairingRaceIntegrationTest extends TestCase
{
	use DualProcessRaceTrait;

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

	public function testConcurrentPairClaimsExactlyOneToken(): void
	{
		$app = new Application();
		$license = $app->getContainer()->get(ScannerService::class);
		$license->apply('admin', Iv2TestSigning::signPayload([
			'v' => 2,
			'product' => 'inventorycheck',
			'customerId' => 'race-pair-' . bin2hex(random_bytes(2)),
			'issuedAt' => '2026-07-24',
			'validUntil' => '2099-01-01',
			'mobileSeats' => 1,
			'scanDevices' => 3,
		]));
		foreach ($license->listDevices(200, 0)['data'] as $row) {
			if (!empty($row['active'])) {
				$license->deactivateDevice((int)$row['id']);
			}
		}
		$created = $license->createDevice('admin', 'Race scanner');
		$code = $created['pairCode'];
		$deviceId = (int)$created['device']['id'];
		$this->assertNotSame('', $code);

		[$a, $b] = $this->runDualWorkers(function (string $resultFile, string $goFile, string $root) use ($code): string {
			$codeExport = var_export($code, true);
			$resultExport = var_export($resultFile, true);
			$goExport = var_export($goFile, true);
			$rootExport = var_export($root, true);
			$key = var_export(Iv2TestSigning::publicKeyB64(), true);
			return <<<PHP
<?php
putenv('IV_VENDOR_PUBLIC_KEY_B64=' . {$key});
require {$rootExport} . '/lib/base.php';
\$app = new \\OCA\\InventoryCheck\\AppInfo\\Application();
\$pairing = \$app->getContainer()->get(\\OCA\\InventoryCheck\\Service\\DevicePairingService::class);
while (!is_file({$goExport})) { usleep(1000); }
try {
	\$r = \$pairing->pair({$codeExport});
	file_put_contents({$resultExport}, 'ok:' . \$r['token']);
} catch (\\Throwable \$e) {
	file_put_contents({$resultExport}, 'err:' . \$e->getMessage());
}
PHP;
		});

		$ok = [];
		$err = [];
		foreach ([$a, $b] as $out) {
			if (str_starts_with($out, 'ok:')) {
				$ok[] = substr($out, 3);
			} else {
				$err[] = $out;
			}
		}
		$this->assertCount(1, $ok, "exactly one pair must succeed; got A={$a} B={$b}");
		$this->assertCount(1, $err, "exactly one pair must fail; got A={$a} B={$b}");
		$this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $ok[0]);

		$hash = $license->hashSecret($ok[0]);
		$found = $license->findDeviceByTokenHash($hash);
		$this->assertNotNull($found);
		$this->assertSame($deviceId, (int)$found->getId());
		$this->assertNull($found->getPairCodeHash());
	}
}
