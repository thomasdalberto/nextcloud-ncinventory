<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Tests\Unit\Service;

use OCA\InventoryCheck\Db\ScanDevice;
use OCA\InventoryCheck\Db\ScanDeviceMapper;
use OCA\InventoryCheck\Exception\MobileRequestException;
use OCA\InventoryCheck\Exception\ValidationException;
use OCA\InventoryCheck\Service\Clock;
use OCA\InventoryCheck\Service\DevicePairingService;
use OCA\InventoryCheck\Service\ScannerService;
use OCP\IConfig;
use OCP\IRequest;
use OCP\Lock\ILockingProvider;
use OCP\Lock\LockedException;
use PHPUnit\Framework\TestCase;

final class DevicePairingServiceTest extends TestCase
{
	private ScannerService $license;
	private ScanDeviceMapper $devices;
	private Clock $clock;
	private IConfig $config;
	private ILockingProvider $locking;
	private IRequest $request;
	private DevicePairingService $pairing;
	/** @var array<string, string> */
	private array $appValues = [];
	private string $clientIp = '203.0.113.10';

	protected function setUp(): void
	{
		parent::setUp();
		$this->license = $this->createMock(ScannerService::class);
		$this->devices = $this->createMock(ScanDeviceMapper::class);
		$this->clock = $this->createMock(Clock::class);
		$this->clock->method('now')->willReturn(1_700_000_000);
		$this->config = $this->createMock(IConfig::class);
		$this->config->method('getAppValue')->willReturnCallback(
			fn (string $app, string $key, string $default = '') => $this->appValues[$key] ?? $default,
		);
		$this->config->method('setAppValue')->willReturnCallback(
			function (string $app, string $key, string $value): void {
				$this->appValues[$key] = $value;
			},
		);
		$this->locking = $this->createMock(ILockingProvider::class);
		$this->request = $this->createMock(IRequest::class);
		$this->request->method('getRemoteAddress')->willReturnCallback(fn (): string => $this->clientIp);
		$this->pairing = $this->makePairing();
	}

	private function makePairing(): DevicePairingService
	{
		return new DevicePairingService(
			$this->license,
			$this->devices,
			$this->clock,
			$this->config,
			$this->locking,
			$this->request,
		);
	}

	private function rateKeyForIp(string $ip): string
	{
		return 'pair_fail:' . substr(hash('sha256', $ip), 0, 32);
	}

	public function testPairSuccessClaimsAtomicallyAndReturnsTokenOnce(): void
	{
		$device = new ScanDevice();
		$device->setId(7);
		$device->setActive(true);
		$device->setPairCodeHash('HASH');
		$device->setPairCodeExpires(1_700_000_000 + 100);
		$this->license->method('hashSecret')->willReturnCallback(
			static fn (string $s): string => $s === 'ABCD2345' ? 'HASH' : 'tok-' . $s,
		);
		$this->devices->method('findPendingByPairCodeHash')->with('HASH')->willReturn($device);
		$this->devices->expects($this->once())->method('claimPairing')->with(
			7,
			'HASH',
			$this->stringStartsWith('tok-'),
			1_700_000_000,
		)->willReturn(true);

		$result = $this->pairing->pair('abcd2345');
		$this->assertSame(7, $result['deviceId']);
		$this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $result['token']);
	}

	public function testPairRejectsWhenClaimLosesRace(): void
	{
		$device = new ScanDevice();
		$device->setId(7);
		$device->setActive(true);
		$device->setPairCodeHash('HASH');
		$device->setPairCodeExpires(1_700_000_000 + 100);
		$this->license->method('hashSecret')->willReturn('HASH');
		$this->devices->method('findPendingByPairCodeHash')->willReturn($device);
		$this->devices->method('claimPairing')->willReturn(false);
		$this->expectException(ValidationException::class);
		$this->pairing->pair('ABCD2345');
	}

	public function testPairRejectsExpiredCode(): void
	{
		$device = new ScanDevice();
		$device->setId(1);
		$device->setActive(true);
		$device->setPairCodeHash('HASH');
		$device->setPairCodeExpires(1_700_000_000 - 1);
		$this->license->method('hashSecret')->willReturn('HASH');
		$this->devices->method('findPendingByPairCodeHash')->willReturn($device);
		$this->devices->expects($this->never())->method('claimPairing');
		$this->expectException(ValidationException::class);
		$this->pairing->pair('ABCD2345');
	}

	public function testRateLimitAfterTenFailures(): void
	{
		$this->license->method('hashSecret')->willReturn('NONE');
		$this->devices->method('findPendingByPairCodeHash')->willReturn(null);
		for ($i = 0; $i < 10; $i++) {
			try {
				$this->pairing->pair('ZZZZZZZZ');
			} catch (ValidationException) {
				// expected
			}
		}
		$this->expectException(MobileRequestException::class);
		$this->pairing->pair('ZZZZZZZZ');
	}

	public function testRateLimitAppliesToMalformedCodesToo(): void
	{
		for ($i = 0; $i < 10; $i++) {
			try {
				$this->pairing->pair('!!!');
			} catch (ValidationException) {
				// expected
			}
		}
		$this->expectException(MobileRequestException::class);
		$this->pairing->pair('bad');
	}

	public function testRateLimitIsPerClientIpNotGlobal(): void
	{
		$this->license->method('hashSecret')->willReturn('NONE');
		$this->devices->method('findPendingByPairCodeHash')->willReturn(null);
		for ($i = 0; $i < 10; $i++) {
			try {
				$this->pairing->pair('ZZZZZZZZ');
			} catch (ValidationException) {
				// expected
			}
		}
		$this->clientIp = '198.51.100.20';
		// Different IP must still be allowed (not frozen by the other bucket).
		try {
			$this->pairing->pair('ZZZZZZZZ');
			$this->fail('expected ValidationException for bad code on fresh IP');
		} catch (ValidationException) {
			// expected
		}
		$this->assertSame(
			1,
			(int)(json_decode($this->appValues[$this->rateKeyForIp('198.51.100.20')], true)['count'] ?? 0),
		);
		$this->assertSame(
			10,
			(int)(json_decode($this->appValues[$this->rateKeyForIp('203.0.113.10')], true)['count'] ?? 0),
		);
	}

	public function testTouchLastSeenSkipsWithinFiveMinutes(): void
	{
		$device = new ScanDevice();
		$device->setId(3);
		$device->setLastSeenAt(1_700_000_000 - 100);
		$this->devices->expects($this->never())->method('update');
		$this->pairing->touchLastSeen($device);
	}

	public function testTouchLastSeenUpdatesAfterThrottleWindow(): void
	{
		$device = new ScanDevice();
		$device->setId(3);
		$device->setLastSeenAt(1_700_000_000 - 301);
		$this->devices->expects($this->once())->method('update')->with($this->callback(
			static fn (ScanDevice $d): bool => $d->getLastSeenAt() === 1_700_000_000,
		));
		$this->pairing->touchLastSeen($device);
	}

	public function testRateLimitLockContentionSurfacesAsRateLimited(): void
	{
		$this->locking = $this->createMock(ILockingProvider::class);
		$this->locking->method('acquireLock')->willThrowException(new LockedException('busy'));
		$this->pairing = $this->makePairing();
		$this->expectException(MobileRequestException::class);
		$this->pairing->pair('ZZZZZZZZ');
	}

	public function testRecordFailureThrowsWhenWindowAlreadyFull(): void
	{
		$key = $this->rateKeyForIp($this->clientIp);
		$this->appValues[$key] = json_encode([
			'start' => 1_700_000_000,
			'count' => 10,
		]);
		$method = new \ReflectionMethod(DevicePairingService::class, 'recordFailureOrThrowRateLimited');
		try {
			$method->invoke($this->pairing);
			$this->fail('expected MobileRequestException when window is full');
		} catch (MobileRequestException) {
			// expected
		}
		$this->assertSame(
			10,
			(int)(json_decode($this->appValues[$key], true)['count'] ?? 0),
			'full window must not increment further',
		);
	}

	public function testRateLimitUsesExclusiveLockAroundCounter(): void
	{
		$lock = 'inventorycheck/pair_rate/' . substr(hash('sha256', $this->clientIp), 0, 16);
		$this->locking->expects($this->atLeastOnce())->method('acquireLock')->with(
			$lock,
			ILockingProvider::LOCK_EXCLUSIVE,
		);
		$this->locking->expects($this->atLeastOnce())->method('releaseLock')->with(
			$lock,
			ILockingProvider::LOCK_EXCLUSIVE,
		);
		$this->license->method('hashSecret')->willReturn('NONE');
		$this->devices->method('findPendingByPairCodeHash')->willReturn(null);
		try {
			$this->pairing->pair('ZZZZZZZZ');
		} catch (ValidationException) {
			// expected
		}
	}
}
