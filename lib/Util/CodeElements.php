<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Util;

use splitbrain\phpQRCode\QRCode;

/** Standalone, reusable SVG elements used by label composition and API clients. */
final class CodeElements
{
	public static function qr(string $payload): string
	{
		$payload = trim($payload);
		if ($payload === '') { throw new \InvalidArgumentException('qr_payload_required'); }
		return QRCode::svg($payload, ['s' => 'qrm']);
	}

	public static function barcode(string $payload): string
	{
		$payload = trim($payload);
		if ($payload === '') { throw new \InvalidArgumentException('barcode_payload_required'); }
		$bars = Code128Svg::barsGroup($payload, 12, 12, 376, 76, 'iv-code128');
		return '<?xml version="1.0" encoding="UTF-8"?>'
			. '<svg xmlns="http://www.w3.org/2000/svg" width="400" height="100" viewBox="0 0 400 100" role="img" aria-label="Code 128 ' . htmlspecialchars($payload, ENT_QUOTES | ENT_XML1, 'UTF-8') . '">'
			. '<rect width="400" height="100" fill="#fff"/>' . $bars . '</svg>';
	}

	/** Inner SVG markup for placing a QR within a composed label. */
	public static function qrMarkup(string $payload): array
	{
		$svg = self::qr($payload);
		if (!preg_match('/viewBox="0 0 (\d+) (\d+)"/', $svg, $m)) { throw new \RuntimeException('qr_svg_malformed'); }
		$inner = preg_replace('/^[\s\S]*?<svg[^>]*>/', '', $svg) ?? '';
		$inner = preg_replace('/<\/svg>\s*$/', '', $inner) ?? '';
		return [$inner, (int)$m[1], (int)$m[2]];
	}
}
