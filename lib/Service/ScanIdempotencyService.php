<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Service;

use OCP\DB\Exception as DbException;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * At-most-once companion scan bookings keyed by (actor, clientRequestId).
 *
 * Claim → scan → complete. Concurrent twins see pending or the stored JSON.
 * Failed scans release the claim so a genuine retry can proceed.
 *
 * Payload fingerprint binds the key to the booking body so a lost ACK cannot
 * silently “succeed” a later edit of qty/location under the same request id.
 */
class ScanIdempotencyService
{
	public const TABLE = 'iv_scan_idem';
	public const STATUS_PENDING = 'pending';
	public const STATUS_DONE = 'done';
	public const MAX_ID_LEN = 64;
	/** Pending claims older than this are abandoned (crash between commit and complete). */
	public const PENDING_STALE_SECONDS = 180;

	public function __construct(
		private readonly IDBConnection $db,
		private readonly Clock $clock,
		private readonly WorkspaceContext $workspace,
	) {
	}

	/**
	 * Optional client id. Invalid / empty → null (feature off for that call).
	 */
	public function normalizeClientRequestId(mixed $raw): ?string
	{
		if (!is_string($raw) && !is_int($raw)) {
			return null;
		}
		$id = trim((string)$raw);
		if ($id === '' || strlen($id) > self::MAX_ID_LEN) {
			return null;
		}
		if (preg_match('/^[A-Za-z0-9._:-]+$/', $id) !== 1) {
			return null;
		}
		return $id;
	}

	/**
	 * Stable SHA-256 of booking-material fields from the scan request params.
	 *
	 * @param array<string, mixed> $params
	 */
	public function fingerprintFromParams(array $params): string
	{
		$canon = [
			'code' => (string)($params['code'] ?? ''),
			'kind' => (string)($params['kind'] ?? ''),
			'locationId' => (int)($params['locationId'] ?? 0),
			'toLocationId' => array_key_exists('toLocationId', $params) && $params['toLocationId'] !== null && $params['toLocationId'] !== ''
				? (int)$params['toLocationId']
				: null,
			'qty' => $params['qty'] ?? null,
			'qtyDelta' => $params['qtyDelta'] ?? null,
			'lotCode' => $this->nullableString($params['lotCode'] ?? null),
			'reason' => $this->nullableString($params['reason'] ?? null),
			'reasonCode' => $this->nullableString(
				$params['reasonCode'] ?? ($params['reason_code'] ?? null),
			),
			'locationCode' => $this->nullableString(
				$params['locationCode'] ?? ($params['location_code'] ?? null),
			),
			'toLocationCode' => $this->nullableString(
				$params['toLocationCode'] ?? ($params['to_location_code'] ?? null),
			),
		];
		return hash('sha256', json_encode($canon, JSON_THROW_ON_ERROR));
	}

	public function payloadMatches(?string $storedHash, string $requestHash): bool
	{
		if ($storedHash === null || $storedHash === '') {
			// Legacy rows (pre-fingerprint): allow replay so upgrades do not brick
			// in-flight companions; new claims always stamp a hash.
			return true;
		}
		return hash_equals($storedHash, $requestHash);
	}

	/**
	 * @return array{status: string, response: ?array<string, mixed>, payloadHash: ?string}
	 */
	public function lookup(string $actorUid, string $requestId): array
	{
		$row = $this->fetchRow($actorUid, $requestId);
		if ($row === null) {
			return ['status' => 'missing', 'response' => null, 'payloadHash' => null];
		}
		$hash = isset($row['payload_hash']) && is_string($row['payload_hash']) && $row['payload_hash'] !== ''
			? $row['payload_hash']
			: null;
		if (($row['status'] ?? '') === self::STATUS_DONE && is_string($row['response_json'] ?? null)) {
			$decoded = json_decode((string)$row['response_json'], true);
			if (is_array($decoded)) {
				return ['status' => self::STATUS_DONE, 'response' => $decoded, 'payloadHash' => $hash];
			}
		}
		return ['status' => self::STATUS_PENDING, 'response' => null, 'payloadHash' => $hash];
	}

	/**
	 * Insert pending claim. False when another claim already owns the key.
	 */
	public function tryClaim(string $actorUid, string $requestId, ?string $payloadHash = null): bool
	{
		$this->workspace->requireWrite();
		$now = $this->clock->now();
		$qb = $this->db->getQueryBuilder();
		$qb->insert(self::TABLE)
			->values([
				'workspace_id' => $qb->createNamedParameter($this->workspace->id(), IQueryBuilder::PARAM_INT),
				'actor_uid' => $qb->createNamedParameter($actorUid),
				'request_id' => $qb->createNamedParameter($requestId),
				'status' => $qb->createNamedParameter(self::STATUS_PENDING),
				'response_json' => $qb->createNamedParameter(null),
				'payload_hash' => $qb->createNamedParameter($payloadHash),
				'created_at' => $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT),
				'completed_at' => $qb->createNamedParameter(null),
			]);
		try {
			$qb->executeStatement();
			return true;
		} catch (DbException $e) {
			if ($this->isUniqueViolation($e)) {
				return false;
			}
			throw $e;
		}
	}

	/**
	 * @param array<string, mixed> $response
	 */
	public function complete(string $actorUid, string $requestId, array $response): void
	{
		$this->workspace->requireWrite();
		$json = json_encode($response, JSON_THROW_ON_ERROR);
		$now = $this->clock->now();
		$qb = $this->db->getQueryBuilder();
		$qb->update(self::TABLE)
			->set('status', $qb->createNamedParameter(self::STATUS_DONE))
			->set('response_json', $qb->createNamedParameter($json))
			->set('completed_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT))
			->where($qb->expr()->eq('actor_uid', $qb->createNamedParameter($actorUid)))
			->andWhere($qb->expr()->eq('request_id', $qb->createNamedParameter($requestId)));
		$this->workspace->apply($qb);
		$qb->executeStatement();
	}

	/** Allow a later retry after scan threw (stock conflict, validation, etc.). */
	public function release(string $actorUid, string $requestId): void
	{
		$this->workspace->requireWrite();
		$qb = $this->db->getQueryBuilder();
		$qb->delete(self::TABLE)
			->where($qb->expr()->eq('actor_uid', $qb->createNamedParameter($actorUid)))
			->andWhere($qb->expr()->eq('request_id', $qb->createNamedParameter($requestId)))
			->andWhere($qb->expr()->eq('status', $qb->createNamedParameter(self::STATUS_PENDING)));
		$this->workspace->apply($qb);
		$qb->executeStatement();
	}

	/**
	 * Drop a PENDING claim that is older than {@see PENDING_STALE_SECONDS}.
	 * Used when a worker died after ledger commit but before complete(), so the
	 * companion is not stuck on idempotency_in_flight forever.
	 *
	 * Returns true when a stale row was removed (caller may tryClaim again).
	 */
	public function releaseStalePending(
		string $actorUid,
		string $requestId,
		int $maxAgeSeconds = self::PENDING_STALE_SECONDS,
	): bool {
		$row = $this->fetchRow($actorUid, $requestId);
		if ($row === null || ($row['status'] ?? '') !== self::STATUS_PENDING) {
			return false;
		}
		$created = (int)($row['created_at'] ?? 0);
		$age = $this->clock->now() - $created;
		if ($age < $maxAgeSeconds) {
			return false;
		}
		$this->release($actorUid, $requestId);
		return true;
	}

	/**
	 * @return array<string, mixed>|null
	 */
	private function fetchRow(string $actorUid, string $requestId): ?array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from(self::TABLE)
			->where($qb->expr()->eq('actor_uid', $qb->createNamedParameter($actorUid)))
			->andWhere($qb->expr()->eq('request_id', $qb->createNamedParameter($requestId)));
		$this->workspace->apply($qb);
		$res = $qb->executeQuery();
		$row = $res->fetch();
		$res->closeCursor();
		return is_array($row) ? $row : null;
	}

	private function isUniqueViolation(DbException $e): bool
	{
		$code = (string)$e->getCode();
		$msg = strtolower($e->getMessage());
		return $code === '23000'
			|| str_contains($msg, 'unique')
			|| str_contains($msg, 'duplicate');
	}

	private function nullableString(mixed $raw): ?string
	{
		if ($raw === null || $raw === '') {
			return null;
		}
		return (string)$raw;
	}
}
