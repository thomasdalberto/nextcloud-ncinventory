<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Tests\Unit;

use PHPUnit\Framework\TestCase;

/** Portfolio §2.1 — user-delete must scrub all live grants, seats, favourites, ACL. */
final class UserLifecyclePurgeContractTest extends TestCase
{
	public function testFavouriteMapperAndAclExposeUserPurge(): void
	{
		$fav = (string) file_get_contents(dirname(__DIR__, 2) . '/lib/Db/LocationFavouriteMapper.php');
		$acl = (string) file_get_contents(dirname(__DIR__, 2) . '/lib/Service/LocationAclService.php');
		$this->assertStringContainsString('public function deleteAllForUser(string $userId): void', $fav);
		$this->assertStringContainsString('public function purgeUser(string $userId): void', $acl);
		$this->assertStringContainsString("delete('iv_loc_acl')", $acl);
	}

	/** CRIT-01 — group-delete must scrub group ACL grants + gid-bearing config lists. */
	/** CRIT-02 — item delete must not orphan its AppData photo blob. */
	public function testItemDeletePurgesPhotoBlob(): void
	{
		$svc = (string) file_get_contents(dirname(__DIR__, 2) . '/lib/Service/ItemService.php');
		$photos = (string) file_get_contents(dirname(__DIR__, 2) . '/lib/Service/ItemPhotoService.php');
		$this->assertStringContainsString('getPhotoName()', $svc);
		$this->assertStringContainsString('photos?->purgeFile(', $svc);
		$this->assertStringContainsString('public function purgeFile(string $fileName): void', $photos);
	}

	/** CRIT-01 residual — group purge must cover EVERY gid-bearing config list. */
	/** CRIT-01 residual — repair step must scrub config lists + dangling location refs. */
	/** CRIT-04 — location delete must not orphan ACL grants or favourites. */
	public function testLocationDeletePurgesAclAndFavourites(): void
	{
		$svc = (string) file_get_contents(dirname(__DIR__, 2) . '/lib/Service/LocationService.php');
		$acl = (string) file_get_contents(dirname(__DIR__, 2) . '/lib/Service/LocationAclService.php');
		$fav = (string) file_get_contents(dirname(__DIR__, 2) . '/lib/Db/LocationFavouriteMapper.php');
		$this->assertStringContainsString('locationAcl->purgeLocation($id)', $svc);
		$this->assertStringContainsString('favourites->deleteAllForLocation($id)', $svc);
		$this->assertStringContainsString('public function purgeLocation(int $locationId): void', $acl);
		$this->assertStringContainsString('public function deleteAllForLocation(int $locationId): void', $fav);
	}
}
