<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Tests\Unit\Service;

use OCA\InventoryCheck\Db\WorkspaceMapper;
use OCA\InventoryCheck\Exception\NotFoundException;
use OCA\InventoryCheck\Exception\PermissionDeniedException;
use OCA\InventoryCheck\Exception\ValidationException;
use OCA\InventoryCheck\Service\WorkspaceContext;
use OCA\InventoryCheck\Service\WorkspaceService;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

final class WorkspaceContextTest extends TestCase
{
	private function context(mixed $id = 7, string $role = 'owner', string $header = ''): WorkspaceContext
	{
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->with('workspaceId')->willReturn($id);
		$request->method('getHeader')->with('X-IV-Workspace')->willReturn($header);
		$service = $this->createMock(WorkspaceService::class);
		$service->method('requireMember')->willReturnCallback(static function (string $uid, int $workspaceId) use ($role): array {
			if ($workspaceId !== 7) { throw new NotFoundException('unknown_workspace'); }
			return ['id' => 7, 'role' => $role, 'status' => 'accepted'];
		});
		$service->method('list')->willReturn([
			['id' => 8, 'role' => 'editor', 'status' => 'pending'],
			['id' => 7, 'role' => $role, 'status' => 'accepted'],
		]);
		$mapper = $this->createMock(WorkspaceMapper::class);
		$mapper->method('find')->willReturnCallback(static fn (int $id) => ['id' => $id]);
		return new WorkspaceContext($service, $mapper, $session, $request);
	}

	public function testDefaultSkipsPendingInvitations(): void
	{
		self::assertSame(7, $this->context(null)->id());
	}

	public function testExplicitScopeNeverFallsBackToAnotherWorkspace(): void
	{
		$this->expectException(NotFoundException::class);
		$this->context(8)->id();
	}

	public function testHeaderKeepsTabScopeIndependentOfOtherRequests(): void
	{
		self::assertSame(7, $this->context(8, 'editor', '7')->id());
	}

	/** @dataProvider invalidIds */
	public function testMalformedScopeIsRejected(mixed $id): void
	{
		$this->expectException(ValidationException::class);
		$this->context($id)->id();
	}

	public static function invalidIds(): array
	{
		return [[0], [-1], ['1 OR 1=1'], [[7]], ['7.5']];
	}

	public function testViewerCannotWriteAndCannotImpersonateAnEditor(): void
	{
		$context = $this->context(7, 'viewer');
		self::assertFalse($context->canEdit('alice'));
		self::assertFalse($context->canEdit('bob'));
		$this->expectException(PermissionDeniedException::class);
		$context->requireWrite();
	}

	public function testEditorRightsBelongToAuthenticatedActor(): void
	{
		$context = $this->context(7, 'editor');
		self::assertTrue($context->canEdit('alice'));
		self::assertFalse($context->canEdit('bob'));
		$context->requireWrite();
	}

	public function testDeviceScopeComesFromAuthenticatedDevice(): void
	{
		$context = $this->context(999);
		$context->forDevice(7);
		self::assertSame(7, $context->id());
		self::assertFalse($context->canEdit('alice'));
		$context->requireWrite();
	}

	public function testMaintenanceScopeIsRestoredEvenAfterAnException(): void
	{
		$context = $this->context(7, 'viewer');
		self::assertSame(7, $context->id());
		try {
			$context->runAsSystem(8, function () use ($context): void {
				self::assertSame(8, $context->id());
				$context->requireWrite();
				throw new \RuntimeException('test');
			});
		} catch (\RuntimeException $e) {
			self::assertSame('test', $e->getMessage());
		}
		self::assertSame(7, $context->id());
		$this->expectException(PermissionDeniedException::class);
		$context->requireWrite();
	}
}
