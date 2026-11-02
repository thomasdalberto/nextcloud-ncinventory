<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Db;


/**
 * @method int getItemId()
 * @method void setItemId(int $v)
 * @method int getLocationId()
 * @method void setLocationId(int $v)
 * @method int getQty()
 * @method void setQty(int $v)
 * @method int getUpdatedAt()
 * @method void setUpdatedAt(int $v)
 */
class Balance extends WorkspaceEntity
{
	protected int $itemId = 0;
	protected int $locationId = 0;
	protected int $qty = 0;
	protected int $updatedAt = 0;

	public function __construct()
	{
		parent::__construct();
		$this->addType('itemId', 'integer');
		$this->addType('locationId', 'integer');
		$this->addType('qty', 'integer');
		$this->addType('updatedAt', 'integer');
	}

	/** @return array<string, mixed> */
	public function toApi(): array
	{
		return [
			'id' => (int)$this->getId(),
			'itemId' => $this->itemId,
			'locationId' => $this->locationId,
			'qty' => $this->qty,
			'updatedAt' => $this->updatedAt,
		];
	}
}
