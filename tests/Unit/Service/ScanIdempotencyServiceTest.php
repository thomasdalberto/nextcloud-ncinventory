<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Tests\Unit\Service;

use OCA\InventoryCheck\Service\Clock;
use OCA\InventoryCheck\Service\ScanIdempotencyService;
use OCP\DB\Exception as DbException;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

final class ScanIdempotencyServiceTest extends TestCase
{
	public function testNormalizeRejectsGarbage(): void
	{
		$svc = new ScanIdempotencyService(
			$this->createMock(IDBConnection::class),
			new Clock(),
			$this->createMock(\OCA\InventoryCheck\Service\WorkspaceContext::class),
		);
		self::assertNull($svc->normalizeClientRequestId(''));
		self::assertNull($svc->normalizeClientRequestId('has space'));
		self::assertNull($svc->normalizeClientRequestId(str_repeat('a', 65)));
		self::assertNull($svc->normalizeClientRequestId(['x']));
		self::assertSame('ob-1-abc', $svc->normalizeClientRequestId('ob-1-abc'));
		self::assertSame('cf:42_ok', $svc->normalizeClientRequestId('cf:42_ok'));
	}

	public function testLookupReturnsDonePayload(): void
	{
		$result = $this->createMock(IResult::class);
		$result->method('fetch')->willReturn([
			'status' => 'done',
			'response_json' => '{"movements":[],"balances":[]}',
		]);
		$result->method('closeCursor');

		$expr = new class {
			public function eq(mixed ...$args): string
			{
				return 'eq';
			}
		};

		$qb = $this->createMock(IQueryBuilder::class);
		$qb->method('select')->willReturnSelf();
		$qb->method('from')->willReturnSelf();
		$qb->method('where')->willReturnSelf();
		$qb->method('andWhere')->willReturnSelf();
		$qb->method('expr')->willReturn($expr);
		$qb->method('createNamedParameter')->willReturnArgument(0);
		$qb->method('executeQuery')->willReturn($result);

		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturn($qb);

		$svc = new ScanIdempotencyService($db, new Clock(), $this->createMock(\OCA\InventoryCheck\Service\WorkspaceContext::class));
		$look = $svc->lookup('device:1', 'ob-1');
		self::assertSame('done', $look['status']);
		self::assertSame(['movements' => [], 'balances' => []], $look['response']);
		self::assertNull($look['payloadHash']);
	}

	public function testFingerprintChangesWhenQtyChanges(): void
	{
		$svc = new ScanIdempotencyService(
			$this->createMock(IDBConnection::class),
			new Clock(),
			$this->createMock(\OCA\InventoryCheck\Service\WorkspaceContext::class),
		);
		$a = $svc->fingerprintFromParams([
			'code' => 'SKU-1',
			'kind' => 'issue',
			'locationId' => 3,
			'qty' => '10',
		]);
		$b = $svc->fingerprintFromParams([
			'code' => 'SKU-1',
			'kind' => 'issue',
			'locationId' => 3,
			'qty' => '1',
		]);
		self::assertNotSame($a, $b);
		self::assertTrue($svc->payloadMatches($a, $a));
		self::assertFalse($svc->payloadMatches($a, $b));
		// Legacy rows without a stored hash still replay (upgrade safety).
		self::assertTrue($svc->payloadMatches(null, $b));
	}

	public function testTryClaimReturnsFalseOnUniqueViolation(): void
	{
		$qb = $this->createMock(IQueryBuilder::class);
		$qb->method('insert')->willReturnSelf();
		$qb->method('values')->willReturnSelf();
		$qb->method('createNamedParameter')->willReturnArgument(0);
		$qb->method('executeStatement')->willThrowException(
			new DbException('Duplicate entry', 23000),
		);

		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturn($qb);

		$svc = new ScanIdempotencyService($db, new Clock(), $this->createMock(\OCA\InventoryCheck\Service\WorkspaceContext::class));
		self::assertFalse($svc->tryClaim('alice', 'req-1', 'abc'));
	}
}
