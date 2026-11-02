<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;

/**
 * AC-21 / SPEC §11.4 — executable a11y contracts (templates + CSS + JS).
 * Complements browser axe runs; fails the build if family a11y regresses.
 */
final class AccessibilityContractTest extends TestCase
{
	private string $root;

	protected function setUp(): void
	{
		parent::setUp();
		$this->root = dirname(__DIR__, 3);
	}

	public function testShellHasSkipLinkLiveRegionsLangAndMainLandmark(): void
	{
		$src = (string)file_get_contents($this->root . '/templates/common/page-start.php');
		self::assertStringContainsString('iv-skip-link', $src);
		self::assertStringContainsString('href="#iv-main-content"', $src);
		self::assertStringContainsString('role="status"', $src);
		self::assertStringContainsString('aria-live="polite"', $src);
		self::assertStringContainsString('role="alert"', $src);
		self::assertStringContainsString('aria-live="assertive"', $src);
		self::assertStringContainsString('lang="<?php p($htmlLang); ?>"', $src);
		self::assertStringContainsString('<main id="iv-main-content"', $src);
		self::assertStringContainsString('aria-labelledby="iv-page-title"', $src);
		self::assertStringContainsString('id="iv-toast-region"', $src);
		self::assertStringContainsString('role="region"', $src);
	}

	public function testNavigationIsLandmarkWithAriaLabel(): void
	{
		$src = (string)file_get_contents($this->root . '/templates/common/navigation.php');
		self::assertStringContainsString('role="navigation"', $src);
		self::assertStringContainsString('aria-label', $src);
		self::assertStringContainsString('aria-current', $src);
	}

	public function testEmptyStateUsesFamilyShellAndStatusRole(): void
	{
		$js = (string)file_get_contents($this->root . '/js/app.js');
		self::assertStringContainsString("className: 'iv-empty iv-empty-state'", $js);
		self::assertStringContainsString("role: 'status'", $js);
		self::assertStringContainsString("className: 'iv-empty__icon iv-empty-state__icon'", $js);
		$css = (string)file_get_contents($this->root . '/css/app.css');
		self::assertStringContainsString('.iv-empty-state', $css);
	}

	public function testNavigationPutsAriaCurrentOnLinks(): void
	{
		$src = (string)file_get_contents($this->root . '/templates/common/navigation.php');
		// PHP short close tags contain `>`, so avoid `[^>]*` between `<a` and aria-current.
		self::assertMatchesRegularExpression(
			'/<a\s[\s\S]{0,240}?aria-current="page"/',
			$src,
			'aria-current must be on the navigation link',
		);
		self::assertDoesNotMatchRegularExpression(
			'/<li\b[^>]*\baria-current="page"/',
			$src,
			'aria-current must not remain on the list item',
		);
	}

	public function testStocktakeProgressExposesProgressbarSemantics(): void
	{
		$js = (string)file_get_contents($this->root . '/js/app.js');
		self::assertStringContainsString("role: 'progressbar'", $js);
		self::assertStringContainsString("'aria-label': tr('Count progress')", $js);
		self::assertStringContainsString("'aria-valuenow'", $js);
		self::assertStringContainsString("'aria-valuetext'", $js);
	}

	public function testCssFocusVisibleTouchTargetsAndResponsiveBreakpoints(): void
	{
		$css = (string)file_get_contents($this->root . '/css/app.css');
		$a11y = (string)file_get_contents($this->root . '/css/common/accessibility.css');
		$tokens = (string)file_get_contents($this->root . '/css/common/tokens.css');
		$bundle = $css . $a11y . $tokens;
		self::assertMatchesRegularExpression('/:focus-visible/', $bundle);
		self::assertStringContainsString('common/accessibility.css', $css);
		self::assertStringContainsString('#content.app-inventorycheck', $a11y);
		self::assertDoesNotMatchRegularExpression('/^\s*\*:focus-visible/m', $css);
		self::assertGreaterThanOrEqual(4, preg_match_all('/min-height:\s*(?:var\(--iv-touch,\s*)?44px/', $bundle));
		self::assertMatchesRegularExpression('/@media\s*\(\s*max-width:\s*720px\s*\)/', $css);
		self::assertMatchesRegularExpression('/@media\s*\(\s*max-width:\s*768\.98px\s*\)/', $css);
		self::assertStringContainsString('iv-skip-link', $css);
		self::assertStringContainsString('prefers-reduced-motion', $bundle);
		self::assertStringContainsString('--iv-scrim', $tokens);
		self::assertStringContainsString('--iv-touch: 44px', $tokens);
		self::assertStringContainsString('--iv-qr-canvas', $tokens);
		self::assertStringContainsString('--inventorycheck-color-primary:', $tokens);
		self::assertStringContainsString('var(--iv-scrim', $css);
		self::assertStringContainsString('forced-colors: active', $bundle);
		self::assertStringContainsString('prefers-contrast: more', $bundle);
		self::assertDoesNotMatchRegularExpression('/rgba\(\s*0\s*,\s*0\s*,\s*0/', $css);
	}

	public function testDetailGridNeutralisesNextcloudCoreDtChrome(): void
	{
		$css = (string)file_get_contents($this->root . '/css/app.css');
		self::assertMatchesRegularExpression(
			'/\.iv-detail__grid\s+dt[^{]*\{[^}]*text-align:\s*start/s',
			$css,
			'Detail grids must force text-align: start against core dt end-align',
		);
		self::assertMatchesRegularExpression(
			'/\.iv-detail__grid\s+dt[^{]*\{[^}]*width:\s*auto/s',
			$css,
		);
	}

	public function testJsDialogHasModalSemanticsFocusTrapAndEsc(): void
	{
		$js = (string)file_get_contents($this->root . '/js/app.js');
		self::assertStringContainsString("role: 'dialog'", $js);
		self::assertStringContainsString("'aria-modal': 'true'", $js);
		self::assertStringContainsString('previouslyFocused', $js);
		self::assertStringContainsString("ev.key === 'Escape'", $js);
		self::assertStringContainsString("ev.key !== 'Tab'", $js);
		self::assertStringContainsString('iv-dialog-overlay', $js);
	}

	public function testLabelSvgProvidesTextAlternativeA12(): void
	{
		$src = (string)file_get_contents($this->root . '/lib/Util/LabelSvg.php');
		self::assertStringContainsString('id="iv-label-code"', $src);
		self::assertStringContainsString('Code128Svg::barsGroup', $src);
		self::assertStringContainsString('aria-label', $src);
		self::assertStringContainsString('role="img"', $src);
		$barcode = (string)file_get_contents($this->root . '/lib/Util/Code128Svg.php');
		self::assertStringContainsString("id = 'iv-label-barcode'", $barcode);
		self::assertStringContainsString('code128b', $barcode);
		$css = (string)file_get_contents($this->root . '/css/app.css');
		self::assertMatchesRegularExpression('/@media\s+print/', $css);
		self::assertStringContainsString('12pt', $css);
		self::assertStringContainsString('iv-label-preview', $css);
		$js = (string)file_get_contents($this->root . '/js/app.js');
		self::assertStringContainsString("tr('Label preview')", $js);
		self::assertStringContainsString('labelSvgUrl', $js);
	}

	public function testNoInnerHtmlAssignmentInAppJs(): void
	{
		$js = (string)file_get_contents($this->root . '/js/app.js');
		self::assertDoesNotMatchRegularExpression('/\.innerHTML\s*=/', $js);
	}
}
