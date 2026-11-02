<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Db;


/**
 * @method int getLocationId()
 * @method void setLocationId(int $v)
 * @method string getStatus()
 * @method void setStatus(string $v)
 * @method string getName()
 * @method void setName(string $v)
 * @method int getCreatedAt()
 * @method void setCreatedAt(int $v)
 * @method int getUpdatedAt()
 * @method void setUpdatedAt(int $v)
 * @method string getCreatedBy()
 * @method void setCreatedBy(string $v)
 * @method int|null getClosedAt()
 * @method void setClosedAt(?int $v)
 */
class CycleCampaign extends WorkspaceEntity
{
	protected int $locationId = 0;
	/** Empty default so setStatus('open') is marked dirty for INSERT (Entity skip-unchanged). */
	protected string $status = '';
	protected string $name = '';
	protected int $createdAt = 0;
	protected int $updatedAt = 0;
	protected string $createdBy = '';
	protected ?int $closedAt = null;

	public function __construct()
	{
		parent::__construct();
		$this->addType('locationId', 'integer');
		$this->addType('status', 'string');
		$this->addType('name', 'string');
		$this->addType('createdAt', 'integer');
		$this->addType('updatedAt', 'integer');
		$this->addType('createdBy', 'string');
		$this->addType('closedAt', 'integer');
	}

	/** @return array<string, mixed> */
	public function toApi(): array
	{
		return [
			'id' => (int)$this->getId(),
			'locationId' => $this->locationId,
			'status' => $this->status,
			'name' => $this->name,
			'createdAt' => $this->createdAt,
			'updatedAt' => $this->updatedAt,
			'createdBy' => $this->createdBy,
			'closedAt' => $this->closedAt,
		];
	}
}
