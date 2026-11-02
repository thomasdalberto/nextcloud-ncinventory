<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;

/**
 * Route + shell contracts for the stocktake create page (no modal chooser).
 */
final class StocktakeCreatePageContractTest extends TestCase
{
	private string $routes;
	private string $pageController;
	private string $pageStart;
	private string $js;
	private string $css;

	protected function setUp(): void
	{
		$root = dirname(__DIR__, 3);
		$this->routes = (string)file_get_contents($root . '/appinfo/routes.php');
		$this->pageController = (string)file_get_contents($root . '/lib/Controller/PageController.php');
		$this->pageStart = (string)file_get_contents($root . '/templates/common/page-start.php');
		$this->js = (string)file_get_contents($root . '/js/app.js');
		$this->css = (string)file_get_contents($root . '/css/app.css');
	}

	public function testCreateRouteIsLiteralPathNotIdCapture(): void
	{
		self::assertStringContainsString("'/stocktake/create'", $this->routes);
		self::assertStringContainsString('page#stocktakeNew', $this->routes);
		self::assertDoesNotMatchRegularExpression(
			"/stocktake\\/new'/",
			$this->routes,
			'Avoid /stocktake/new — NC route cache historically 404’d it against {id}',
		);
		// {id} must stay digits-only so "create" never binds as a campaign id.
		self::assertMatchesRegularExpression(
			"/stocktake\\/\\{id\\}'[^\\n]*requirements[^\\n]*id[^\\n]*\\\\\\\\d\\+/",
			$this->routes,
		);
	}

	public function testControllerExposesStocktakeNewAndUrlMap(): void
	{
		self::assertStringContainsString('function stocktakeNew()', $this->pageController);
		self::assertStringContainsString("'stocktake-new'", $this->pageController);
		self::assertStringContainsString("'stocktakeNew'", $this->pageController);
		self::assertStringContainsString("'stocktake-new' => 'stocktake'", $this->pageController);
		self::assertStringContainsString(
			'Tap a location below to start counting — favourites appear first',
			$this->pageController,
		);
	}

	public function testBreadcrumbAndNavTreatCreateAsStocktakeChild(): void
	{
		self::assertStringContainsString("'stocktake-new'", $this->pageStart);
		self::assertStringContainsString("\$pageId === 'stocktake-new'", $this->pageStart);
		$nav = (string)file_get_contents(dirname(__DIR__, 3) . '/templates/common/navigation.php');
		self::assertStringContainsString("'stocktake-new' => 'stocktake'", $nav);
	}

	public function testClientGatesOfficeAndValidatesLocationId(): void
	{
		self::assertStringContainsString('Office access required', $this->js);
		self::assertStringContainsString('!ctx.canWrite', $this->js);
		self::assertStringContainsString('if (!locId || locId < 1 || locId !== Math.floor(locId))', $this->js);
		self::assertStringContainsString('Arm busy before the async create path', $this->js);
		self::assertStringContainsString('setBusy(true);', $this->js);
		self::assertStringContainsString('opts.onPick(loc);', $this->js);
		self::assertMatchesRegularExpression(
			'/setBusy\(true\);\s*opts\.onPick\(loc\);/',
			$this->js,
		);
		self::assertStringContainsString('labelSrOnly: true', $this->js);
		self::assertStringContainsString('.iv-stocktake-new', $this->css);
		self::assertStringContainsString('max-height: none', $this->css);
	}

	public function testDialogShellScrollsBodyNotOuterPadding(): void
	{
		self::assertStringContainsString('.iv-dialog__body', $this->css);
		self::assertMatchesRegularExpression(
			'/\.iv-dialog\s*\{[^}]*overflow:\s*hidden/s',
			$this->css,
		);
		self::assertMatchesRegularExpression(
			'/\.iv-dialog__body\s*\{[^}]*overflow-y:\s*auto/s',
			$this->css,
		);
		$ime = (string)file_get_contents(dirname(__DIR__, 3) . '/js/common/keep-focused-visible.js');
		self::assertStringContainsString("'.iv-dialog__body'", $ime);
		self::assertStringContainsString('KEYBOARD_SHRINK_PX', $ime);
		self::assertStringContainsString(
			'win.visualViewport.height < win.innerHeight - KEYBOARD_SHRINK_PX',
			$ime,
		);
		self::assertStringContainsString('softKeyboardLikelyOpen', $ime);
		self::assertStringContainsString('OVERLAY_CHROME_SKIP_SEL', $ime);
	}
}
