<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Service;

use OCA\InventoryCheck\AppInfo\Application;
use OCA\InventoryCheck\Exception\ValidationException;
use OCA\InventoryCheck\Service\WorkspaceSettings;

/**
 * Wave D3: fixed adjust reason taxonomy (DE/EN labels).
 *
 * Codes are stable API tokens; labels are UI-only.
 */
final class ReasonCodes
{
	public const KEY_REQUIRE_ADJUST_REASON = 'require_adjust_reason';

	/** @var list<string> */
	public const CODES = [
		'inventur',
		'damage',
		'loss',
		'found',
		'correction',
		'other',
	];

	/**
	 * Default ON for new installs (ensurer seeds '1').
	 * Missing key → off (upgrade-safe until admin/ensurer sets it).
	 */
	public static function isRequired(WorkspaceSettings $settings): bool
	{
		$v = $settings->get(self::KEY_REQUIRE_ADJUST_REASON, '1');
		if ($v === '') {
			return false;
		}
		return $v === '1';
	}

	public static function setRequired(WorkspaceSettings $settings, bool $required): void
	{
		$settings->set(self::KEY_REQUIRE_ADJUST_REASON, $required ? '1' : '0');
	}

	public static function isValid(?string $code): bool
	{
		if ($code === null || $code === '') {
			return false;
		}
		return in_array(strtolower(trim($code)), self::CODES, true);
	}

	/**
	 * Normalize or null. Empty → null. Invalid → ValidationException.
	 */
	public static function normalize(?string $code): ?string
	{
		if ($code === null) {
			return null;
		}
		$trimmed = strtolower(trim($code));
		if ($trimmed === '') {
			return null;
		}
		if (!in_array($trimmed, self::CODES, true)) {
			throw new ValidationException('validation_failed', '', [
				['field' => 'reasonCode', 'code' => 'invalid_reason_code'],
			]);
		}
		return $trimmed;
	}

	/**
	 * Enforce policy when posting an adjust.
	 *
	 * @throws ValidationException
	 */
	public static function requireForAdjust(WorkspaceSettings $settings, ?string $code): ?string
	{
		$normalized = self::normalize($code);
		if (self::isRequired($settings) && $normalized === null) {
			throw new ValidationException('validation_failed', '', [
				['field' => 'reasonCode', 'code' => 'reason_code_required'],
			]);
		}
		return $normalized;
	}

	/**
	 * @return list<array{code: string, labelEn: string, labelDe: string}>
	 */
	public static function catalog(): array
	{
		return [
			['code' => 'inventur', 'labelEn' => 'Stocktake / inventur', 'labelDe' => 'Inventur'],
			['code' => 'damage', 'labelEn' => 'Damage', 'labelDe' => 'Beschädigung'],
			['code' => 'loss', 'labelEn' => 'Loss / theft', 'labelDe' => 'Verlust / Diebstahl'],
			['code' => 'found', 'labelEn' => 'Found stock', 'labelDe' => 'Gefundener Bestand'],
			['code' => 'correction', 'labelEn' => 'Correction', 'labelDe' => 'Korrektur'],
			['code' => 'other', 'labelEn' => 'Other', 'labelDe' => 'Sonstiges'],
		];
	}
}
