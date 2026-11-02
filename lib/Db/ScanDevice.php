<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Db;


/**
 * @method string getLabel()
 * @method void setLabel(string $v)
 * @method string|null getPairCodeHash()
 * @method void setPairCodeHash(?string $v)
 * @method int|null getPairCodeExpires()
 * @method void setPairCodeExpires(?int $v)
 * @method string|null getTokenHash()
 * @method void setTokenHash(?string $v)
 * @method int|null getPairedAt()
 * @method void setPairedAt(?int $v)
 * @method int|null getLastSeenAt()
 * @method void setLastSeenAt(?int $v)
 * @method bool getActive()
 * @method void setActive(bool $v)
 * @method int getCreatedAt()
 * @method void setCreatedAt(int $v)
 * @method string getCreatedBy()
 * @method void setCreatedBy(string $v)
 */
class ScanDevice extends WorkspaceEntity
{
	protected string $label = '';
	protected ?string $pairCodeHash = null;
	protected ?int $pairCodeExpires = null;
	protected ?string $tokenHash = null;
	protected ?int $pairedAt = null;
	protected ?int $lastSeenAt = null;
	protected bool $active = true;
	protected int $createdAt = 0;
	protected string $createdBy = '';

	public function __construct()
	{
		parent::__construct();
		$this->addType('label', 'string');
		$this->addType('pairCodeHash', 'string');
		$this->addType('pairCodeExpires', 'integer');
		$this->addType('tokenHash', 'string');
		$this->addType('pairedAt', 'integer');
		$this->addType('lastSeenAt', 'integer');
		$this->addType('active', 'boolean');
		$this->addType('createdAt', 'integer');
		$this->addType('createdBy', 'string');
	}

	/** @return array<string, mixed> */
	public function toApi(): array
	{
		$state = 'pending';
		if ($this->tokenHash !== null && $this->tokenHash !== '') {
			$state = $this->active ? 'paired' : 'inactive';
		} elseif ($this->pairCodeExpires !== null && $this->pairCodeExpires < time()) {
			$state = 'expired';
		}
		return [
			'id' => (int)$this->getId(),
			'label' => $this->label,
			'state' => $state,
			'pairedAt' => $this->pairedAt,
			'lastSeenAt' => $this->lastSeenAt,
			'active' => $this->active,
			'createdAt' => $this->createdAt,
			'createdBy' => $this->createdBy,
			'hasPendingPairCode' => $this->pairCodeHash !== null && $this->pairCodeHash !== '',
		];
	}
}
