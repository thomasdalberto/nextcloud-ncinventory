<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Tests\Integration;

use OCA\InventoryCheck\AppInfo\Application;
use OCA\InventoryCheck\Activity\Provider;
use OCA\InventoryCheck\Activity\WorkspaceSubjects;
use OCA\InventoryCheck\Controller\WorkspaceController;
use OCA\InventoryCheck\Service\WorkspaceInvitationLinks;
use OCA\InventoryCheck\Service\AccessControlService;
use OCA\InventoryCheck\Service\DirectoryOptionsService;
use OCA\InventoryCheck\Db\BalanceMapper;
use OCA\InventoryCheck\Db\CycleLineMapper;
use OCA\InventoryCheck\Db\Item;
use OCA\InventoryCheck\Db\ItemMapper;
use OCA\InventoryCheck\Db\LocationMapper;
use OCA\InventoryCheck\Db\MovementMapper;
use OCA\InventoryCheck\Exception\NotFoundException;
use OCA\InventoryCheck\Exception\PermissionDeniedException;
use OCA\InventoryCheck\Service\CsvExportService;
use OCA\InventoryCheck\Service\CycleCountService;
use OCA\InventoryCheck\Service\ItemService;
use OCA\InventoryCheck\Service\LocationService;
use OCA\InventoryCheck\Service\MovementService;
use OCA\InventoryCheck\Service\ScanIdempotencyService;
use OCA\InventoryCheck\Service\WorkspaceContext;
use OCA\InventoryCheck\Service\WorkspaceService;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\Server;
use OCP\IDBConnection;
use OCP\IRequest;
use OCP\Notification\IManager as NotificationManager;
use OCP\Notification\IAction;
use OCP\Notification\AlreadyProcessedException;
use PHPUnit\Framework\TestCase;

/** Tests the real migrations, SQL, service wiring and permissions on an isolated Nextcloud. @group DB */
final class WorkspaceIsolationIntegrationTest extends TestCase
{
	private $container;
	private WorkspaceService $workspaces;
	private WorkspaceContext $scope;
	private IUserSession $session;
	private IUserManager $users;
	private array $accounts = [];
	private array $created = [];
	private $previousUser;

	protected function setUp(): void
	{
		if (!class_exists(\OC::class) || !isset(\OC::$server)) {
			$this->markTestSkipped('Nextcloud runtime required; see dev/README.md');
		}
		\OC_App::loadApps();
		$this->container = (new Application())->getContainer();
		$this->workspaces = $this->container->get(WorkspaceService::class);
		$this->scope = $this->container->get(WorkspaceContext::class);
		$this->users = Server::get(IUserManager::class);
		$this->session = Server::get(IUserSession::class);
		$this->previousUser = $this->session->getUser();
		foreach (['alice', 'bob', 'carol'] as $name) {
			$uid = 'iv_ws_' . $name . '_' . bin2hex(random_bytes(4));
			$this->accounts[$name] = $this->users->createUser($uid, 'Workspace-Tests-42!');
		}
	}

	protected function tearDown(): void
	{
		if (!isset($this->workspaces)) { return; }
		foreach (array_reverse($this->created) as [$uid, $id]) {
			try { $this->workspaces->delete($uid, $id); } catch (NotFoundException) { }
		}
		$this->session->setUser($this->previousUser);
		foreach ($this->accounts as $user) { $user->delete(); }
	}

	private function create(string $account, string $name): array
	{
		$uid = $this->accounts[$account]->getUID();
		$workspace = $this->workspaces->create($uid, $name);
		$this->created[] = [$uid, $workspace['id']];
		return $workspace;
	}

	private function select(string $account, int $workspaceId): string
	{
		$this->session->setUser($this->accounts[$account]);
		$this->scope->select($workspaceId);
		return $this->accounts[$account]->getUID();
	}

	private function expectMissing(callable $read): void
	{
		try { $read(); self::fail('Another workspace was accessible'); }
		catch (NotFoundException $e) { self::assertNotSame('', $e->getMessage()); }
	}

	private function expectForbidden(callable $operation): void
	{
		try { $operation(); self::fail('Operation unexpectedly allowed'); }
		catch (PermissionDeniedException) { self::assertTrue(true); }
	}

	public function testWorkspaceAdminsManageSettingsAndMembersWithoutOwningTheWorkspace(): void
	{
		$workspace = $this->create('alice', 'Admin policy');
		$other = $this->create('carol', 'Other workspace');
		$owner = $this->accounts['alice']->getUID();
		$admin = $this->accounts['bob']->getUID();
		$reader = $this->accounts['carol']->getUID();
		$this->workspaces->invite($owner, $workspace['id'], $admin, 'admin');
		$this->workspaces->respond($admin, $workspace['id'], true);
		$this->select('bob', $workspace['id']);
		$access = $this->container->get(AccessControlService::class);
		self::assertTrue($access->isWorkspaceAdmin($admin));
		self::assertTrue($access->canWrite($admin));
		$access->setAllowNegativeStock(true);
		self::assertTrue($access->allowNegativeStock());
		$this->workspaces->invite($admin, $workspace['id'], $reader, 'viewer');
		$this->workspaces->respond($reader, $workspace['id'], true);
		self::assertCount(3, $this->workspaces->members($admin, $workspace['id']));
		$this->expectForbidden(fn () => $this->workspaces->delete($admin, $workspace['id']));
		$this->expectMissing(fn () => $this->workspaces->requireAdmin($admin, $other['id']));
		$this->workspaces->changeRole($admin, $workspace['id'], $reader, 'editor');
		$this->select('carol', $workspace['id']);
		self::assertTrue($access->canWrite($reader));
		self::assertFalse($access->isWorkspaceAdmin($reader));
		$this->expectForbidden(fn () => $access->requireWorkspaceAdmin($reader));
		$this->expectForbidden(fn () => $this->workspaces->invite($reader, $workspace['id'], $owner, 'admin'));
		$this->select('carol', $other['id']);
		self::assertFalse($access->allowNegativeStock());
	}

	public function testSettingsStoresDoNotCrossWorkspaceBoundaries(): void
	{
		$first = $this->create('alice', 'First settings');
		$second = $this->create('alice', 'Second settings');
		$settings = $this->container->get(\OCA\InventoryCheck\Service\WorkspaceSettings::class);
		$this->select('alice', $first['id']);
		$settings->set('require_location_scan', '1');
		$settings->set('maint_flange_enabled', '1');
		self::assertTrue(\OCA\InventoryCheck\Service\LocationScanPolicy::isRequired($settings));
		$this->select('alice', $second['id']);
		self::assertFalse(\OCA\InventoryCheck\Service\LocationScanPolicy::isRequired($settings));
		self::assertSame('0', $settings->get('maint_flange_enabled', '0'));
		$settings->set('require_location_scan', '0');
		$this->select('alice', $first['id']);
		self::assertSame('1', $settings->get('require_location_scan'));
	}

	public function testFractionalQuantitiesOnlyRescaleTheSelectedWorkspace(): void
	{
		$first = $this->create('alice', 'Fractional settings');
		$second = $this->create('alice', 'Integer settings');
		$items = $this->container->get(ItemService::class);
		$locations = $this->container->get(LocationService::class);
		$movements = $this->container->get(MovementService::class);
		$settings = $this->container->get(\OCA\InventoryCheck\Service\WorkspaceSettings::class);
		$records = [];
		foreach ([$first, $second] as $workspace) {
			$uid = $this->select('alice', $workspace['id']);
			$item = $items->create($uid, ['sku' => 'SCALE', 'name' => 'Scale item', 'reorderLevel' => 2, 'targetStock' => 5]);
			$location = $locations->create($uid, ['code' => 'LOC-SCALE', 'name' => 'Scale location', 'kind' => 'warehouse']);
			$movements->receive($uid, $item['id'], $location['id'], 4);
			$records[] = $item['id'];
		}
		$this->select('alice', $first['id']);
		$service = $this->container->get(\OCA\InventoryCheck\Service\QtyScaleService::class);
		self::assertTrue($service->enableFractional()['changed']);
		self::assertFalse($service->enableFractional()['changed']);
		self::assertSame(3, \OCA\InventoryCheck\Service\QtyScale::current($settings));
		$item = $this->container->get(ItemMapper::class)->findById($records[0]);
		self::assertSame(2000, $item->getReorderLevel());
		self::assertSame(5000, $item->getTargetStock());
		self::assertSame(4000, $this->container->get(BalanceMapper::class)->sumQtyByItem()[$records[0]]);
		$this->select('alice', $second['id']);
		self::assertSame(0, \OCA\InventoryCheck\Service\QtyScale::current($settings));
		self::assertSame(2, $this->container->get(ItemMapper::class)->findById($records[1])->getReorderLevel());
		self::assertSame(4, $this->container->get(BalanceMapper::class)->sumQtyByItem()[$records[1]]);
	}

	public function testScannerPairingAndMobileAccessDoNotRequireALicense(): void
	{
		$workspace = $this->create('alice', 'Scanner settings');
		$uid = $this->select('alice', $workspace['id']);
		$scanners = $this->container->get(\OCA\InventoryCheck\Service\ScannerService::class);
		$created = $scanners->createDevice($uid, 'Test scanner');
		$paired = $this->container->get(\OCA\InventoryCheck\Service\DevicePairingService::class)->pair($created['pairCode']);
		$device = $scanners->findDeviceByTokenHash($scanners->hashSecret($paired['token']));
		self::assertSame($workspace['id'], $device->getWorkspaceId());
		$mobile = $this->container->get(\OCA\InventoryCheck\Service\MobileAccessService::class);
		$mobile->requireCaller($uid, null);
		$mobile->requireCaller(null, $device);
		self::assertArrayNotHasKey('licensing', $mobile->bootstrap($uid, null));
		self::assertFalse(Server::get(IDBConnection::class)->tableExists('iv_license_state'));
		self::assertFalse(Server::get(IDBConnection::class)->tableExists('iv_mobile_seats'));
	}

	private function invitations(string $uid): array
	{
		$filter = Server::get(NotificationManager::class)->createNotification();
		$filter->setApp(Application::APP_ID)->setUser($uid);
		return array_values(array_filter(Server::get(\OCA\Notifications\Handler::class)->get($filter),
			static fn ($n) => $n->getSubject() === WorkspaceSubjects::INVITED));
	}

	private function activitySubjects(string $uid, int $workspaceId): array
	{
		$qb = Server::get(IDBConnection::class)->getQueryBuilder();
		$result = $qb->select('subject')->from('activity')
			->where($qb->expr()->eq('app', $qb->createNamedParameter(Application::APP_ID)))
			->andWhere($qb->expr()->eq('affecteduser', $qb->createNamedParameter($uid)))
			->andWhere($qb->expr()->eq('object_type', $qb->createNamedParameter('workspace')))
			->andWhere($qb->expr()->eq('object_id', $qb->createNamedParameter($workspaceId)))
			->orderBy('activity_id', 'ASC')->executeQuery();
		$subjects = array_column($result->fetchAll(), 'subject');
		$result->closeCursor();
		return $subjects;
	}

	private function notificationClick(string $account, string $url, array $changes = []): void
	{
		parse_str(parse_url($url, PHP_URL_QUERY), $params);
		$params = array_merge($params, $changes);
		$this->session->setUser($this->accounts[$account]);
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(static fn ($key, $default = null) => [
			'invitationId' => $params['invitation'], 'decision' => $params['decision'], 'token' => $params['invitationToken'],
		][$key] ?? $default);
		$controller = new WorkspaceController($request, $this->container->get(AccessControlService::class),
			$this->workspaces, $this->container->get(DirectoryOptionsService::class), $this->container->get(WorkspaceInvitationLinks::class));
		self::assertSame(200, $controller->notificationAction((int)$params['invitationWorkspace'])->getStatus());
	}

	public function testInvitationNotificationEmailActionsAndAcceptance(): void
	{
		self::assertSame('inventorycheck_workspaces', Server::get(\OCP\Activity\IManager::class)->getSettingById('inventorycheck_workspaces')->getIdentifier());
		$a = $this->create('alice', 'Shared <inventory>');
		$alice = $this->accounts['alice']->getUID();
		$bob = $this->accounts['bob']->getUID();
		$this->accounts['bob']->setEMailAddress('workspace-test@example.test');
		$this->workspaces->invite($alice, $a['id'], $bob, 'viewer');
		self::assertSame([WorkspaceSubjects::CREATED, WorkspaceSubjects::INVITED], $this->activitySubjects($alice, $a['id']));
		self::assertSame([WorkspaceSubjects::INVITED], $this->activitySubjects($bob, $a['id']));
		self::assertSame([], $this->activitySubjects($this->accounts['carol']->getUID(), $a['id']));
		$notifications = $this->invitations($bob);
		self::assertCount(1, $notifications);
		$notification = Server::get(NotificationManager::class)->prepare($notifications[0], 'en');
		self::assertStringContainsString('Shared <inventory>', $notification->getParsedSubject());
		$actions = $notification->getParsedActions();
		self::assertCount(2, $actions);
		foreach ($actions as $action) {
			self::assertSame(IAction::TYPE_WEB, $action->getRequestType());
			self::assertStringContainsString('invitation=' . $notification->getObjectId(), $action->getLink());
			self::assertStringContainsString('invitationWorkspace=' . $a['id'], $action->getLink());
			self::assertStringContainsString('invitationToken=', $action->getLink());
		}
		// Exercise Nextcloud's real notification email renderer without sending external mail.
		$method = new \ReflectionMethod(\OCA\Notifications\MailNotifications::class, 'prepareEmailMessage');
		$method->setAccessible(true);
		$mail = $method->invoke(Server::get(\OCA\Notifications\MailNotifications::class), $bob, [$notification], 'en', 'UTC');
		self::assertNotNull($mail);
		$html = $mail->getSymfonyEmail()->getHtmlBody();
		self::assertStringContainsString('Shared &lt;inventory&gt;', $html);
		foreach (['Accept invitation', 'Decline invitation', 'decision=accept', 'decision=decline'] as $text) {
			self::assertStringContainsString($text, $html);
			self::assertStringContainsString($text, $mail->getPlainBody());
		}
		$id = (int)$notification->getObjectId();
		$this->workspaces->respond($bob, $a['id'], true, $id);
		self::assertSame('viewer', $this->workspaces->requireMember($bob, $a['id'])['role']);
		self::assertSame([], $this->invitations($bob));
		self::assertContains(WorkspaceSubjects::ACCEPTED, $this->activitySubjects($alice, $a['id']));
		$this->expectException(AlreadyProcessedException::class);
		Server::get(NotificationManager::class)->prepare($notification, 'en');
	}

	public function testNotificationBodyDismissesWithoutJoiningAndAcceptActsDirectly(): void
	{
		$a = $this->create('alice', 'One click invitation');
		$alice = $this->accounts['alice']->getUID();
		$bob = $this->accounts['bob']->getUID();
		$this->workspaces->invite($alice, $a['id'], $bob, 'viewer');
		$n = Server::get(NotificationManager::class)->prepare($this->invitations($bob)[0], 'en');
		foreach ([['carol', $n->getLink(), []], ['bob', $n->getLink(), ['decision' => 'accept']],
			['bob', $n->getLink(), ['invitationToken' => '']]] as [$account, $url, $changes]) {
			try {
				$this->notificationClick($account, $url, $changes);
				self::fail('An altered notification action was accepted');
			} catch (PermissionDeniedException) { }
			self::assertCount(1, $this->invitations($bob));
		}
		$this->notificationClick('bob', $n->getLink());
		self::assertSame([], $this->invitations($bob));
		$this->expectMissing(fn () => $this->workspaces->requireMember($bob, $a['id']));
		self::assertCount(2, $this->workspaces->members($alice, $a['id']));
		$this->notificationClick('bob', $n->getParsedActions()[0]->getLink());
		self::assertSame('viewer', $this->workspaces->requireMember($bob, $a['id'])['role']);
		self::assertSame([], $this->invitations($bob));
	}

	public function testNotificationDeclineActsDirectlyAndClearsTheInvitation(): void
	{
		$a = $this->create('alice', 'Direct decline');
		$alice = $this->accounts['alice']->getUID();
		$bob = $this->accounts['bob']->getUID();
		$this->workspaces->invite($alice, $a['id'], $bob, 'editor');
		$n = Server::get(NotificationManager::class)->prepare($this->invitations($bob)[0], 'en');
		$this->notificationClick('bob', $n->getParsedActions()[1]->getLink());
		self::assertSame([], $this->invitations($bob));
		self::assertCount(1, $this->workspaces->members($alice, $a['id']));
		self::assertContains(WorkspaceSubjects::DECLINED, $this->activitySubjects($alice, $a['id']));
	}

	public function testNativeActivityFilterIncludesWorkspaceAndLowStockEventsForThisUserOnly(): void
	{
		$a = $this->create('alice', 'Activity filter');
		$this->create('bob', 'Other account');
		$alice = $this->accounts['alice']->getUID();
		$manager = Server::get(\OCP\Activity\IManager::class);
		$filter = $manager->getFilterById(Application::APP_ID);
		self::assertSame('InventoryCheck', $filter->getName());
		self::assertStringContainsString('/img/app-dark.svg', $filter->getIcon());
		foreach ([Application::APP_ID, 'other_app'] as $app) {
			$event = $manager->generateEvent();
			$event->setApp($app)->setType(\OCA\InventoryCheck\Service\LowStockNotifyService::TYPE)
				->setAuthor($alice)->setAffectedUser($alice)->setTimestamp(time())
				->setSubject('low_stock', ['workspaceId' => $a['id'], 'itemName' => 'Filter', 'sku' => 'FILTER', 'totalQty' => '1', 'reorderLevel' => '2'])
				->setObject('item', 123);
			$manager->publish($event);
			if ($app === Application::APP_ID) {
				self::assertSame($filter->getIcon(), $this->container->get(Provider::class)->parse('en', $event)->getIcon());
			}
		}
		// Inspect rows selected by Activity itself, before any provider can hide unknown apps.
		$rows = [];
		$group = $this->createMock(\OCA\Activity\GroupHelper::class);
		$group->method('addActivity')->willReturnCallback(static function (array $row) use (&$rows): void { $rows[] = $row; });
		Server::get(\OCA\Activity\Data::class)->get($group, Server::get(\OCA\Activity\UserSettings::class),
			$alice, 0, 50, 'desc', Application::APP_ID);
		self::assertCount(2, $rows);
		self::assertSame([Application::APP_ID], array_values(array_unique(array_column($rows, 'app'))));
		self::assertSame([$alice], array_values(array_unique(array_column($rows, 'affecteduser'))));
		self::assertContains('low_stock', array_column($rows, 'subject'));
		self::assertContains(WorkspaceSubjects::CREATED, array_column($rows, 'subject'));
	}

	public function testRevocationReinvitationAndDeclineExpireOldActions(): void
	{
		$a = $this->create('alice', 'Invitation lifecycle');
		$alice = $this->accounts['alice']->getUID();
		$bob = $this->accounts['bob']->getUID();
		$this->workspaces->invite($alice, $a['id'], $bob, 'editor');
		$old = $this->invitations($bob)[0];
		$this->workspaces->removeMember($alice, $a['id'], $bob);
		self::assertSame([], $this->invitations($bob));
		$this->workspaces->invite($alice, $a['id'], $bob, 'viewer');
		$current = $this->invitations($bob)[0];
		self::assertNotSame($old->getObjectId(), $current->getObjectId());
		$this->expectMissing(fn () => $this->workspaces->respond($bob, $a['id'], true, (int)$old->getObjectId()));
		try {
			Server::get(NotificationManager::class)->prepare($old, 'en');
			self::fail('Revoked invitation remained actionable');
		} catch (AlreadyProcessedException) { }
		self::assertCount(1, $this->invitations($bob));
		$this->workspaces->respond($bob, $a['id'], false, (int)$current->getObjectId());
		self::assertSame([], $this->invitations($bob));
		$this->expectMissing(fn () => $this->workspaces->requireMember($bob, $a['id']));
		self::assertContains(WorkspaceSubjects::DECLINED, $this->activitySubjects($alice, $a['id']));
	}

	public function testActivityAudienceAndWorkspaceDeletionCleanup(): void
	{
		$a = $this->create('alice', 'Members');
		$alice = $this->accounts['alice']->getUID();
		$bob = $this->accounts['bob']->getUID();
		$carol = $this->accounts['carol']->getUID();
		$this->workspaces->invite($alice, $a['id'], $bob, 'editor');
		$this->workspaces->invite($alice, $a['id'], $carol, 'viewer');
		$this->workspaces->respond($bob, $a['id'], true);
		$this->workspaces->rename($alice, $a['id'], 'Renamed');
		self::assertContains(WorkspaceSubjects::RENAMED, $this->activitySubjects($bob, $a['id']));
		self::assertSame([WorkspaceSubjects::INVITED], $this->activitySubjects($carol, $a['id']));
		$this->workspaces->removeMember($bob, $a['id'], $bob);
		$this->workspaces->delete($alice, $a['id']);
		self::assertSame([], $this->invitations($carol));
		self::assertContains(WorkspaceSubjects::LEFT, $this->activitySubjects($bob, $a['id']));
		self::assertContains(WorkspaceSubjects::DELETED, $this->activitySubjects($carol, $a['id']));
		$event = Server::get(\OCP\Activity\IManager::class)->generateEvent();
		$event->setApp(Application::APP_ID)->setSubject(WorkspaceSubjects::DELETED, ['actorName' => 'Alice', 'workspaceName' => 'Renamed']);
		self::assertStringContainsString('deleted workspace', $this->container->get(Provider::class)->parse('en', $event)->getParsedSubject());
	}

	public function testCodesBalancesExportsAndStocktakesAreIsolated(): void
	{
		$a = $this->create('alice', 'Alice inventory');
		$b = $this->create('bob', 'Bob inventory');
		$items = $this->container->get(ItemService::class);
		$locations = $this->container->get(LocationService::class);
		$movements = $this->container->get(MovementService::class);
		$records = [];
		foreach ([['alice', $a, 9], ['bob', $b, 4]] as [$account, $workspace, $qty]) {
			$uid = $this->select($account, $workspace['id']);
			$item = $items->create($uid, ['sku' => 'SAME-SKU', 'name' => $account . ' item']);
			$location = $locations->create($uid, ['code' => 'SAME-LOCATION', 'name' => $account . ' location', 'kind' => 'warehouse']);
			$movements->receive($uid, $item['id'], $location['id'], $qty, 'test', null, false);
			$records[$account] = [$item['id'], $location['id']];
			self::assertSame([$item['id'] => $qty], $this->container->get(BalanceMapper::class)->sumQtyByItem());
		}
		$alice = $this->select('alice', $a['id']);
		self::assertSame(1, $this->container->get(ItemMapper::class)->search('', null, 100, 0)['total']);
		self::assertSame($records['alice'][0], $items->byCode($alice, 'SAME-SKU')['id']);
		$this->expectMissing(fn () => $items->get($alice, $records['bob'][0]));
		$this->expectMissing(fn () => $this->container->get(LocationMapper::class)->findById($records['bob'][1]));
		$csv = $this->container->get(CsvExportService::class)->export($alice, 'items', null, null, null, null)['body'];
		self::assertStringContainsString('alice item', $csv);
		self::assertStringNotContainsString('bob item', $csv);
		$cycles = $this->container->get(CycleCountService::class);
		$campaign = $cycles->create($alice, $records['alice'][1], 'Private count');
		self::assertCount(1, $this->container->get(CycleLineMapper::class)->forCampaign($campaign['id']));
		$this->select('bob', $b['id']);
		self::assertSame(0, $cycles->list($this->accounts['bob']->getUID(), null, 100, 0)['total']);
		$this->expectMissing(fn () => $cycles->get($this->accounts['bob']->getUID(), $campaign['id']));
	}

	public function testInvitationAcceptanceAndRevocationChangeAccess(): void
	{
		$a = $this->create('alice', 'Shared after acceptance');
		$alice = $this->accounts['alice']->getUID();
		$bob = $this->accounts['bob']->getUID();
		$this->workspaces->invite($alice, $a['id'], $bob, 'editor');
		$this->expectMissing(fn () => $this->workspaces->requireMember($bob, $a['id']));
		$this->expectMissing(fn () => $this->workspaces->respond($this->accounts['carol']->getUID(), $a['id'], true));
		$this->workspaces->respond($bob, $a['id'], true);
		$this->select('bob', $a['id']);
		$item = $this->container->get(ItemService::class)->create($bob, ['sku' => 'SHARED', 'name' => 'Shared item']);
		self::assertGreaterThan(0, $item['id']);
		$this->workspaces->removeMember($alice, $a['id'], $bob);
		$this->expectMissing(fn () => $this->select('bob', $a['id']));
	}

	public function testViewerCannotWriteEvenThroughTheMapper(): void
	{
		$a = $this->create('alice', 'Read only invitation');
		$alice = $this->accounts['alice']->getUID();
		$bob = $this->accounts['bob']->getUID();
		$this->workspaces->invite($alice, $a['id'], $bob, 'viewer');
		$this->workspaces->respond($bob, $a['id'], true);
		$this->select('bob', $a['id']);
		$item = new Item();
		$item->setSku('FORBIDDEN');
		$this->expectException(PermissionDeniedException::class);
		$this->container->get(ItemMapper::class)->insert($item);
	}

	public function testScanReplayKeysAreIndependentAcrossWorkspaces(): void
	{
		$a = $this->create('alice', 'First inventory');
		$b = $this->create('alice', 'Second inventory');
		$alice = $this->select('alice', $a['id']);
		$idempotency = $this->container->get(ScanIdempotencyService::class);
		self::assertTrue($idempotency->tryClaim($alice, 'same-request', 'hash'));
		$idempotency->complete($alice, 'same-request', ['workspace' => 'first']);
		$this->select('alice', $b['id']);
		self::assertSame('missing', $idempotency->lookup($alice, 'same-request')['status']);
		self::assertTrue($idempotency->tryClaim($alice, 'same-request', 'hash'));
		$idempotency->complete($alice, 'same-request', ['workspace' => 'second']);
		$this->select('alice', $a['id']);
		self::assertSame(['workspace' => 'first'], $idempotency->lookup($alice, 'same-request')['response']);
	}

	public function testDemoIsPrivateAndDeletionIsPermanent(): void
	{
		$alice = $this->accounts['alice']->getUID();
		$bob = $this->accounts['bob']->getUID();
		$a = $this->workspaces->list($alice);
		$b = $this->workspaces->list($bob);
		self::assertCount(1, $a);
		self::assertCount(1, $b);
		self::assertNotSame($a[0]['id'], $b[0]['id']);
		$this->created[] = [$bob, $b[0]['id']];
		$this->select('alice', $a[0]['id']);
		self::assertSame(3, $this->container->get(ItemMapper::class)->search('', null, 100, 0)['total']);
		$this->workspaces->delete($alice, $a[0]['id']);
		self::assertSame([], $this->workspaces->list($alice));
		self::assertSame([], $this->workspaces->list($alice));
		$this->select('bob', $b[0]['id']);
		self::assertSame(3, $this->container->get(ItemMapper::class)->search('', null, 100, 0)['total']);
		$c = $this->create('alice', 'After demo deletion');
		self::assertFalse($c['isDemo']);
		self::assertCount(1, $this->workspaces->list($alice));
	}

	public function testWorkspaceDeletionRemovesOnlyItsOwnPhotos(): void
	{
		$a = $this->create('alice', 'First photos');
		$b = $this->create('alice', 'Second photos');
		$appData = Server::get(\OCP\Files\AppData\IAppDataFactory::class)->get(Application::APP_ID);
		$folderName = \OCA\InventoryCheck\Service\ItemPhotoService::workspaceFolder($a['id']);
		$appData->newFolder($folderName)->newFile('photo.jpg')->putContent('first');
		$otherFolder = \OCA\InventoryCheck\Service\ItemPhotoService::workspaceFolder($b['id']);
		$appData->newFolder($otherFolder)->newFile('photo.jpg')->putContent('second');
		$this->workspaces->delete($this->accounts['alice']->getUID(), $a['id']);
		self::assertSame('second', $appData->getFolder($otherFolder)->getFile('photo.jpg')->getContent());
		$this->expectException(\OCP\Files\NotFoundException::class);
		$appData->getFolder($folderName)->getFile('photo.jpg')->getContent();
	}

	public function testForgedEntityCannotUpdateOrDeleteAnotherWorkspaceRow(): void
	{
		$a = $this->create('alice', 'First inventory');
		$b = $this->create('alice', 'Second inventory');
		$uid = $this->select('alice', $a['id']);
		$items = $this->container->get(ItemService::class);
		$item = $items->create($uid, ['sku' => 'PROTECTED', 'name' => 'Original']);
		$this->select('alice', $b['id']);
		$forged = new Item();
		$forged->setId($item['id']);
		$forged->setWorkspaceId($b['id']);
		$forged->setName('Wrong workspace');
		$mapper = $this->container->get(ItemMapper::class);
		$mapper->update($forged);
		$mapper->delete($forged);
		$this->select('alice', $a['id']);
		self::assertSame('Original', $items->get($uid, $item['id'])['name']);
	}
}
