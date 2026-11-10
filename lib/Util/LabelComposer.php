<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Util;

/** Composes independently generated code/text elements into a printable item label. */
final class LabelComposer
{
	public const ELEMENTS = ['qr', 'barcode', 'name', 'sku', 'code', 'branding'];
	public const DEFAULT_ELEMENTS = ['qr', 'barcode', 'name', 'sku', 'code', 'branding'];

	/** @param list<string> $elements */
	public static function item(string $scanCode, string $sku, string $name, array $elements = []): string
	{
		$scanCode = trim($scanCode);
		if ($scanCode === '') { throw new \InvalidArgumentException('scan_code_required'); }
		$elements = self::normalize($elements);
		$esc = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_XML1, 'UTF-8');
		$rows = [];
		foreach ($elements as $element) {
			switch ($element) {
				case 'qr':
					[$inner, $matrixW, $matrixH] = CodeElements::qrMarkup($scanCode);
					$size = 150.0;
					$scale = $size / max($matrixW, $matrixH);
					$rows[] = ['height' => 158, 'svg' => '<g transform="translate(' . ((320.0 - $size) / 2.0) . ' 4) scale(' . $scale . ')" fill="#111">' . $inner . '</g>'];
					break;
				case 'barcode':
					$rows[] = ['height' => 54, 'svg' => Code128Svg::barsGroup($scanCode, 20, 7, 280, 40)];
					break;
				case 'name': $rows[] = ['height' => 28, 'svg' => self::text($esc(mb_substr($name, 0, 64)), 14, '#111')]; break;
				case 'sku': $rows[] = ['height' => 26, 'svg' => self::text('SKU ' . $esc($sku), 12, '#333', true)]; break;
				case 'code': $rows[] = ['height' => 30, 'svg' => self::text($esc($scanCode), 16, '#111', true, true)]; break;
				case 'branding': $rows[] = ['height' => 24, 'svg' => self::text('InventoryCheck', 11, '#555')]; break;
			}
		}
		if ($rows === []) { throw new \InvalidArgumentException('label_elements_required'); }
		$height = array_sum(array_column($rows, 'height'));
		$y = 0;
		$parts = [];
		foreach ($rows as $row) {
			$parts[] = '<g transform="translate(0 ' . $y . ')">' . $row['svg'] . '</g>';
			$y += $row['height'];
		}
		return '<?xml version="1.0" encoding="UTF-8"?>'
			. '<svg xmlns="http://www.w3.org/2000/svg" width="320" height="' . $height . '" viewBox="0 0 320 ' . $height . '" role="img" aria-label="' . $esc($scanCode) . '">'
			. '<rect width="320" height="' . $height . '" fill="#fff"/>' . implode('', $parts) . '</svg>';
	}

	/** @param list<string> $elements @return list<string> */
	private static function normalize(array $elements): array
	{
		if ($elements === []) { return self::DEFAULT_ELEMENTS; }
		return array_values(array_unique(array_filter($elements, static fn ($v): bool => is_string($v) && in_array($v, self::ELEMENTS, true))));
	}

	private static function text(string $value, int $size, string $color, bool $mono = false, bool $bold = false): string
	{
		$family = $mono ? 'DejaVu Sans Mono, Liberation Mono, monospace' : 'DejaVu Sans, Liberation Sans, Arial, sans-serif';
		$weight = $bold ? ' font-weight="700"' : '';
		return '<text x="160" y="19" text-anchor="middle" fill="' . $color . '" font-family="' . $family . '" font-size="' . $size . '"' . $weight . '>' . $value . '</text>';
	}
}
