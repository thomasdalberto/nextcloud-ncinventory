<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Tests\Unit\Controller;

use OCA\InventoryCheck\Service\SettingsSectionCatalog;
use PHPUnit\Framework\TestCase;

/**
 * Cross-artifact drift protection for split settings sub-pages.
 */
final class SettingsPagesContractTest extends TestCase
{
	private static function appRoot(): string
	{
		return dirname(__DIR__, 3);
	}

	private static function read(string $relative): string
	{
		$path = self::appRoot() . '/' . $relative;
		self::assertFileExists($path);
		return (string)file_get_contents($path);
	}

	/** @return list<array<string, mixed>> */
	private static function routes(): array
	{
		$routes = include self::appRoot() . '/appinfo/routes.php';
		self::assertIsArray($routes);
		self::assertArrayHasKey('routes', $routes);
		return $routes['routes'];
	}

	/** @return array<string, mixed>|null */
	private static function routeByName(string $name): ?array
	{
		foreach (self::routes() as $route) {
			if (($route['name'] ?? '') === $name) {
				return $route;
			}
		}
		return null;
	}

	public function testRouteRequirementMatchesCatalog(): void
	{
		$route = self::routeByName('page#settingsSection');
		self::assertNotNull($route);
		self::assertSame('/settings/{section}', $route['url'] ?? null);
		self::assertSame(
			SettingsSectionCatalog::routeRequirement(),
			$route['requirements']['section'] ?? null,
		);
	}

	public function testLegacySettingsRouteStillExists(): void
	{
		$route = self::routeByName('page#settings');
		self::assertNotNull($route);
		self::assertSame('/settings', $route['url'] ?? null);
	}

	public function testCatalogDefaultIsMembers(): void
	{
		self::assertSame('members', SettingsSectionCatalog::DEFAULT_SECTION);
		self::assertContains(SettingsSectionCatalog::DEFAULT_SECTION, SettingsSectionCatalog::SECTIONS);
	}

	public function testAppJsSwitchesOnEveryCatalogSection(): void
	{
		$js = self::read('js/app.js');
		foreach (SettingsSectionCatalog::SECTIONS as $section) {
			self::assertStringContainsString("section === '" . $section . "'", $js, $section);
		}
		self::assertStringContainsString('data-iv-settings-section', $js);
	}

	public function testNavigationAndChipBarUseCatalogArtifacts(): void
	{
		$nav = self::read('templates/common/navigation.php');
		self::assertStringContainsString('iv-settings-subnav', $nav);
		self::assertStringContainsString('settingsSectionLabels', $nav);
		self::assertStringContainsString('settingsSections', $nav);

		$chips = self::read('templates/parts/settings-nav.php');
		self::assertStringContainsString('iv-settings-pages', $chips);
		self::assertStringContainsString('Settings pages', $chips);
		self::assertStringContainsString('settingsSectionLabels', $chips);

		$settings = self::read('templates/settings.php');
		self::assertStringContainsString('parts/settings-nav.php', $settings);
	}

	public function testPageControllerWiresCatalog(): void
	{
		$src = self::read('lib/Controller/PageController.php');
		self::assertStringContainsString('SettingsSectionCatalog', $src);
		self::assertStringContainsString('RedirectResponse', $src);
		self::assertStringContainsString('NotFoundResponse', $src);
		self::assertStringContainsString('settingsSection', $src);
		// Labels must be in the base $params array (every page), not only inside the
		// settings-section branch — otherwise the sidebar Settings submenu is empty.
		self::assertMatchesRegularExpression(
			"/\\\$params\s*=\s*\[[\s\S]*?'settingsSectionLabels'\s*=>\s*\\\$settingsSectionLabels/",
			$src,
		);
		self::assertStringContainsString(
			'Settings submenu is populated from every page',
			$src,
		);
	}

	public function testNavigationFallsBackWhenLabelsMissing(): void
	{
		$nav = self::read('templates/common/navigation.php');
		self::assertStringContainsString('array_fill_keys(array_keys($settingsSectionUrls)', $nav);
		self::assertStringContainsString('$ivSettingsNav', $nav);
	}

	public function testSettingsNavCssHasWcagActiveInk(): void
	{
		$css = self::read('css/app.css');
		self::assertStringContainsString('.iv-settings-nav', $css);
		self::assertStringContainsString('min-height: 44px', $css);
		self::assertStringContainsString('Keep main-text ink', $css);
		self::assertStringContainsString('.iv-switch-field', $css);
		self::assertStringContainsString('.iv-form-actions', $css);
		self::assertStringContainsString('.iv-settings-page', $css);
	}
}
