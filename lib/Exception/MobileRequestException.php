<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Exception;

/**
 * Mobile gate ladder failure (SPEC §9.1).
 *
 * Most codes map to HTTP 402. Special cases:
 * - `auth_required` → 401 (rung 1)
 * - `rate_limited` → 429
 * - `idempotency_in_flight` → 409
 * - `idempotency_payload_mismatch` → 409
 */
class MobileRequestException extends \Exception
{
	public function __construct(
		private readonly string $errorCode,
	) {
		parent::__construct($errorCode);
	}

	public function getErrorCode(): string
	{
		return $this->errorCode;
	}
}
