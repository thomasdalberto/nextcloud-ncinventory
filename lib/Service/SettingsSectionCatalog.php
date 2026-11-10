<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Service;

use OCP\IL10N;

/** One ordered catalog for workspace settings routes, navigation and page titles. */
final class SettingsSectionCatalog
{
	public const DEFAULT_SECTION = 'members';
	public const SECTIONS = ['members', 'stock', 'notifications', 'quantities', 'location-access', 'connections', 'policies', 'scanners', 'codes'];

	public function isSection(string $section): bool
	{
		return in_array($section, self::SECTIONS, true);
	}

	public static function routeRequirement(): string
	{
		return implode('|', self::SECTIONS);
	}

	public function label(IL10N $l, string $section): string
	{
		return match ($section) {
			'members' => $l->t('Members and invitations'),
			'stock' => $l->t('Stock policy'),
			'notifications' => $l->t('Low stock notifications'),
			'quantities' => $l->t('Fractional quantities'),
			'location-access' => $l->t('Location access'),
			'connections' => $l->t('Connections to other Check apps'),
			'policies' => $l->t('Scan & adjust policies'),
			'scanners' => $l->t('Scanners'),
			'codes' => $l->t('SKU and labels'),
			default => $l->t('Workspace settings'),
		};
	}

	public function navLabel(IL10N $l, string $section): string
	{
		return match ($section) {
			'members' => $l->t('Members'),
			'notifications' => $l->t('Notifications'),
			'quantities' => $l->t('Quantities'),
			'location-access' => $l->t('Locations'),
			'connections' => $l->t('Connections'),
			'policies' => $l->t('Policies'),
			default => $this->label($l, $section),
		};
	}

	public function help(IL10N $l, string $section): string
	{
		return match ($section) {
			'members' => $l->t('Invite colleagues and manage Read, Write and Admin roles in this workspace.'),
			'stock' => $l->t('Control whether this workspace may have negative stock.'),
			'notifications' => $l->t('Choose workspace members to notify when stock drops below its reorder level. By default, notify the owner and admins.'),
			'quantities' => $l->t('Enable up to three decimal places for this workspace. This cannot be undone.'),
			'location-access' => $l->t('Optionally limit Read members and scanners to granted locations in this workspace.'),
			'connections' => $l->t('Configure stock integrations and the default issue location for this workspace.'),
			'policies' => $l->t('Set reason code and location scan requirements for this workspace.'),
			'scanners' => $l->t('Pair and manage scanners that can access this workspace.'),
			'codes' => $l->t('Choose automatic SKU generation and the label shown for item codes.'),
			default => '',
		};
	}
}
