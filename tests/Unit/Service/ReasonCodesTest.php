<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Tests\Unit\Service;

use OCA\InventoryCheck\AppInfo\Application;
use OCA\InventoryCheck\Exception\ValidationException;
use OCA\InventoryCheck\Service\ReasonCodes;
use OCA\InventoryCheck\Service\WorkspaceSettings;
use PHPUnit\Framework\TestCase;

final class ReasonCodesTest extends TestCase
{
	public function testCatalogHasSixCodes(): void
	{
		$codes = array_column(ReasonCodes::catalog(), 'code');
		$this->assertSame(ReasonCodes::CODES, $codes);
	}

	public function testNormalizeValid(): void
	{
		$this->assertSame('inventur', ReasonCodes::normalize(' Inventur '));
	}

	public function testNormalizeEmptyIsNull(): void
	{
		$this->assertNull(ReasonCodes::normalize(''));
		$this->assertNull(ReasonCodes::normalize(null));
	}

	public function testNormalizeInvalidThrows(): void
	{
		$this->expectException(ValidationException::class);
		ReasonCodes::normalize('not-a-code');
	}

	public function testRequireWhenPolicyOffAllowsNull(): void
	{
		$config = $this->createMock(WorkspaceSettings::class);
		$config->method('get')->willReturnMap([
			[ReasonCodes::KEY_REQUIRE_ADJUST_REASON, '1', '0'],
		]);
		$this->assertNull(ReasonCodes::requireForAdjust($config, null));
	}

	public function testRequireWhenPolicyOnRejectsNull(): void
	{
		$config = $this->createMock(WorkspaceSettings::class);
		$config->method('get')->willReturnMap([
			[ReasonCodes::KEY_REQUIRE_ADJUST_REASON, '1', '1'],
		]);
		$this->expectException(ValidationException::class);
		ReasonCodes::requireForAdjust($config, null);
	}

	public function testMissingKeyMeansNotRequired(): void
	{
		$config = $this->createMock(WorkspaceSettings::class);
		$config->method('get')->willReturn('');
		$this->assertFalse(ReasonCodes::isRequired($config));
	}
}
