<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Service;

use OCA\InventoryCheck\Db\Item;
use OCA\InventoryCheck\Db\ItemMapper;
use OCA\InventoryCheck\Exception\NotFoundException;
use OCA\InventoryCheck\Exception\ValidationException;
use OCP\Files\IAppData;
use OCP\Files\NotFoundException as FilesNotFoundException;
use OCP\IDBConnection;

/**
 * One primary item photo via AppData (Wave A4). ≤ 2 MB; JPEG/PNG/WebP only.
 */
class ItemPhotoService
{
	public const MAX_BYTES = 2_097_152;
	public const FOLDER = 'item_photos';
	/** @var array<string, string> */
	public const ALLOWED = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];

	public function __construct(
		private readonly IDBConnection $db,
		private readonly IAppData $appData,
		private readonly ItemMapper $items,
		private readonly AccessControlService $access,
		private readonly Clock $clock,
		private readonly WorkspaceContext $workspace,
	) {
	}

	/**
	 * @param array<string, mixed> $file PHP upload array
	 * @return array<string, mixed>
	 */
	public function upload(string $actorUid, int $itemId, array $file): array
	{
		$this->access->requireWrite($actorUid);
		if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK
			|| !is_string($file['tmp_name'] ?? null)
			|| !is_uploaded_file($file['tmp_name'])) {
			throw new ValidationException('upload_failed', '', [['field' => 'file', 'code' => 'upload_failed']]);
		}
		$size = (int)($file['size'] ?? 0);
		if ($size <= 0 || $size > self::MAX_BYTES) {
			throw new ValidationException('photo_too_large', '', [['field' => 'file', 'code' => 'photo_too_large']]);
		}
		$raw = file_get_contents($file['tmp_name']);
		if ($raw === false || $raw === '') {
			throw new ValidationException('upload_failed', '', [['field' => 'file', 'code' => 'upload_failed']]);
		}
		$info = @getimagesizefromstring($raw);
		if ($info === false || !isset(self::ALLOWED[(string)$info['mime']])) {
			throw new ValidationException('photo_type_invalid', '', [['field' => 'file', 'code' => 'photo_type_invalid']]);
		}
		$mime = (string)$info['mime'];
		if ($info[0] < 16 || $info[1] < 16 || $info[0] > 8000 || $info[1] > 8000) {
			throw new ValidationException('photo_type_invalid', '', [['field' => 'file', 'code' => 'photo_type_invalid']]);
		}
		$clean = $this->reencode($raw, $mime);
		$ext = self::ALLOWED[$mime];
		$fileName = sprintf('item-%d-%s.%s', $itemId, bin2hex(random_bytes(8)), $ext);
		$folder = $this->folder();

		$this->db->beginTransaction();
		try {
			$item = $this->items->lockById($itemId, true);
			if (!$item->getActive()) {
				throw new ValidationException('inactive_item');
			}
			$old = $item->getPhotoName();
			$folder->newFile($fileName)->putContent($clean);
			$item->setPhotoName($fileName);
			$item->setPhotoMime($mime);
			$item->setUpdatedAt($this->clock->now());
			$this->items->update($item);
			$this->db->commit();
			if ($old !== null && $old !== '') {
				$this->tryDeleteFile($old);
			}
			return $item->toApi();
		} catch (\Throwable $e) {
			if ($this->db->inTransaction()) {
				$this->db->rollBack();
			}
			$this->tryDeleteFile($fileName);
			throw $e;
		}
	}

	public function delete(string $actorUid, int $itemId): array
	{
		$this->access->requireWrite($actorUid);
		$this->db->beginTransaction();
		try {
			$item = $this->items->lockById($itemId, true);
			if (!$item->getActive()) {
				throw new ValidationException('inactive_item');
			}
			$old = $item->getPhotoName();
			$item->setPhotoName(null);
			$item->setPhotoMime(null);
			$item->setUpdatedAt($this->clock->now());
			$api = $this->items->update($item)->toApi();
			$this->db->commit();
			if ($old !== null && $old !== '') {
				$this->tryDeleteFile($old);
			}
			return $api;
		} catch (\Throwable $e) {
			if ($this->db->inTransaction()) {
				$this->db->rollBack();
			}
			throw $e;
		}
	}

	/** @return array{content: string, mime: string, name: string} */
	public function read(int $itemId): array
	{
		$item = $this->items->findById($itemId);
		$name = $item->getPhotoName();
		$mime = $item->getPhotoMime();
		if ($name === null || $name === '' || $mime === null || $mime === '') {
			throw new NotFoundException('photo_not_found');
		}
		try {
			$content = $this->folder()->getFile($name)->getContent();
		} catch (FilesNotFoundException) {
			throw new NotFoundException('photo_not_found');
		}
		return ['content' => $content, 'mime' => $mime, 'name' => $name];
	}

	public static function workspaceFolder(int $id): string
	{
		return self::FOLDER . '_ws_' . $id;
	}

	private function folder(): \OCP\Files\SimpleFS\ISimpleFolder
	{
		try {
			return $this->appData->getFolder(self::workspaceFolder($this->workspace->id()));
		} catch (FilesNotFoundException) {
			return $this->appData->newFolder(self::workspaceFolder($this->workspace->id()));
		}
	}

	/** Best-effort blob removal (item-delete cleanup); never throws. */
	public function purgeFile(string $fileName): void
	{
		$this->tryDeleteFile($fileName);
	}

	private function tryDeleteFile(string $fileName): void
	{
		try {
			$this->folder()->getFile($fileName)->delete();
		} catch (\Throwable) {
		}
	}

	private function reencode(string $raw, string $mime): string
	{
		$img = @imagecreatefromstring($raw);
		if ($img === false) {
			throw new ValidationException('photo_type_invalid', '', [['field' => 'file', 'code' => 'photo_type_invalid']]);
		}
		ob_start();
		$ok = match ($mime) {
			'image/jpeg' => imagejpeg($img, null, 85),
			'image/png' => imagepng($img, null, 6),
			'image/webp' => function_exists('imagewebp') ? imagewebp($img, null, 85) : false,
			default => false,
		};
		imagedestroy($img);
		$out = ob_get_clean();
		if ($ok === false || $out === false || $out === '') {
			throw new ValidationException('photo_type_invalid', '', [['field' => 'file', 'code' => 'photo_type_invalid']]);
		}
		if (strlen($out) > self::MAX_BYTES) {
			throw new ValidationException('photo_too_large', '', [['field' => 'file', 'code' => 'photo_too_large']]);
		}
		return $out;
	}
}
