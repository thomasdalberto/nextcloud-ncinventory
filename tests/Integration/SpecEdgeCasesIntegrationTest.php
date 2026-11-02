<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Tests\Integration;

use OCA\InventoryCheck\AppInfo\Application;
use OCA\InventoryCheck\Command\RebuildBalancesCommand;
use OCA\InventoryCheck\Db\BalanceMapper;
use OCA\InventoryCheck\Exception\InsufficientStockException;
use OCA\InventoryCheck\Exception\PermissionDeniedException;
use OCA\InventoryCheck\Service\AccessControlService;
use OCA\InventoryCheck\Service\ItemService;
use OCA\InventoryCheck\Service\LocationService;
use OCA\InventoryCheck\Service\MovementService;
use OCP\IConfig;
use OCP\IUserManager;
use OCP\Server;
use Symfony\Component\Console\Tester\CommandTester;
use Test\TestCase;

/**
 * S4 more-negative, S11 transferGroup filter, S13 rebuild, P4 field ACL.
 *
 * @group DB
 */
final class SpecEdgeCasesIntegrationTest extends TestCase
{
	private MovementService $movements;
	private LocationService $locations;
	private ItemService $items;
	private BalanceMapper $balances;
	private IConfig $config;
	private string $uid = 'admin';

	protected function setUp(): void
	{
		parent::setUp();
		$app = new Application();
		$c = $app->getContainer();
		$this->movements = $c->get(MovementService::class);
		$this->locations = $c->get(LocationService::class);
		$this->items = $c->get(ItemService::class);
		$this->balances = $c->get(BalanceMapper::class);
		$this->config = Server::get(IConfig::class);
		$this->config->setAppValue(Application::APP_ID, AccessControlService::KEY_ALLOW_NEGATIVE, '0');
	}

	public function testTransferGroupFilterReturnsExactlyTwoLegs(): void
	{
		$suffix = bin2hex(random_bytes(3));
		$a = $this->locations->create($this->uid, [
			'code' => 'TG-A-' . $suffix, 'name' => 'A', 'kind' => 'warehouse',
		]);
		$b = $this->locations->create($this->uid, [
			'code' => 'TG-B-' . $suffix, 'name' => 'B', 'kind' => 'van',
		]);
		$item = $this->items->create($this->uid, [
			'sku' => 'TG-I-' . $suffix, 'name' => 'Item',
		]);
		$itemId = (int)$item['id'];
		$this->movements->receive($this->uid, $itemId, (int)$a['id'], 10, null);
		$xfer = $this->movements->transfer($this->uid, $itemId, (int)$a['id'], (int)$b['id'], 4, null);
		$group = (string)$xfer['movements'][0]['transferGroup'];

		$list = $this->movements->list($this->uid, null, null, null, null, null, $group, 50, 0);
		$this->assertSame(2, $list['total']);
		$this->assertCount(2, $list['data']);
		$kinds = array_column($list['data'], 'kind');
		sort($kinds);
		$this->assertSame(['transfer_in', 'transfer_out'], $kinds);
	}

	public function testS4MoreNegativeBlockedAfterToggleOffButReceiveTowardZeroAllowed(): void
	{
		$suffix = bin2hex(random_bytes(3));
		$loc = $this->locations->create($this->uid, [
			'code' => 'S4-' . $suffix, 'name' => 'Loc', 'kind' => 'other',
		]);
		$item = $this->items->create($this->uid, [
			'sku' => 'S4I-' . $suffix, 'name' => 'Item',
		]);
		$itemId = (int)$item['id'];
		$locId = (int)$loc['id'];

		$this->config->setAppValue(Application::APP_ID, AccessControlService::KEY_ALLOW_NEGATIVE, '1');
		$this->movements->issue($this->uid, $itemId, $locId, 3, 'create negative');
		$this->assertSame(-3, $this->balances->findPair($itemId, $locId)?->getQty());

		$this->config->setAppValue(Application::APP_ID, AccessControlService::KEY_ALLOW_NEGATIVE, '0');

		try {
			$this->movements->issue($this->uid, $itemId, $locId, 1, 'more negative');
			$this->fail('expected insufficient_stock when making more negative');
		} catch (InsufficientStockException) {
			$this->assertSame(-3, $this->balances->findPair($itemId, $locId)?->getQty());
		}

		$recv = $this->movements->receive($this->uid, $itemId, $locId, 2, 'toward zero');
		$this->assertSame(-1, $recv['balances'][0]['qty']);
	}

	public function testRebuildBalancesFixesDriftWithForce(): void
	{
		$suffix = bin2hex(random_bytes(3));
		$loc = $this->locations->create($this->uid, [
			'code' => 'RB-' . $suffix, 'name' => 'Loc', 'kind' => 'other',
		]);
		$item = $this->items->create($this->uid, [
			'sku' => 'RBI-' . $suffix, 'name' => 'Item',
		]);
		$itemId = (int)$item['id'];
		$locId = (int)$loc['id'];
		$this->movements->receive($this->uid, $itemId, $locId, 7, null);

		$bal = $this->balances->findPair($itemId, $locId);
		$this->assertNotNull($bal);
		$bal->setQty(99);
		$bal->setUpdatedAt(time());
		$this->balances->update($bal);
		$this->assertSame(99, $this->balances->findPair($itemId, $locId)?->getQty());

		$cmd = Server::get(RebuildBalancesCommand::class);
		$tester = new CommandTester($cmd);
		$code = $tester->execute(['--force' => true]);
		$this->assertContains($code, [0, 1]);
		$this->assertSame(7, $this->balances->findPair($itemId, $locId)?->getQty());
	}

	public function testFieldUserCannotReceiveButCanIssue(): void
	{
		$users = Server::get(IUserManager::class);
		$fieldUid = 'ivfield_' . bin2hex(random_bytes(2));
		if ($users->userExists($fieldUid)) {
			$users->get($fieldUid)?->delete();
		}
		$created = $users->createUser($fieldUid, bin2hex(random_bytes(8)));
		$this->assertNotFalse($created);

		$prevRestriction = $this->config->getAppValue(Application::APP_ID, AccessControlService::KEY_ACCESS_RESTRICTION, '0');
		$prevAllowed = $this->config->getAppValue(Application::APP_ID, AccessControlService::KEY_ACCESS_ALLOWED_USER_IDS, '[]');
		$prevOffice = $this->config->getAppValue(Application::APP_ID, AccessControlService::KEY_OFFICE_USER_IDS, '[]');

		try {
			$this->config->setAppValue(Application::APP_ID, AccessControlService::KEY_ACCESS_RESTRICTION, '1');
			$this->config->setAppValue(
				Application::APP_ID,
				AccessControlService::KEY_ACCESS_ALLOWED_USER_IDS,
				json_encode([$fieldUid, 'admin'], JSON_THROW_ON_ERROR),
			);
			$this->config->setAppValue(Application::APP_ID, AccessControlService::KEY_OFFICE_USER_IDS, '[]');

			$acl = Server::get(AccessControlService::class);
			$this->assertFalse($acl->canWrite($fieldUid));
			$this->assertTrue($acl->canUseApp($fieldUid));

			$suffix = bin2hex(random_bytes(3));
			$loc = $this->locations->create($this->uid, [
				'code' => 'FL-' . $suffix, 'name' => 'Loc', 'kind' => 'van',
			]);
			$item = $this->items->create($this->uid, [
				'sku' => 'FI-' . $suffix, 'name' => 'Item',
			]);
			$itemId = (int)$item['id'];
			$locId = (int)$loc['id'];
			$this->movements->receive($this->uid, $itemId, $locId, 5, null);

			try {
				$this->movements->receive($fieldUid, $itemId, $locId, 1, null);
				$this->fail('field must not receive');
			} catch (PermissionDeniedException) {
				$this->assertSame(5, $this->balances->findPair($itemId, $locId)?->getQty());
			}

			$issued = $this->movements->issue($fieldUid, $itemId, $locId, 2, 'field issue');
			$this->assertSame(3, $issued['balances'][0]['qty']);
		} finally {
			$this->config->setAppValue(Application::APP_ID, AccessControlService::KEY_ACCESS_RESTRICTION, $prevRestriction);
			$this->config->setAppValue(Application::APP_ID, AccessControlService::KEY_ACCESS_ALLOWED_USER_IDS, $prevAllowed);
			$this->config->setAppValue(Application::APP_ID, AccessControlService::KEY_OFFICE_USER_IDS, $prevOffice);
			$users->get($fieldUid)?->delete();
		}
	}
}
