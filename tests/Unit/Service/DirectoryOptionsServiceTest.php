<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Tests\Unit\Service;

use OCA\InventoryCheck\Service\DirectoryOptionsService;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Directory search backing the Settings pickers (portfolio ACCESS-AND-DIRECTORY-PICKERS.md
 * §1): search + pick only. This service only narrows candidates for humans —
 * every write path (ConfigController, ScannerService, LocationAclService)
 * re-validates the committed id independently.
 */
final class DirectoryOptionsServiceTest extends TestCase
{
	/** @var IUserManager&MockObject */
	private IUserManager $userManager;
	/** @var IGroupManager&MockObject */
	private IGroupManager $groupManager;
	private DirectoryOptionsService $service;

	protected function setUp(): void
	{
		parent::setUp();
		$this->userManager = $this->createMock(IUserManager::class);
		$this->groupManager = $this->createMock(IGroupManager::class);
		$this->service = new DirectoryOptionsService($this->userManager, $this->groupManager);
	}

	private function makeUser(string $uid, string $displayName = ''): IUser
	{
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$user->method('getDisplayName')->willReturn($displayName);
		return $user;
	}

	private function makeGroup(string $gid, string $displayName = ''): IGroup
	{
		$group = $this->createMock(IGroup::class);
		$group->method('getGID')->willReturn($gid);
		$group->method('getDisplayName')->willReturn($displayName);
		return $group;
	}

	public function testSearchUsersReturnsEmptyForBlankQuery(): void
	{
		$this->userManager->expects($this->never())->method('search');
		$this->assertSame([], $this->service->searchUsers('   '));
	}

	public function testSearchUsersReturnsEmptyForSingleCharacterQuery(): void
	{
		$this->userManager->expects($this->never())->method('search');
		$this->userManager->expects($this->never())->method('searchDisplayName');
		$this->assertSame([], $this->service->searchUsers('a'));
	}

	public function testSearchUsersReturnsEmptyForZeroLimit(): void
	{
		$this->userManager->expects($this->never())->method('search');
		$this->assertSame([], $this->service->searchUsers('anna', 0));
	}

	public function testSearchUsersMapsIdAndDisplayNameAndSorts(): void
	{
		$this->userManager->method('search')->with('an', 20, 0)->willReturn([
			$this->makeUser('zed', 'Anna Zed'),
			$this->makeUser('anna', 'Anna Alpha'),
		]);
		$this->userManager->method('searchDisplayName')->with('an', 20, 0)->willReturn([]);
		$result = $this->service->searchUsers('an');
		$this->assertSame([
			['id' => 'anna', 'displayName' => 'Anna Alpha'],
			['id' => 'zed', 'displayName' => 'Anna Zed'],
		], $result);
	}

	public function testSearchUsersMergesDisplayNameHits(): void
	{
		$this->userManager->method('search')->with('an', 20, 0)->willReturn([
			$this->makeUser('zed', 'Anna Zed'),
		]);
		$this->userManager->method('searchDisplayName')->with('an', 20, 0)->willReturn([
			$this->makeUser('anna', 'Anna Alpha'),
			$this->makeUser('zed', 'Anna Zed'),
		]);
		$result = $this->service->searchUsers('an');
		$this->assertSame([
			['id' => 'anna', 'displayName' => 'Anna Alpha'],
			['id' => 'zed', 'displayName' => 'Anna Zed'],
		], $result);
	}

	public function testSearchUsersFallsBackToUidWhenDisplayNameBlank(): void
	{
		$this->userManager->method('search')->willReturn([$this->makeUser('anna', '')]);
		$this->userManager->method('searchDisplayName')->willReturn([]);
		$this->assertSame([['id' => 'anna', 'displayName' => 'anna']], $this->service->searchUsers('an'));
	}

	public function testSearchUsersSkipsNullAndEmptyUid(): void
	{
		$this->userManager->method('search')->willReturn([null, $this->makeUser('', 'ghost')]);
		$this->userManager->method('searchDisplayName')->willReturn([]);
		$this->assertSame([], $this->service->searchUsers('an'));
	}

	public function testSearchUsersLimitIsCappedAt50(): void
	{
		$this->userManager->expects($this->once())->method('search')->with('an', 50, 0)->willReturn([]);
		$this->userManager->expects($this->once())->method('searchDisplayName')->with('an', 50, 0)->willReturn([]);
		$this->service->searchUsers('an', 999);
	}

	public function testSearchGroupsReturnsEmptyForBlankQuery(): void
	{
		$this->groupManager->expects($this->never())->method('search');
		$this->assertSame([], $this->service->searchGroups(''));
	}

	public function testSearchGroupsMapsIdAndDisplayNameAndSorts(): void
	{
		$this->groupManager->method('search')->with('of', 20)->willReturn([
			$this->makeGroup('office-b', 'Office B'),
			$this->makeGroup('office-a', 'Office A'),
		]);
		$this->assertSame([
			['id' => 'office-a', 'displayName' => 'Office A'],
			['id' => 'office-b', 'displayName' => 'Office B'],
		], $this->service->searchGroups('of'));
	}

	public function testSearchGroupsFallsBackToGidWhenDisplayNameBlank(): void
	{
		$this->groupManager->method('search')->willReturn([$this->makeGroup('office', '')]);
		$this->assertSame([['id' => 'office', 'displayName' => 'office']], $this->service->searchGroups('of'));
	}

	public function testSearchGroupsSkipsNullAndEmptyGid(): void
	{
		$this->groupManager->method('search')->willReturn([null, $this->makeGroup('', 'ghost')]);
		$this->assertSame([], $this->service->searchGroups('of'));
	}
}
