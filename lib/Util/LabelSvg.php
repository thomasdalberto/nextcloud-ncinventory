<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Util;

use splitbrain\phpQRCode\QRCode;

/**
 * Printable item label: QR + Code 128 of scan_code + selectable text (SPEC A12 / Wave A5).
 *
 * Both symbologies encode the same plain-string payload so phone cameras (QR)
 * and wedge/laser scanners (Code 128) resolve via the same S8 by-code path.
 */
final class LabelSvg
{
	/**
	 * Build a standalone SVG label (QR + Code 128 + human-readable code + name).
	 */
	public static function forItem(string $scanCode, string $sku, string $name, array $elements = []): string
	{
		return LabelComposer::item($scanCode, $sku, $name, $elements);
	}

	/**
	 * Wave D1: location/bin label — QR + Code 128 of location.code.
	 */
	public static function forLocation(string $code, string $name, string $kind = 'other'): string
	{
		$code = trim($code);
		if ($code === '') {
			throw new \InvalidArgumentException('location_code_required');
		}

		$qrSvg = QRCode::svg($code, ['s' => 'qrm']);
		if (!preg_match('/viewBox="0 0 (\d+) (\d+)"/', $qrSvg, $m)) {
			throw new \RuntimeException('qr_svg_malformed');
		}
		$matrix = (int)$m[1];
		$inner = preg_replace('/^[\s\S]*?<svg[^>]*>/', '', $qrSvg) ?? '';
		$inner = preg_replace('/<\/svg>\s*$/', '', $inner) ?? '';

		$escCode = htmlspecialchars($code, ENT_QUOTES | ENT_XML1, 'UTF-8');
		$escKind = htmlspecialchars($kind, ENT_QUOTES | ENT_XML1, 'UTF-8');
		$escName = htmlspecialchars(mb_substr($name, 0, 64), ENT_QUOTES | ENT_XML1, 'UTF-8');

		$target = 168.0;
		$scale = $target / max(1, $matrix);
		$tx = (320.0 - $target) / 2.0;
		$ty = 16.0;

		$barcode = Code128Svg::barsGroup($code, 20.0, 196.0, 280.0, 44.0);

		return '<?xml version="1.0" encoding="UTF-8"?>'
			. '<svg xmlns="http://www.w3.org/2000/svg" width="320" height="400" viewBox="0 0 320 400"'
			. ' role="img" aria-label="' . $escCode . '">'
			. '<title>QR and barcode for location ' . $escCode . '</title>'
			. '<rect width="320" height="400" fill="#ffffff"/>'
			. '<g id="iv-label-qr" transform="translate(' . $tx . ' ' . $ty . ') scale(' . $scale . ')" fill="#111111">'
			. $inner
			. '</g>'
			. $barcode
			. '<text x="160" y="268" text-anchor="middle" fill="#111111"'
			. ' font-family="DejaVu Sans, Liberation Sans, Arial, sans-serif" font-size="14">'
			. $escName
			. '</text>'
			. '<text x="160" y="292" text-anchor="middle" fill="#333333"'
			. ' font-family="DejaVu Sans Mono, Liberation Mono, monospace" font-size="12">'
			. $escKind
			. '</text>'
			. '<text id="iv-label-code" x="160" y="322" text-anchor="middle" fill="#111111"'
			. ' font-family="DejaVu Sans Mono, Liberation Mono, monospace" font-size="16" font-weight="700">'
			. $escCode
			. '</text>'
			. '<text x="160" y="348" text-anchor="middle" fill="#555555"'
			. ' font-family="DejaVu Sans, Liberation Sans, Arial, sans-serif" font-size="11">'
			. 'Location · QR · Code 128'
			. '</text>'
			. '<text x="160" y="372" text-anchor="middle" fill="#555555"'
			. ' font-family="DejaVu Sans, Liberation Sans, Arial, sans-serif" font-size="11">'
			. 'InventoryCheck'
			. '</text>'
			. '</svg>';
	}
}
