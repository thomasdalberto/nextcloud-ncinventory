<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Tests\Unit\Service;

use OCA\InventoryCheck\Service\SettingsSectionCatalog;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;

final class SettingsSectionCatalogTest extends TestCase
{
	public function testIsSectionStrict(): void
	{
		$cat = new SettingsSectionCatalog();
		self::assertTrue($cat->isSection('members'));
		self::assertTrue($cat->isSection('location-access'));
		self::assertFalse($cat->isSection('Access'));
		self::assertFalse($cat->isSection('../etc'));
		self::assertFalse($cat->isSection(''));
	}

	public function testLabelsAndHelpAreNonEmptyForOperationalSections(): void
	{
		$l = $this->createMock(IL10N::class);
		$l->method('t')->willReturnCallback(static fn (string $s): string => $s);
		$cat = new SettingsSectionCatalog();
		foreach (SettingsSectionCatalog::SECTIONS as $section) {
			self::assertNotSame('', $cat->navLabel($l, $section));
			self::assertNotSame('', $cat->label($l, $section));
			// SETTINGS-PAGES-STANDARD: every section has a lead (H1 companion).
			self::assertNotSame('', $cat->help($l, $section), $section);
		}
	}

	public function testRouteRequirementIsPipeJoinedAllowlist(): void
	{
		$req = SettingsSectionCatalog::routeRequirement();
		self::assertSame(implode('|', SettingsSectionCatalog::SECTIONS), $req);
		self::assertStringNotContainsString(' ', $req);
	}
}
