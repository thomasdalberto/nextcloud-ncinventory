<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Service;

use OCA\InventoryCheck\AppInfo\Application;
use OCA\InventoryCheck\Exception\ValidationException;
use OCA\InventoryCheck\Service\WorkspaceSettings;

/**
 * Wave C1: optional fixed-scale-3 fractional quantities.
 *
 * Storage is always integer. When scale=0, storage == display (whole units).
 * When scale=3, storage is milli-units (display × 1000). Enable is one-way.
 */
final class QtyScale
{
	public const KEY = 'qty_scale';
	public const SCALE_INT = 0;
	public const SCALE_MILLI = 3;
	public const FACTOR = 1000;
	/** Max display quantity (whole or fractional) accepted from clients. */
	public const MAX_DISPLAY = 1_000_000;

	public static function current(WorkspaceSettings $settings): int
	{
		$raw = $settings->get(self::KEY, '0');
		return $raw === '3' ? self::SCALE_MILLI : self::SCALE_INT;
	}

	public static function isFractional(WorkspaceSettings $settings): bool
	{
		return self::current($settings) === self::SCALE_MILLI;
	}

	public static function factor(WorkspaceSettings $settings): int
	{
		return self::isFractional($settings) ? self::FACTOR : 1;
	}

	/**
	 * One physical/serial unit in storage ints (1 when scale=0, 1000 when scale=3).
	 */
	public static function serialUnit(WorkspaceSettings $settings): int
	{
		return self::factor($settings);
	}

	/** Absolute max storage int (= MAX_DISPLAY × factor). */
	public static function maxStorage(WorkspaceSettings $settings): int
	{
		return self::MAX_DISPLAY * self::factor($settings);
	}

	/**
	 * Parse a client-facing qty into storage int.
	 *
	 * Client display values may not exceed {@see MAX_DISPLAY} (1e6 whole units,
	 * or 1e6.000 when fractional). Without this check, adjust mode=set and
	 * inventur counts could post a 2e9 movement in one request and bypass S1.
	 */
	public static function toStorage(WorkspaceSettings $settings, mixed $display): int
	{
		if (self::current($settings) === self::SCALE_INT) {
			if (is_int($display)) {
				self::assertStorageWithinClientMax($settings, $display);
				return $display;
			}
			if (is_float($display) && is_finite($display) && floor($display) === $display) {
				$storage = (int)$display;
				self::assertStorageWithinClientMax($settings, $storage);
				return $storage;
			}
			$raw = trim((string)$display);
			if ($raw === '' || preg_match('/^-?\d+$/', $raw) !== 1) {
				throw new ValidationException('invalid_qty', '', [['field' => 'qty', 'code' => 'invalid_qty']]);
			}
			$storage = (int)$raw;
			self::assertStorageWithinClientMax($settings, $storage);
			return $storage;
		}

		$raw = trim((string)$display);
		if ($raw === '' || preg_match('/^-?\d+(\.\d{1,3})?$/', $raw) !== 1) {
			throw new ValidationException('invalid_qty', '', [['field' => 'qty', 'code' => 'invalid_qty']]);
		}
		$negative = str_starts_with($raw, '-');
		if ($negative) {
			$raw = substr($raw, 1);
		}
		[$whole, $frac] = array_pad(explode('.', $raw, 2), 2, '');
		$frac = str_pad($frac, 3, '0');
		$milli = ((int)$whole) * self::FACTOR + (int)$frac;
		$storage = $negative ? -$milli : $milli;
		self::assertStorageWithinClientMax($settings, $storage);
		return $storage;
	}

	private static function assertStorageWithinClientMax(WorkspaceSettings $settings, int $storage): void
	{
		$max = self::maxStorage($settings);
		if ($storage > $max || $storage < -$max) {
			throw new ValidationException('invalid_qty', '', [['field' => 'qty', 'code' => 'invalid_qty']]);
		}
	}

	/**
	 * Convert storage int to client-facing display (int or decimal string).
	 */
	public static function toDisplay(WorkspaceSettings $settings, int $storage): int|string
	{
		if (self::current($settings) === self::SCALE_INT) {
			return $storage;
		}
		$neg = $storage < 0;
		$abs = abs($storage);
		$whole = intdiv($abs, self::FACTOR);
		$frac = $abs % self::FACTOR;
		$out = $frac === 0
			? (string)$whole
			: rtrim(rtrim(sprintf('%d.%03d', $whole, $frac), '0'), '.');
		return $neg ? '-' . $out : $out;
	}

	/** @param array<string, mixed> $item */
	public static function formatItem(array $item, WorkspaceSettings $settings): array
	{
		if (array_key_exists('reorderLevel', $item) && is_int($item['reorderLevel'])) {
			$item['reorderLevel'] = self::toDisplay($settings, $item['reorderLevel']);
		}
		if (array_key_exists('targetStock', $item) && is_int($item['targetStock'])) {
			$item['targetStock'] = self::toDisplay($settings, $item['targetStock']);
		}
		return $item;
	}

	/** @param array<string, mixed> $balance */
	public static function formatBalance(array $balance, WorkspaceSettings $settings): array
	{
		if (array_key_exists('qty', $balance) && is_int($balance['qty'])) {
			$balance['qty'] = self::toDisplay($settings, $balance['qty']);
		}
		return $balance;
	}

	/** @param array<string, mixed> $movement */
	public static function formatMovement(array $movement, WorkspaceSettings $settings): array
	{
		foreach (['qtyDelta', 'qtyAfter'] as $key) {
			if (array_key_exists($key, $movement) && is_int($movement[$key])) {
				$movement[$key] = self::toDisplay($settings, $movement[$key]);
			}
		}
		return $movement;
	}

	/** @param array<string, mixed> $row */
	public static function formatLowStock(array $row, WorkspaceSettings $settings): array
	{
		foreach (['totalQty', 'qty', 'reorderLevel', 'suggestedQty', 'targetStock'] as $key) {
			if (array_key_exists($key, $row) && is_int($row[$key])) {
				$row[$key] = self::toDisplay($settings, $row[$key]);
			}
		}
		return $row;
	}

	/** @param array<string, mixed> $line */
	public static function formatCycleLine(array $line, WorkspaceSettings $settings): array
	{
		foreach (['systemQty', 'qtyCounted', 'currentQty'] as $key) {
			if (array_key_exists($key, $line) && is_int($line[$key])) {
				$line[$key] = self::toDisplay($settings, $line[$key]);
			}
		}
		return $line;
	}
}
