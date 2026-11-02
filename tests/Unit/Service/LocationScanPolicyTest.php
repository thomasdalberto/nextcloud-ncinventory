<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Tests\Unit\Service;

use OCA\InventoryCheck\AppInfo\Application;
use OCA\InventoryCheck\Db\Location;
use OCA\InventoryCheck\Db\LocationMapper;
use OCA\InventoryCheck\Exception\ValidationException;
use OCA\InventoryCheck\Service\LocationScanPolicy;
use OCA\InventoryCheck\Service\WorkspaceSettings;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Wave D8 / AF-IV12 — executable policy unit tests (not scrape-only).
 */
final class LocationScanPolicyTest extends TestCase
{
	/** @var WorkspaceSettings&MockObject */
	private WorkspaceSettings $config;
	/** @var LocationMapper&MockObject */
	private LocationMapper $locations;

	protected function setUp(): void
	{
		parent::setUp();
		$this->config = $this->createMock(WorkspaceSettings::class);
		$this->locations = $this->createMock(LocationMapper::class);
	}

	public function testRequiredEmptyCodeFails(): void
	{
		$this->config->method('get')
			->with(LocationScanPolicy::KEY_REQUIRE_LOCATION_SCAN, '0')
			->willReturn('1');

		try {
			LocationScanPolicy::assertMatches($this->config, $this->locations, 3, null);
			$this->fail('expected location_code_required');
		} catch (ValidationException $e) {
			$this->assertSame('location_code_required', $e->getDetails()[0]['code'] ?? null);
			$this->assertSame('locationCode', $e->getDetails()[0]['field'] ?? null);
		}
	}

	public function testRequiredMismatchFailsWithFieldName(): void
	{
		$this->config->method('get')->willReturn('1');
		$loc = new Location();
		$loc->setCode('VAN-1');
		$this->locations->method('findById')->with(9)->willReturn($loc);

		try {
			LocationScanPolicy::assertMatches(
				$this->config,
				$this->locations,
				9,
				'SHELF-A',
				'toLocationCode',
			);
			$this->fail('expected location_code_mismatch');
		} catch (ValidationException $e) {
			$this->assertSame('location_code_mismatch', $e->getErrorCode());
			$this->assertSame('toLocationCode', $e->getDetails()[0]['field'] ?? null);
		}
	}

	public function testRequiredExactMatchPasses(): void
	{
		$this->config->method('get')->willReturn('1');
		$loc = new Location();
		$loc->setCode('VAN-1');
		$this->locations->method('findById')->with(3)->willReturn($loc);

		LocationScanPolicy::assertMatches($this->config, $this->locations, 3, '  VAN-1  ');
		$this->addToAssertionCount(1);
	}

	public function testPolicyOffIgnoresEmptyCode(): void
	{
		$this->config->method('get')->willReturn('0');
		LocationScanPolicy::assertMatches($this->config, $this->locations, 3, null);
		$this->addToAssertionCount(1);
	}

	public function testPolicyOffStillRejectsMismatchedProvidedCode(): void
	{
		$this->config->method('get')->willReturn('0');
		$loc = new Location();
		$loc->setCode('VAN-1');
		$this->locations->method('findById')->with(3)->willReturn($loc);

		$this->expectException(ValidationException::class);
		LocationScanPolicy::assertMatches($this->config, $this->locations, 3, 'OTHER');
	}
}
