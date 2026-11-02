<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Db;


/**
 * @method int getItemId()
 * @method void setItemId(int $v)
 * @method int getLocationId()
 * @method void setLocationId(int $v)
 * @method string getKind()
 * @method void setKind(string $v)
 * @method int getQtyDelta()
 * @method void setQtyDelta(int $v)
 * @method int getQtyAfter()
 * @method void setQtyAfter(int $v)
 * @method string|null getTransferGroup()
 * @method void setTransferGroup(?string $v)
 * @method int|null getCounterpartyLocId()
 * @method void setCounterpartyLocId(?int $v)
 * @method string|null getReason()
 * @method void setReason(?string $v)
 * @method string|null getRefType()
 * @method void setRefType(?string $v)
 * @method int|null getRefId()
 * @method void setRefId(?int $v)
 * @method int getCreatedAt()
 * @method void setCreatedAt(int $v)
 * @method string getCreatedBy()
 * @method void setCreatedBy(string $v)
 * @method string|null getLotCode()
 * @method void setLotCode(?string $v)
 * @method string|null getReasonCode()
 * @method void setReasonCode(?string $v)
 */
class Movement extends WorkspaceEntity
{
	protected int $itemId = 0;
	protected int $locationId = 0;
	protected string $kind = '';
	/** Sentinel so legitimate 0 deltas/afters are still inserted (Entity skips unchanged defaults). */
	protected int $qtyDelta = \PHP_INT_MIN;
	protected int $qtyAfter = \PHP_INT_MIN;
	protected ?string $transferGroup = null;
	protected ?int $counterpartyLocId = null;
	protected ?string $reason = null;
	protected ?string $refType = null;
	protected ?int $refId = null;
	protected int $createdAt = 0;
	protected string $createdBy = '';
	/** Wave C2: lot/serial code, only set when the item's track_mode requires one. */
	protected ?string $lotCode = null;
	/** Wave D3: adjust reason taxonomy code (nullable for non-adjust / legacy). */
	protected ?string $reasonCode = null;

	public function __construct()
	{
		parent::__construct();
		$this->addType('itemId', 'integer');
		$this->addType('locationId', 'integer');
		$this->addType('kind', 'string');
		$this->addType('qtyDelta', 'integer');
		$this->addType('qtyAfter', 'integer');
		$this->addType('transferGroup', 'string');
		$this->addType('counterpartyLocId', 'integer');
		$this->addType('reason', 'string');
		$this->addType('refType', 'string');
		$this->addType('refId', 'integer');
		$this->addType('createdAt', 'integer');
		$this->addType('createdBy', 'string');
		$this->addType('lotCode', 'string');
		$this->addType('reasonCode', 'string');
	}

	/** @return array<string, mixed> */
	public function toApi(): array
	{
		return [
			'id' => (int)$this->getId(),
			'itemId' => $this->itemId,
			'locationId' => $this->locationId,
			'kind' => $this->kind,
			'qtyDelta' => $this->qtyDelta,
			'qtyAfter' => $this->qtyAfter,
			'transferGroup' => $this->transferGroup,
			'counterpartyLocId' => $this->counterpartyLocId,
			'reason' => $this->reason,
			'reasonCode' => $this->reasonCode,
			'refType' => $this->refType,
			'refId' => $this->refId,
			'lotCode' => $this->lotCode,
			'createdAt' => $this->createdAt,
			'createdBy' => $this->createdBy,
		];
	}
}
