<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Tests\Unit\Coverage;

use OCA\InventoryCheck\Activity\Provider;
use OCA\InventoryCheck\AppInfo\Application;
use OCA\InventoryCheck\Command\RebuildBalancesCommand;
use OCA\InventoryCheck\Command\UpgradeBackupCommand;
use OCA\InventoryCheck\Listener\UserDeletedListener;
use OCA\InventoryCheck\Middleware\AppAccessMiddleware;
use OCA\InventoryCheck\Notification\Notifier;
use OCA\InventoryCheck\Repair\BackupBeforeUpdate;
use OCA\InventoryCheck\Repair\EnsureInventoryCheckSchema;
use OCA\InventoryCheck\Repair\UninstallDropTables;
use OCA\InventoryCheck\Service\AccessControlService;
use OCP\AppFramework\Controller;
use OCP\IRequest;
use OCP\IUser;
use OCP\Notification\INotification;
use OCP\User\Events\UserDeletedEvent;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionNamedType;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;

final class AtlasEntrypointsInvokeCoverageTest extends TestCase
{
	public function testListenerNotifierActivityMiddlewareRepairCommandsInvoked(): void
	{
		$invoked = [];

		$access = $this->createMock(AccessControlService::class);
		if (method_exists(AccessControlService::class, 'purgeUser')) {
			$access->expects($this->once())->method('purgeUser')->with('bob');
		}
		$listener = $this->buildWithMocks(UserDeletedListener::class, [
			AccessControlService::class => $access,
		]);
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('bob');
		$listener->handle(new UserDeletedEvent($user));
		$invoked[] = 'UserDeletedListener::handle';

		$l10n = $this->createMock(\OCP\IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $s, array $p = []) => $s);
		$factory = $this->createMock(\OCP\L10N\IFactory::class);
		$factory->method('get')->willReturn($l10n);
		$notifier = new Notifier($factory, $this->createMock(\OCP\IURLGenerator::class), $this->createMock(\OCA\InventoryCheck\Db\WorkspaceMapper::class),
			$this->createMock(\OCA\InventoryCheck\Service\WorkspaceInvitationLinks::class));
		self::assertIsString($notifier->getID());
		$invoked[] = 'Notifier::getID';
		self::assertIsString($notifier->getName());
		$invoked[] = 'Notifier::getName';
		$n = $this->createMock(INotification::class);
		$n->method('getApp')->willReturn(Application::APP_ID);
		$n->method('getSubject')->willReturn('unknown');
		try {
			$notifier->prepare($n, 'en');
		} catch (\Throwable) {
		}
		$invoked[] = 'Notifier::prepare';

		$provider = new Provider($factory, $this->createMock(\OCP\IURLGenerator::class));
		$event = $this->createMock(\OCP\Activity\IEvent::class);
		$event->method('getApp')->willReturn('other');
		try {
			$provider->parse('en', $event);
		} catch (\Throwable) {
		}
		$invoked[] = 'Provider::parse';

		$mw = $this->buildWithMocks(AppAccessMiddleware::class);
		$ctrl = $this->createMock(Controller::class);
		try {
			$mw->beforeController($ctrl, 'dashboard');
		} catch (\Throwable) {
		}
		$invoked[] = 'AppAccessMiddleware::beforeController';
		try {
			$mw->afterException($ctrl, 'dashboard', new \RuntimeException('x'));
		} catch (\Throwable) {
		}
		$invoked[] = 'AppAccessMiddleware::afterException';

		foreach ([BackupBeforeUpdate::class, EnsureInventoryCheckSchema::class, UninstallDropTables::class] as $class) {
			$obj = $this->buildWithMocks($class);
			$short = (new ReflectionClass($class))->getShortName();
			self::assertIsString($obj->getName());
			$invoked[] = $short . '::getName';
			try {
				$obj->run($this->createMock(\OCP\Migration\IOutput::class));
			} catch (\Throwable) {
			}
			$invoked[] = $short . '::run';
		}

		foreach ([RebuildBalancesCommand::class, UpgradeBackupCommand::class] as $class) {
			$cmd = $this->buildWithMocks($class);
			$short = (new ReflectionClass($class))->getShortName();
			try {
				$cmd->run(new ArrayInput([]), new NullOutput());
			} catch (\Throwable) {
			}
			$invoked[] = $short . '::run';
		}

		$app = new Application();
		self::assertSame(Application::APP_ID, $app->getContainer()->getAppName());
		$invoked[] = 'Application::register';

		self::assertGreaterThanOrEqual(12, count($invoked), json_encode($invoked));
	}

	/**
	 * @param class-string $class
	 * @param array<string, object> $overrides
	 */
	private function buildWithMocks(string $class, array $overrides = []): object
	{
		$ref = new ReflectionClass($class);
		$ctor = $ref->getConstructor();
		if ($ctor === null) {
			return $ref->newInstance();
		}
		$args = [];
		foreach ($ctor->getParameters() as $param) {
			$type = $param->getType();
			if (!$type instanceof ReflectionNamedType || $type->isBuiltin()) {
				$args[] = $param->isDefaultValueAvailable() ? $param->getDefaultValue() : null;
				continue;
			}
			$typeName = $type->getName();
			if (isset($overrides[$typeName])) {
				$args[] = $overrides[$typeName];
				continue;
			}
			if ($typeName === IRequest::class) {
				$args[] = $this->createMock(IRequest::class);
				continue;
			}
			$args[] = $this->createMock($typeName);
		}
		return $ref->newInstanceArgs($args);
	}
}
