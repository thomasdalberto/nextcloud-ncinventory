<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Db;


/**
 * @method string getCode()
 * @method void setCode(string $v)
 * @method string getName()
 * @method void setName(string $v)
 * @method string getKind()
 * @method void setKind(string $v)
 * @method string|null getNotes()
 * @method void setNotes(?string $v)
 * @method bool getActive()
 * @method void setActive(bool $v)
 * @method int getCreatedAt()
 * @method void setCreatedAt(int $v)
 * @method int getUpdatedAt()
 * @method void setUpdatedAt(int $v)
 * @method string getCreatedBy()
 * @method void setCreatedBy(string $v)
 */
class Location extends WorkspaceEntity
{
	protected string $code = '';
	protected string $name = '';
	protected string $kind = 'other';
	protected ?string $notes = null;
	protected bool $active = true;
	protected int $createdAt = 0;
	protected int $updatedAt = 0;
	protected string $createdBy = '';

	public function __construct()
	{
		parent::__construct();
		$this->addType('code', 'string');
		$this->addType('name', 'string');
		$this->addType('kind', 'string');
		$this->addType('notes', 'string');
		$this->addType('active', 'boolean');
		$this->addType('createdAt', 'integer');
		$this->addType('updatedAt', 'integer');
		$this->addType('createdBy', 'string');
	}

	/** @return array<string, mixed> */
	public function toApi(): array
	{
		return [
			'id' => (int)$this->getId(),
			'code' => $this->code,
			'name' => $this->name,
			'kind' => $this->kind,
			'notes' => $this->notes,
			'active' => $this->active,
			'createdAt' => $this->createdAt,
			'updatedAt' => $this->updatedAt,
			'createdBy' => $this->createdBy,
		];
	}
}
