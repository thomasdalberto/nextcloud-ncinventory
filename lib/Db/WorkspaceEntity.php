<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Db;

use OCP\AppFramework\Db\Entity;

/** @method int getWorkspaceId()
 * @method void setWorkspaceId(int $id)
 */
abstract class WorkspaceEntity extends Entity
{
	protected int $workspaceId = 0;

	public function __construct()
	{
		$this->addType('workspaceId', 'integer');
	}
}
