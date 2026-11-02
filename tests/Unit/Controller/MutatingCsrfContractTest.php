<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Tests\Unit\Controller;

use PHPUnit\Framework\TestCase;

/**
 * Mutating JSON APIs must stay CSRF-protected (browser session callers).
 * Page/label GETs and Mobile PublicPage routes are the only NoCSRFRequired surfaces.
 */
final class MutatingCsrfContractTest extends TestCase
{
	/**
	 * Every action behind a mutating verb in appinfo/routes.php (browser
	 * surface; mobile/* is the token-authenticated PublicPage exception).
	 * Kept honest by {@see testEveryMutatingRouteIsPinned}.
	 */
	private const MUTATING = [
		'create', 'update', 'destroy', 'invite', 'removeMember', 'respond', 'notificationAction',
		'receive', 'issue', 'transfer', 'adjust', 'scan',
		'saveStock', 'saveNotify', 'saveFractional', 'saveWaveD', 'saveLocationAcl', 'changeRole', 'save',
		'createDevice', 'regeneratePairCode', 'removeDevice',
		'start', 'setCount', 'close',
		'dryRun', 'commit',
		'upload',
		'lookup',
		'issueMaintWo', 'issueProject', 'saveSettings', 'retryInvoicePush',
	];

	/** @return list<string> */
	private function controllerFiles(): array
	{
		$root = dirname(__DIR__, 3) . '/lib/Controller';
		$files = glob($root . '/*.php');
		self::assertNotFalse($files);
		$out = [];
		foreach ($files as $file) {
			if (basename($file) === 'MobileController.php') {
				continue;
			}
			$out[] = $file;
		}
		return $out;
	}

	public function testMutatingMethodsAreNotCsrfExempt(): void
	{
		foreach ($this->controllerFiles() as $file) {
			$src = (string)file_get_contents($file);
			$base = basename($file);
			foreach (self::MUTATING as $method) {
				if (!preg_match('/public function ' . preg_quote($method, '/') . '\b/', $src)) {
					continue;
				}
				$this->assertDoesNotMatchRegularExpression(
					'/\#\[NoCSRFRequired\]\s*(?:\#\[[^\]]+\]\s*)*public function ' . preg_quote($method, '/') . '\b/s',
					$src,
					"$base::$method must NOT be #[NoCSRFRequired]",
				);
			}
		}
	}

	/**
	 * Completeness tripwire (security_contract_list_drift): every
	 * POST/PUT/PATCH/DELETE route in appinfo/routes.php must be pinned in
	 * self::MUTATING, so a new mutating route cannot silently escape the
	 * NoCSRFRequired regression check.
	 */
	public function testEveryMutatingRouteIsPinned(): void
	{
		$routes = (string)file_get_contents(dirname(__DIR__, 3) . '/appinfo/routes.php');
		$missing = [];
		foreach (explode("\n", $routes) as $line) {
			if (!preg_match("/'name'\s*=>\s*'([a-zA-Z]+)#([a-zA-Z]+)'/", $line, $nm)) {
				continue;
			}
			if (!preg_match("/'verb'\s*=>\s*'(POST|PUT|PATCH|DELETE)'/", $line)) {
				continue;
			}
			if ($nm[1] === 'mobile') {
				continue; // PublicPage + device-token surface, CSRF-exempt by design
			}
			if (!in_array($nm[2], self::MUTATING, true)) {
				$missing[] = $nm[1] . '#' . $nm[2];
			}
		}
		self::assertSame(
			[],
			$missing,
			'mutating-verb routes missing from self::MUTATING: ' . implode(', ', $missing),
		);
	}

	public function testLabelAndPageGetsRemainCsrfExempt(): void
	{
		$page = (string)file_get_contents(dirname(__DIR__, 3) . '/lib/Controller/PageController.php');
		$this->assertMatchesRegularExpression(
			'/\#\[NoCSRFRequired\]\s*(?:\#\[[^\]]+\]\s*)*public function dashboard\b/s',
			$page,
		);
		$item = (string)file_get_contents(dirname(__DIR__, 3) . '/lib/Controller/ItemController.php');
		$this->assertMatchesRegularExpression(
			'/\#\[NoCSRFRequired\]\s*(?:\#\[[^\]]+\]\s*)*public function labelPrint\b/s',
			$item,
		);
	}
}
