<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Db;


/**
 * @method int getCampaignId()
 * @method void setCampaignId(int $v)
 * @method int getItemId()
 * @method void setItemId(int $v)
 * @method int getSystemQty()
 * @method void setSystemQty(int $v)
 * @method int|null getQtyCounted()
 * @method void setQtyCounted(?int $v)
 * @method int|null getPostedMovId()
 * @method void setPostedMovId(?int $v)
 * @method int getUpdatedAt()
 * @method void setUpdatedAt(int $v)
 */
class CycleLine extends WorkspaceEntity
{
	protected int $campaignId = 0;
	protected int $itemId = 0;
	/** Sentinel so legitimate 0 system qty still inserts (Entity skips unchanged defaults). */
	protected int $systemQty = \PHP_INT_MIN;
	protected ?int $qtyCounted = null;
	protected ?int $postedMovId = null;
	protected int $updatedAt = 0;

	public function __construct()
	{
		parent::__construct();
		$this->addType('campaignId', 'integer');
		$this->addType('itemId', 'integer');
		$this->addType('systemQty', 'integer');
		$this->addType('qtyCounted', 'integer');
		$this->addType('postedMovId', 'integer');
		$this->addType('updatedAt', 'integer');
	}

	/** @return array<string, mixed> */
	public function toApi(): array
	{
		return [
			'id' => (int)$this->getId(),
			'campaignId' => $this->campaignId,
			'itemId' => $this->itemId,
			'systemQty' => $this->systemQty,
			'qtyCounted' => $this->qtyCounted,
			'postedMovementId' => $this->postedMovId,
			'updatedAt' => $this->updatedAt,
		];
	}
}
