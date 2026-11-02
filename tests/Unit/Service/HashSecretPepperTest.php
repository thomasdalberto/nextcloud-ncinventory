<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Tests\Unit\Service;

use OCA\InventoryCheck\Db\ScanDeviceMapper;
use OCA\InventoryCheck\Service\Clock;
use OCA\InventoryCheck\Service\ScannerService;
use OCP\IConfig;
use PHPUnit\Framework\TestCase;

/**
 * N5 — pair/device secrets must be HMAC-peppered with the instance secret.
 */
final class HashSecretPepperTest extends TestCase
{
	public function testHashSecretUsesInstanceSecretAsHmacPepper(): void
	{
		$config = $this->createMock(IConfig::class);
		$config->method('getSystemValueString')->with('secret', '')->willReturn('unit-test-pepper');

		$svc = $this->service($config);
		$hash = $svc->hashSecret('ABCD2345');
		$this->assertSame(hash_hmac('sha256', 'ABCD2345', 'unit-test-pepper'), $hash);
		$this->assertNotSame(hash('sha256', 'ABCD2345'), $hash);
	}

	public function testHashSecretFailsClosedWhenInstanceSecretMissing(): void
	{
		$config = $this->createMock(IConfig::class);
		$config->method('getSystemValueString')->with('secret', '')->willReturn('');

		$svc = $this->service($config);
		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('instance_secret_missing');
		$svc->hashSecret('ABCD2345');
	}

	public function testDifferentPeppersProduceDifferentDigests(): void
	{
		$a = $this->service($this->configWithSecret('pepper-a'));
		$b = $this->service($this->configWithSecret('pepper-b'));
		$this->assertNotSame($a->hashSecret('SAME'), $b->hashSecret('SAME'));
	}

	private function configWithSecret(string $secret): IConfig
	{
		$config = $this->createMock(IConfig::class);
		$config->method('getSystemValueString')->with('secret', '')->willReturn($secret);
		return $config;
	}

	private function service(IConfig $config): ScannerService
	{
		return new ScannerService(
			$this->createMock(ScanDeviceMapper::class),
			$this->createMock(Clock::class),
			$config,
			$this->createMock(\OCA\InventoryCheck\Service\WorkspaceContext::class),
		);
	}
}
