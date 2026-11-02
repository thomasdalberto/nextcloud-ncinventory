<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Db;


/**
 * @method string getSku()
 * @method void setSku(string $v)
 * @method string getScanCode()
 * @method void setScanCode(string $v)
 * @method string getName()
 * @method void setName(string $v)
 * @method string|null getDescription()
 * @method void setDescription(?string $v)
 * @method string getUom()
 * @method void setUom(string $v)
 * @method int getReorderLevel()
 * @method void setReorderLevel(int $v)
 * @method bool getActive()
 * @method void setActive(bool $v)
 * @method int getCreatedAt()
 * @method void setCreatedAt(int $v)
 * @method int getUpdatedAt()
 * @method void setUpdatedAt(int $v)
 * @method string getCreatedBy()
 * @method void setCreatedBy(string $v)
 * @method string|null getPhotoName()
 * @method void setPhotoName(?string $v)
 * @method string|null getPhotoMime()
 * @method void setPhotoMime(?string $v)
 * @method string|null getSupplierNote()
 * @method void setSupplierNote(?string $v)
 * @method int|null getLastPriceMinor()
 * @method void setLastPriceMinor(?int $v)
 * @method string getTrackMode()
 * @method void setTrackMode(string $v)
 * @method int|null getTargetStock()
 * @method void setTargetStock(?int $v)
 * @method int|null getDefaultLocationId()
 * @method void setDefaultLocationId(?int $v)
 */
class Item extends WorkspaceEntity
{
	protected string $sku = '';
	protected string $scanCode = '';
	protected string $name = '';
	protected ?string $description = null;
	protected string $uom = 'pcs';
	protected int $reorderLevel = 0;
	protected bool $active = true;
	protected int $createdAt = 0;
	protected int $updatedAt = 0;
	protected string $createdBy = '';
	protected ?string $photoName = null;
	protected ?string $photoMime = null;
	protected ?string $supplierNote = null;
	protected ?int $lastPriceMinor = null;
	/** Wave C2: none|lot|serial — see {@see \OCA\InventoryCheck\Service\CodeRules::TRACK_MODES}. */
	protected string $trackMode = 'none';
	/** Wave D4: optional order-up-to level (storage units). */
	protected ?int $targetStock = null;
	/** Wave D7: optional putaway / receive default location. */
	protected ?int $defaultLocationId = null;

	public function __construct()
	{
		parent::__construct();
		$this->addType('sku', 'string');
		$this->addType('scanCode', 'string');
		$this->addType('name', 'string');
		$this->addType('description', 'string');
		$this->addType('uom', 'string');
		$this->addType('reorderLevel', 'integer');
		$this->addType('active', 'boolean');
		$this->addType('createdAt', 'integer');
		$this->addType('updatedAt', 'integer');
		$this->addType('createdBy', 'string');
		$this->addType('photoName', 'string');
		$this->addType('photoMime', 'string');
		$this->addType('supplierNote', 'string');
		$this->addType('lastPriceMinor', 'integer');
		$this->addType('trackMode', 'string');
		$this->addType('targetStock', 'integer');
		$this->addType('defaultLocationId', 'integer');
	}

	/** @return array<string, mixed> */
	public function toApi(): array
	{
		return [
			'id' => (int)$this->getId(),
			'sku' => $this->sku,
			'scanCode' => $this->scanCode,
			'name' => $this->name,
			'description' => $this->description,
			'uom' => $this->uom,
			'reorderLevel' => $this->reorderLevel,
			'targetStock' => $this->targetStock,
			'defaultLocationId' => $this->defaultLocationId,
			'active' => $this->active,
			'hasPhoto' => $this->photoName !== null && $this->photoName !== '',
			'photoMime' => $this->photoMime,
			'supplierNote' => $this->supplierNote,
			'lastPriceMinor' => $this->lastPriceMinor,
			'trackMode' => $this->trackMode,
			'createdAt' => $this->createdAt,
			'updatedAt' => $this->updatedAt,
			'createdBy' => $this->createdBy,
		];
	}
}
