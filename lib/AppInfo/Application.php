<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\AppInfo;

use OCA\InventoryCheck\Command\RebuildBalancesCommand;
use OCA\InventoryCheck\Db\BalanceMapper;
use OCA\InventoryCheck\Db\CycleCampaignMapper;
use OCA\InventoryCheck\Db\CycleLineMapper;
use OCA\InventoryCheck\Db\ItemMapper;
use OCA\InventoryCheck\Db\LocationFavouriteMapper;
use OCA\InventoryCheck\Db\LocationMapper;
use OCA\InventoryCheck\Db\MovementMapper;
use OCA\InventoryCheck\Db\NotifyLogMapper;
use OCA\InventoryCheck\Db\ScanDeviceMapper;
use OCA\InventoryCheck\Db\WorkspaceMapper;
use OCA\InventoryCheck\Listener\GroupDeletedListener;
use OCA\InventoryCheck\Listener\UserDeletedListener;
use OCA\InventoryCheck\Middleware\AppAccessMiddleware;
use OCA\InventoryCheck\Notification\Notifier;
use OCA\InventoryCheck\Public\StockIssueFacade;
use OCA\InventoryCheck\Repair\BackupBeforeUpdate;
use OCA\InventoryCheck\Repair\EnsureInventoryCheckSchema;
use OCA\InventoryCheck\Repair\ScrubStaleAclSubjects;
use OCA\InventoryCheck\Repair\UninstallDropTables;
use OCA\InventoryCheck\Service\AccessControlService;
use OCA\InventoryCheck\Service\BalanceService;
use OCA\InventoryCheck\Service\Clock;
use OCA\InventoryCheck\Service\CsvExportService;
use OCA\InventoryCheck\Service\CsvImportService;
use OCA\InventoryCheck\Service\CycleCountService;
use OCA\InventoryCheck\Service\DevicePairingService;
use OCA\InventoryCheck\Service\DirectoryOptionsService;
use OCA\InventoryCheck\Service\FlangeService;
use OCA\InventoryCheck\Service\ItemPhotoService;
use OCA\InventoryCheck\Service\ItemService;
use OCA\InventoryCheck\Service\LocationAclService;
use OCA\InventoryCheck\Service\LocationFavouriteService;
use OCA\InventoryCheck\Service\LocationService;
use OCA\InventoryCheck\Service\Lookup\OpenFactsProvider;
use OCA\InventoryCheck\Service\Lookup\UpcItemDbProvider;
use OCA\InventoryCheck\Service\LowStockNotifyService;
use OCA\InventoryCheck\Service\LowStockService;
use OCA\InventoryCheck\Service\MobileAccessService;
use OCA\InventoryCheck\Service\MovementService;
use OCA\InventoryCheck\Service\ProductLookupService;
use OCA\InventoryCheck\Service\QtyScaleService;
use OCA\InventoryCheck\Service\ScanIdempotencyService;
use OCA\InventoryCheck\Service\UpgradeBackupService;
use OCA\InventoryCheck\Service\WorkspaceContext;
use OCA\InventoryCheck\Service\WorkspaceSettings;
use OCA\InventoryCheck\Service\ScannerService;
use OCP\Activity\IManager as IActivityManager;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\App\IAppManager;
use OCP\Files\AppData\IAppDataFactory;
use OCP\Files\IRootFolder;
use OCP\Group\Events\GroupDeletedEvent;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\L10N\IFactory;
use OCP\Lock\ILockingProvider;
use OCP\Notification\IManager as INotificationManager;
use OCP\User\Events\UserDeletedEvent;

// Bundled splitbrain/phpQRCode lives under lib/Vendor. Nextcloud only autoloads
// OCA\* classes, so the app must load its own composer autoloader — otherwise
// every label route (LabelSvg::forItem/forLocation) fatals at runtime while
// tests stay green because tests/bootstrap.php requires vendor/autoload.php.
//
// CRITICAL: composer's autoload.php registers itself *prepended*. When the
// vendor tree was installed with dev dependencies (nextcloud/ocp stubs), a
// prepended loader would shadow the server's real OCP interfaces and break
// the entire instance. Re-register appended so Nextcloud's own autoloader
// always wins for OCP\*/OC\* while splitbrain\phpQRCode still resolves here.
$inventorycheckAutoload = __DIR__ . '/../../vendor/autoload.php';
if (!class_exists(\splitbrain\phpQRCode\QRCode::class, false) && is_file($inventorycheckAutoload)) {
	$inventorycheckLoader = require $inventorycheckAutoload;
	if ($inventorycheckLoader instanceof \Composer\Autoload\ClassLoader) {
		$inventorycheckLoader->unregister();
		$inventorycheckLoader->register(false);
	}
	unset($inventorycheckLoader);
}
unset($inventorycheckAutoload);
// Fallback for production installs where dev-vendor/ is not shipped: map the
// vendored prefix straight onto lib/Vendor (appended — never shadows OCP/OC).
spl_autoload_register(static function (string $class): void {
	$prefix = 'splitbrain\\phpQRCode\\';
	if (str_starts_with($class, $prefix)) {
		$file = __DIR__ . '/../Vendor/splitbrain/phpQRCode/'
			. str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
		if (is_file($file)) {
			require_once $file;
		}
	}
}, true, false);

class Application extends App implements IBootstrap
{
	public const APP_ID = 'inventorycheck';

	public function __construct(array $urlParams = [])
	{
		parent::__construct(self::APP_ID, $urlParams);
	}

	public function register(IRegistrationContext $context): void
	{
		$context->registerEventListener(UserDeletedEvent::class, UserDeletedListener::class);
		$context->registerEventListener(GroupDeletedEvent::class, GroupDeletedListener::class);
		$context->registerService(LocationMapper::class, static fn ($c) => new LocationMapper($c->get(IDBConnection::class), $c->get(WorkspaceContext::class)));
		$context->registerService(ItemMapper::class, static fn ($c) => new ItemMapper($c->get(IDBConnection::class), $c->get(WorkspaceContext::class)));
		$context->registerService(BalanceMapper::class, static fn ($c) => new BalanceMapper($c->get(IDBConnection::class), $c->get(WorkspaceContext::class)));
		$context->registerService(MovementMapper::class, static fn ($c) => new MovementMapper($c->get(IDBConnection::class), $c->get(WorkspaceContext::class)));
		$context->registerService(ScanDeviceMapper::class, static fn ($c) => new ScanDeviceMapper($c->get(IDBConnection::class)));
		$context->registerService(NotifyLogMapper::class, static fn ($c) => new NotifyLogMapper($c->get(IDBConnection::class), $c->get(WorkspaceContext::class)));
		$context->registerService(CycleCampaignMapper::class, static fn ($c) => new CycleCampaignMapper($c->get(IDBConnection::class), $c->get(WorkspaceContext::class)));
		$context->registerService(CycleLineMapper::class, static fn ($c) => new CycleLineMapper($c->get(IDBConnection::class), $c->get(WorkspaceContext::class)));
		$context->registerService(LocationFavouriteMapper::class, static fn ($c) => new LocationFavouriteMapper($c->get(IDBConnection::class), $c->get(WorkspaceContext::class)));

		$context->registerService(WorkspaceMapper::class, static fn ($c) => new WorkspaceMapper($c->get(IDBConnection::class)));

		$context->registerService(Clock::class, static fn () => new Clock());

		$context->registerService(AccessControlService::class, static function ($c): AccessControlService {
			return new AccessControlService(
				$c->get(IGroupManager::class),
				$c->get(IUserSession::class),
				$c->get(WorkspaceContext::class),
				$c->get(WorkspaceSettings::class),
			);
		});
		$context->registerService(DirectoryOptionsService::class, static function ($c): DirectoryOptionsService {
			return new DirectoryOptionsService(
				$c->get(IUserManager::class),
				$c->get(IGroupManager::class),
			);
		});
		$context->registerService(LocationAclService::class, static function ($c): LocationAclService {
			return new LocationAclService(
				$c->get(IDBConnection::class),
				$c->get(WorkspaceSettings::class),
				$c->get(AccessControlService::class),
				$c->get(IGroupManager::class),
				$c->get(IUserManager::class),
				$c->get(LocationMapper::class),
				$c->get(ScanDeviceMapper::class),
				$c->get(WorkspaceContext::class),
			);
		});
		$context->registerService(QtyScaleService::class, static function ($c): QtyScaleService {
			return new QtyScaleService(
				$c->get(IDBConnection::class),
				$c->get(WorkspaceSettings::class),
				$c->get(ILockingProvider::class),
			);
		});
		$context->registerService(LocationService::class, static function ($c): LocationService {
			return new LocationService(
				$c->get(IDBConnection::class),
				$c->get(LocationMapper::class),
				$c->get(AccessControlService::class),
				$c->get(Clock::class),
				$c->get(LocationAclService::class),
				$c->get(CycleCampaignMapper::class),
				$c->get(ItemMapper::class),
				$c->get(ILockingProvider::class),
				$c->get(LocationFavouriteMapper::class),
			);
		});
		$context->registerService(ItemService::class, static function ($c): ItemService {
			return new ItemService(
				$c->get(IDBConnection::class),
				$c->get(ItemMapper::class),
				$c->get(BalanceMapper::class),
				$c->get(AccessControlService::class),
				$c->get(Clock::class),
				$c->get(ILockingProvider::class),
				$c->get(LocationAclService::class),
				$c->get(WorkspaceSettings::class),
				$c->get(CycleLineMapper::class),
				$c->get(LocationMapper::class),
				$c->get(ItemPhotoService::class),
			);
		});
		$context->registerService(BalanceService::class, static function ($c): BalanceService {
			return new BalanceService(
				$c->get(BalanceMapper::class),
				$c->get(LocationAclService::class),
			);
		});
		// LowStockNotifyService never depends on MovementService — resolving it
		// first here keeps the graph acyclic while still letting MovementService
		// fire a best-effort notify after each ledger commit.
		$context->registerService(MovementService::class, static function ($c): MovementService {
			return new MovementService(
				$c->get(IDBConnection::class),
				$c->get(MovementMapper::class),
				$c->get(BalanceMapper::class),
				$c->get(ItemMapper::class),
				$c->get(LocationMapper::class),
				$c->get(AccessControlService::class),
				$c->get(Clock::class),
				$c->get(LocationAclService::class),
				$c->get(WorkspaceSettings::class),
				$c->get(LowStockNotifyService::class),
			);
		});
		$context->registerService(ScanIdempotencyService::class, static function ($c): ScanIdempotencyService {
			return new ScanIdempotencyService(
				$c->get(IDBConnection::class),
				$c->get(Clock::class),
				$c->get(WorkspaceContext::class),
			);
		});
		$context->registerService(LowStockService::class, static function ($c): LowStockService {
			return new LowStockService(
				$c->get(ItemMapper::class),
				$c->get(BalanceMapper::class),
				$c->get(WorkspaceSettings::class),
				$c->get(LocationAclService::class),
			);
		});
		$context->registerService(DevicePairingService::class, static function ($c): DevicePairingService {
			return new DevicePairingService(
				$c->get(ScannerService::class),
				$c->get(ScanDeviceMapper::class),
				$c->get(Clock::class),
				$c->get(IConfig::class),
				$c->get(ILockingProvider::class),
				$c->get(IRequest::class),
			);
		});
		$context->registerService(ProductLookupService::class, static function ($c): ProductLookupService {
			$http = $c->get(\OCP\Http\Client\IClientService::class);
			return new ProductLookupService(
				[
					new OpenFactsProvider($http, 'openfoodfacts', 'https://world.openfoodfacts.org', 'Open Food Facts'),
					new OpenFactsProvider($http, 'openbeautyfacts', 'https://world.openbeautyfacts.org', 'Open Beauty Facts'),
					new OpenFactsProvider($http, 'openpetfoodfacts', 'https://world.openpetfoodfacts.org', 'Open Pet Food Facts'),
					new OpenFactsProvider($http, 'openproductsfacts', 'https://world.openproductsfacts.org', 'Open Products Facts'),
					new UpcItemDbProvider($http),
				],
				$c->get(\OCP\ICacheFactory::class),
				$c->get(IConfig::class),
				$c->get(IDBConnection::class),
				$c->get(\Psr\Log\LoggerInterface::class),
			);
		});

		$context->registerService(CsvExportService::class, static function ($c): CsvExportService {
			return new CsvExportService(
				$c->get(ItemMapper::class),
				$c->get(LocationMapper::class),
				$c->get(BalanceMapper::class),
				$c->get(MovementMapper::class),
				$c->get(AccessControlService::class),
				$c->get(WorkspaceSettings::class),
			);
		});
		$context->registerService(CsvImportService::class, static function ($c): CsvImportService {
			return new CsvImportService(
				$c->get(IDBConnection::class),
				$c->get(ItemMapper::class),
				$c->get(LocationMapper::class),
				$c->get(MovementService::class),
				$c->get(AccessControlService::class),
				$c->get(Clock::class),
				$c->get(ILockingProvider::class),
				$c->get(WorkspaceSettings::class),
			);
		});
		$context->registerService(ItemPhotoService::class, static function ($c): ItemPhotoService {
			return new ItemPhotoService(
				$c->get(IDBConnection::class),
				$c->get(IAppDataFactory::class)->get(Application::APP_ID),
				$c->get(ItemMapper::class),
				$c->get(AccessControlService::class),
				$c->get(Clock::class),
				$c->get(WorkspaceContext::class),
			);
		});
		$context->registerService(CycleCountService::class, static function ($c): CycleCountService {
			return new CycleCountService(
				$c->get(IDBConnection::class),
				$c->get(CycleCampaignMapper::class),
				$c->get(CycleLineMapper::class),
				$c->get(LocationMapper::class),
				$c->get(ItemMapper::class),
				$c->get(BalanceMapper::class),
				$c->get(MovementService::class),
				$c->get(AccessControlService::class),
				$c->get(Clock::class),
				$c->get(LocationAclService::class),
			);
		});
		$context->registerService(LocationFavouriteService::class, static function ($c): LocationFavouriteService {
			return new LocationFavouriteService(
				$c->get(IDBConnection::class),
				$c->get(LocationFavouriteMapper::class),
				$c->get(LocationMapper::class),
				$c->get(Clock::class),
				$c->get(LocationAclService::class),
			);
		});
		$context->registerService(StockIssueFacade::class, static function ($c): StockIssueFacade {
			return new StockIssueFacade(
				$c->get(IDBConnection::class),
				$c->get(MovementService::class),
				$c->get(MovementMapper::class),
				$c->get(ItemMapper::class),
				$c->get(LocationMapper::class),
				$c->get(WorkspaceSettings::class),
			);
		});
		$context->registerService(\OCA\InventoryCheck\Db\InvoicePushMapper::class, static fn ($c) => new \OCA\InventoryCheck\Db\InvoicePushMapper($c->get(IDBConnection::class), $c->get(WorkspaceContext::class)));

		$context->registerService(\OCA\InventoryCheck\Service\InvoicePushService::class, static function ($c): \OCA\InventoryCheck\Service\InvoicePushService {
			return new \OCA\InventoryCheck\Service\InvoicePushService(
				$c->get(IAppManager::class),
				$c->get(\OCA\InventoryCheck\Db\InvoicePushMapper::class),
				$c->get(Clock::class),
				$c->get(\Psr\Log\LoggerInterface::class),
			);
		});

		$context->registerService(FlangeService::class, static function ($c): FlangeService {
			return new FlangeService(
				$c->get(WorkspaceSettings::class),
				$c->get(IAppManager::class),
				$c->get(LocationMapper::class),
				$c->get(AccessControlService::class),
				$c->get(StockIssueFacade::class),
				$c->get(\OCA\InventoryCheck\Service\InvoicePushService::class),
				$c->get(ItemMapper::class),
			);
		});

		$context->registerService(EnsureInventoryCheckSchema::class, static function ($c): EnsureInventoryCheckSchema {
			return new EnsureInventoryCheckSchema($c->get(IDBConnection::class), $c->get(IConfig::class));
		});
		$context->registerService(ScrubStaleAclSubjects::class, static function ($c): ScrubStaleAclSubjects {
			return new ScrubStaleAclSubjects(
				$c->get(IDBConnection::class),
				$c->get(IUserManager::class),
				$c->get(IGroupManager::class),
				$c->get(IConfig::class),
			);
		});
		$context->registerService(UninstallDropTables::class, static function ($c): UninstallDropTables {
			return new UninstallDropTables(
				$c->get(IDBConnection::class),
				$c->get(IConfig::class),
				$c->get(IRootFolder::class),
			);
		});
		$context->registerService(UpgradeBackupService::class, function ($c): UpgradeBackupService {
			return new UpgradeBackupService(
				$c->get(IDBConnection::class),
				$c->get(IConfig::class),
				$c->get(IRootFolder::class),
				$c->get(IAppManager::class),
				$c->get(ILockingProvider::class),
				$c->get(\Psr\Log\LoggerInterface::class),
			);
		});
		$context->registerService(BackupBeforeUpdate::class, function ($c): BackupBeforeUpdate {
			return new BackupBeforeUpdate(
				$c->get(UpgradeBackupService::class),
			);
		});
		$context->registerService(RebuildBalancesCommand::class, static function ($c): RebuildBalancesCommand {
			return new RebuildBalancesCommand(
				$c->get(IDBConnection::class),
				$c->get(IConfig::class),
				$c->get(MovementMapper::class),
				$c->get(BalanceMapper::class),
				$c->get(WorkspaceContext::class),
				$c->get(WorkspaceMapper::class),
			);
		});

		$context->registerService(AppAccessMiddleware::class, static function ($c): AppAccessMiddleware {
			return new AppAccessMiddleware(
				$c->get(IUserSession::class),
				$c->get(AccessControlService::class),
				$c->get(IRequest::class),
				$c->get(IURLGenerator::class),
				$c->get(IFactory::class),
				$c->get(WorkspaceSettings::class),
				$c->get(\Psr\Log\LoggerInterface::class),
				$c->get(WorkspaceContext::class),
			);
		});
		$context->registerMiddleware(AppAccessMiddleware::class);

		$context->registerNotifierService(Notifier::class);
	}

	public function boot(IBootContext $context): void
	{
		$app = $context->getAppContainer();
	}
}
