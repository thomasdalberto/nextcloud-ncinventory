<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Portfolio rule (planning/check-productivity-suite/ACCESS-AND-DIRECTORY-PICKERS.md
 * §1 "Never ask humans to type raw IDs"): humans must never be asked to type a
 * raw Nextcloud user/group id. Settings → Access/Office/Notifications, the
 * per-location ACL subject, and the mobile seat assignment must resolve ids
 * exclusively via search + pick chips. Pins js/app.js so a free-text
 * "Nextcloud user id" / "User id or group id" field (or a raw-id textarea)
 * cannot silently reappear.
 */
final class DirectoryPickerContractTest extends TestCase
{
	private function appJs(): string
	{
		return (string) file_get_contents(dirname(__DIR__, 2) . '/js/app.js');
	}

	public function testSettingsNeverAsksForARawUserOrGroupIdLabel(): void
	{
		$js = $this->appJs();
		self::assertStringNotContainsString('Nextcloud user id', $js);
		self::assertStringNotContainsString('User id or group id', $js);
		self::assertStringNotContainsString('one per line', $js, 'Raw comma/newline-separated id lists must not reappear.');
	}

	public function testAccessOfficeAndNotifyListsAreNoLongerRawIdTextareas(): void
	{
		$js = $this->appJs();
		foreach ([
			'iv-office-users', 'iv-office-groups',
			'iv-allowed-users', 'iv-allowed-groups',
			'iv-app-admins',
			'iv-notify-users', 'iv-notify-groups',
		] as $legacyId) {
			self::assertStringNotContainsString(
				"id: '" . $legacyId . "'",
				$js,
				$legacyId . ' must not exist as a raw-id form field id anymore.',
			);
		}
	}

	public function testLocationAclSubjectIsNoLongerAFreeTextIdInput(): void
	{
		$js = $this->appJs();
		self::assertStringNotContainsString("id: 'iv-acl-subject-id'", $js);
		self::assertDoesNotMatchRegularExpression(
			"/aclSubjectId\s*=\s*el\('input',\s*\{\s*type:\s*'text'/",
			$js,
		);
	}

	public function testMobileSeatAssignmentIsNoLongerAFreeTextIdInput(): void
	{
		$js = $this->appJs();
		self::assertStringNotContainsString("id: 'iv-seat-uid'", $js);
		self::assertDoesNotMatchRegularExpression(
			"/seatUid\s*=\s*el\('input',\s*\{\s*type:\s*'text'/",
			$js,
		);
	}

	public function testDirectoryPickerIsWiredForEveryRawIdSurface(): void
	{
		$js = $this->appJs();
		self::assertSame(
			9,
			substr_count($js, 'createIdPicker('),
			'Expected 1 factory function definition + 8 call sites: workspace invite, '
				. 'allowedUsers, allowedGroups, appAdmins, notifyUsers, notifyGroups, ACL subject, mobile seat.',
		);
		self::assertStringContainsString('allowedUsersPicker = createIdPicker(', $js);
		self::assertStringContainsString('allowedGroupsPicker = createIdPicker(', $js);
		self::assertStringContainsString('appAdminsPicker = createIdPicker(', $js);
		self::assertStringContainsString('notifyUsersPicker = createIdPicker(', $js);
		self::assertStringContainsString('notifyGroupsPicker = createIdPicker(', $js);
		self::assertStringContainsString('aclSubjectPicker = createIdPicker(', $js);
		self::assertStringContainsString('seatUidPicker = createIdPicker(', $js);
	}

	public function testPickerHasNoManualOrDirectEntryFallback(): void
	{
		$js = $this->appJs();
		self::assertStringNotContainsString('allowDirectEntry', $js);
		self::assertStringNotContainsString('Manual entry', $js);
	}

	public function testPickerSearchInputIsASearchBoxWithComboboxSemantics(): void
	{
		$js = $this->appJs();
		self::assertMatchesRegularExpression("/type:\s*'search'/", $js);
		self::assertStringContainsString("role: 'combobox'", $js);
		self::assertStringContainsString("role: 'listbox'", $js);
		self::assertStringContainsString("'aria-autocomplete': 'list'", $js);
	}

	public function testPickerCommitsOnlySearchResolvedIds(): void
	{
		// getIds()/getId() must read from the picker's internal `state` (built only
		// from search results / initial server ids) — never from raw input.value.
		$js = $this->appJs();
		self::assertStringContainsString(
			'function getIds() { return state.map(function (s) { return s.id; }); }',
			$js,
		);
		self::assertStringNotContainsString('seatUid.value', $js);
		self::assertStringNotContainsString('aclSubjectId.value', $js);
	}

	public function testHintCopyTellsAdminsToSearchAndPickNotType(): void
	{
		$js = $this->appJs();
		self::assertStringContainsString('Never type a raw user id', $js);
	}

	public function testDirectorySearchUrlsAreWiredIntoPickers(): void
	{
		$js = $this->appJs();
		self::assertStringContainsString('ctx.urls.api.directorySearchUsers', $js);
		self::assertStringContainsString('ctx.urls.api.directorySearchGroups', $js);
	}
}
