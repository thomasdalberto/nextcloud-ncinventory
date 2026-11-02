<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Db;


/**
 * @method string getUserId()
 * @method void setUserId(string $v)
 * @method int getLocationId()
 * @method void setLocationId(int $v)
 * @method int getCreatedAt()
 * @method void setCreatedAt(int $v)
 */
class LocationFavourite extends WorkspaceEntity
{
	protected string $userId = '';
	protected int $locationId = 0;
	protected int $createdAt = 0;

	public function __construct()
	{
		parent::__construct();
		$this->addType('userId', 'string');
		$this->addType('locationId', 'integer');
		$this->addType('createdAt', 'integer');
	}
}
