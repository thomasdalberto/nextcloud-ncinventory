<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Db;

use OCA\InventoryCheck\Exception\NotFoundException;
use OCA\InventoryCheck\Service\WorkspaceContext;
use OCP\AppFramework\Db\Entity;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Every inventory read and write belongs to one workspace.
 *
 * @template T of WorkspaceEntity
 * @extends QBMapper<T>
 */
abstract class WorkspaceScopedMapper extends QBMapper
{
	public function __construct(IDBConnection $db, string $table, string $entity, protected readonly WorkspaceContext $workspace)
	{
		parent::__construct($db, $table, $entity);
	}

	protected function findEntity(IQueryBuilder $query): Entity
	{
		$this->workspace->apply($query);
		return parent::findEntity($query);
	}

	protected function findEntities(IQueryBuilder $query): array
	{
		$this->workspace->apply($query);
		return parent::findEntities($query);
	}

	public function insert(Entity $entity): Entity
	{
		$this->workspace->requireWrite();
		$entity->setWorkspaceId($this->workspace->id());
		return parent::insert($entity);
	}

	public function update(Entity $entity): Entity
	{
		$this->assertEntity($entity);
		if ($entity->getId() === null) {
			throw new \InvalidArgumentException('Entity which should be updated has no id');
		}
		$properties = $entity->getUpdatedFields();
		unset($properties['id'], $properties['workspaceId']);
		if ($properties === []) {
			return $entity;
		}
		$qb = $this->db->getQueryBuilder();
		$qb->update($this->getTableName());
		foreach ($properties as $property => $_) {
			$getter = 'get' . ucfirst($property);
			$qb->set($entity->propertyToColumn($property), $qb->createNamedParameter(
				$entity->$getter(), $this->getParameterTypeForProperty($entity, $property)));
		}
		$qb->where($qb->expr()->eq('id', $qb->createNamedParameter($entity->getId(), IQueryBuilder::PARAM_INT)));
		$this->workspace->apply($qb);
		$qb->executeStatement();
		return $entity;
	}

	public function delete(Entity $entity): Entity
	{
		$this->assertEntity($entity);
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($entity->getId(), IQueryBuilder::PARAM_INT)));
		$this->workspace->apply($qb);
		$qb->executeStatement();
		return $entity;
	}

	private function assertEntity(Entity $entity): void
	{
		$this->workspace->requireWrite();
		if ($entity->getWorkspaceId() !== $this->workspace->id()) {
			throw new NotFoundException();
		}
	}
}
