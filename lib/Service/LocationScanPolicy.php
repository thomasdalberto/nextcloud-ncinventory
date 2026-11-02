<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Service;

use OCA\InventoryCheck\AppInfo\Application;
use OCA\InventoryCheck\Db\LocationMapper;
use OCA\InventoryCheck\Exception\ValidationException;
use OCA\InventoryCheck\Service\WorkspaceSettings;

/**
 * Wave D8: optional require_location_scan — scan/issue/transfer must confirm location code.
 */
final class LocationScanPolicy
{
	public const KEY_REQUIRE_LOCATION_SCAN = 'require_location_scan';

	public static function isRequired(WorkspaceSettings $settings): bool
	{
		return $settings->get(self::KEY_REQUIRE_LOCATION_SCAN, '0') === '1';
	}

	public static function setRequired(WorkspaceSettings $settings, bool $required): void
	{
		$settings->set(self::KEY_REQUIRE_LOCATION_SCAN, $required ? '1' : '0');
	}

	/**
	 * When policy is on, $locationCode must resolve to $locationId (exact code match).
	 * When policy is off, $locationCode is ignored (optional soft check if provided).
	 *
	 * @param string $field JSON field name in the 422 details (locationCode | toLocationCode)
	 * @throws ValidationException location_code_required | location_code_mismatch
	 */
	public static function assertMatches(
		WorkspaceSettings $settings,
		LocationMapper $locations,
		int $locationId,
		?string $locationCode,
		string $field = 'locationCode',
	): void {
		$code = $locationCode !== null ? CodeRules::trim($locationCode) : '';
		$required = self::isRequired($settings);
		$fieldName = $field !== '' ? $field : 'locationCode';

		if (!$required) {
			if ($code === '') {
				return;
			}
			// Soft: if provided, still must match (avoid silent wrong labels).
		} elseif ($code === '') {
			throw new ValidationException('validation_failed', '', [
				['field' => $fieldName, 'code' => 'location_code_required'],
			]);
		}

		$loc = $locations->findById($locationId);
		if (!hash_equals($loc->getCode(), $code)) {
			throw new ValidationException('location_code_mismatch', '', [
				['field' => $fieldName, 'code' => 'location_code_mismatch'],
			]);
		}
	}
}
