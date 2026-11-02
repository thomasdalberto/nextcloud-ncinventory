<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Db;


/**
 * F8 outbox row — one per project-bound issue that should mirror its
 * material lines onto an InvoiceCheck draft.
 *
 * The row is written after the issue commits (the issue is the business
 * fact and never rolls back because a sibling is down). Status:
 *   pending — queued, push not attempted or failed softly (retryable)
 *   done    — InvoiceCheck acknowledged the append (created or replay)
 *   failed  — push rejected; `last_error` carries the why for the
 *             settings surface and the manual retry action
 *
 * @method int getId()
 * @method void setId(int $id)
 * @method string getIssueRef()
 * @method void setIssueRef(string $issueRef)
 * @method int getProjectId()
 * @method void setProjectId(int $projectId)
 * @method string getActorUid()
 * @method void setActorUid(string $actorUid)
 * @method string getPayloadJson()
 * @method void setPayloadJson(string $payloadJson)
 * @method string getStatus()
 * @method void setStatus(string $status)
 * @method int getAttempts()
 * @method void setAttempts(int $attempts)
 * @method string|null getLastError()
 * @method void setLastError(?string $lastError)
 * @method int getCreatedAt()
 * @method void setCreatedAt(int $createdAt)
 * @method int getUpdatedAt()
 * @method void setUpdatedAt(int $updatedAt)
 */
class InvoicePush extends WorkspaceEntity
{
	public const STATUS_PENDING = 'pending';
	public const STATUS_DONE = 'done';
	public const STATUS_FAILED = 'failed';

	protected string $issueRef = '';
	protected int $projectId = 0;
	protected string $actorUid = '';
	protected string $payloadJson = '';
	protected string $status = self::STATUS_PENDING;
	protected int $attempts = 0;
	protected ?string $lastError = null;
	protected int $createdAt = 0;
	protected int $updatedAt = 0;

	public function __construct()
	{
		parent::__construct();
		$this->addType('issueRef', 'string');
		$this->addType('projectId', 'integer');
		$this->addType('actorUid', 'string');
		$this->addType('payloadJson', 'string');
		$this->addType('status', 'string');
		$this->addType('attempts', 'integer');
		$this->addType('lastError', 'string');
		$this->addType('createdAt', 'integer');
		$this->addType('updatedAt', 'integer');
	}
}
