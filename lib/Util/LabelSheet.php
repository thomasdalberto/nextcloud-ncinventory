<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Util;

/**
 * Multi-label A4 sheet (≥12 labels) built from {@see LabelSvg} tiles (Wave A5).
 * Chunks into pages of {@see capacity()} so print gets real page breaks.
 */
final class LabelSheet
{
	public const MIN_LABELS_PER_A4 = 12;
	public const COLS = 3;
	public const ROWS = 4;
	/** Hard cap on one GET /labels request — each tile encodes QR + Code 128. */
	public const MAX_BULK_LABELS = 500;

	/**
	 * @param list<array{scanCode: string, sku: string, name: string}> $items
	 */
	public static function html(array $items, array $elements = []): string
	{
		if ($items === []) {
			throw new \InvalidArgumentException('no_items');
		}
		$capacity = self::capacity();
		$pages = array_chunk($items, $capacity);
		$out = [];
		foreach ($pages as $pageItems) {
			$tiles = [];
			foreach ($pageItems as $item) {
				$svg = LabelSvg::forItem(
					(string)$item['scanCode'],
					(string)$item['sku'],
					(string)$item['name'],
					$elements,
				);
				$svg = preg_replace('/^<\?xml[^?]*\?>\s*/', '', $svg) ?? $svg;
				$tiles[] = '<div class="iv-label-tile" role="group" aria-label="'
					. htmlspecialchars((string)$item['scanCode'], ENT_QUOTES | ENT_HTML5, 'UTF-8')
					. '">' . $svg . '</div>';
			}
			$out[] = '<section class="iv-label-sheet__page" aria-label="A4">'
				. implode("\n", $tiles)
				. '</section>';
		}
		return implode("\n", $out);
	}

	/**
	 * Wave D1: bulk location labels — maps to {@see LabelSvg::forLocation}.
	 *
	 * @param list<array{code: string, name: string, kind?: string}> $locations
	 */
	public static function htmlLocations(array $locations): string
	{
		if ($locations === []) {
			throw new \InvalidArgumentException('no_locations');
		}
		$capacity = self::capacity();
		$pages = array_chunk($locations, $capacity);
		$out = [];
		foreach ($pages as $pageLocs) {
			$tiles = [];
			foreach ($pageLocs as $loc) {
				$code = (string)$loc['code'];
				$svg = LabelSvg::forLocation(
					$code,
					(string)$loc['name'],
					(string)($loc['kind'] ?? 'other'),
				);
				$svg = preg_replace('/^<\?xml[^?]*\?>\s*/', '', $svg) ?? $svg;
				$tiles[] = '<div class="iv-label-tile" role="group" aria-label="'
					. htmlspecialchars($code, ENT_QUOTES | ENT_HTML5, 'UTF-8')
					. '">' . $svg . '</div>';
			}
			$out[] = '<section class="iv-label-sheet__page" aria-label="A4">'
				. implode("\n", $tiles)
				. '</section>';
		}
		return implode("\n", $out);
	}

	public static function capacity(): int
	{
		return self::COLS * self::ROWS;
	}
}
