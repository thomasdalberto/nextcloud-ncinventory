<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Service;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/** Workspace preferences share the same request scope as the inventory ledger. */
class WorkspaceSettings
{
	public function __construct(private readonly IDBConnection $db, private readonly WorkspaceContext $workspace) {
	}

	public function id(): int
	{
		return $this->workspace->id();
	}

	public function get(string $key, string $default = ''): string
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('setting_value')->from('iv_ws_settings')
			->where($qb->expr()->eq('setting_key', $qb->createNamedParameter($key)));
		$this->workspace->apply($qb);
		$result = $qb->executeQuery();
		$value = $result->fetchOne();
		$result->closeCursor();
		return $value === false ? $default : (string)$value;
	}

	public function set(string $key, string $value): void
	{
		$this->db->insertIgnoreConflict('iv_ws_settings', [
			'workspace_id' => $this->id(), 'setting_key' => $key, 'setting_value' => $value,
		]);
		$qb = $this->db->getQueryBuilder();
		$qb->update('iv_ws_settings')->set('setting_value', $qb->createNamedParameter($value))
			->where($qb->expr()->eq('setting_key', $qb->createNamedParameter($key)));
		$this->workspace->apply($qb);
		$qb->executeStatement();
	}

	public function delete(string $key): void
	{
		$qb = $this->db->getQueryBuilder();
		$qb->delete('iv_ws_settings')->where($qb->expr()->eq('setting_key', $qb->createNamedParameter($key)));
		$this->workspace->apply($qb);
		$qb->executeStatement();
	}

	public function getList(string $key): array
	{
		$value = json_decode($this->get($key, '[]'), true);
		return is_array($value) ? array_values(array_filter($value, 'is_string')) : [];
	}

	public function setList(string $key, array $values): void
	{
		$this->set($key, json_encode(array_values(array_unique($values)), JSON_THROW_ON_ERROR));
	}
}
