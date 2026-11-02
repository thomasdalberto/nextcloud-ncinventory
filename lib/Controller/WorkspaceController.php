<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Controller;

use OCA\InventoryCheck\AppInfo\Application;
use OCA\InventoryCheck\Exception\ValidationException;
use OCA\InventoryCheck\Service\AccessControlService;
use OCA\InventoryCheck\Service\BoolParam;
use OCA\InventoryCheck\Service\DirectoryOptionsService;
use OCA\InventoryCheck\Service\WorkspaceService;
use OCA\InventoryCheck\Service\WorkspaceInvitationLinks;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

class WorkspaceController extends Controller
{
	public function __construct(
		IRequest $request,
		private readonly AccessControlService $access,
		private readonly WorkspaceService $workspaces,
		private readonly DirectoryOptionsService $directory,
		private readonly WorkspaceInvitationLinks $links,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	#[NoAdminRequired]
	public function index(): JSONResponse
	{
		return new JSONResponse(['data' => $this->workspaces->list($this->access->currentUserId())]);
	}

	#[NoAdminRequired]
	public function create(): JSONResponse
	{
		return new JSONResponse($this->workspaces->create($this->access->currentUserId(),
			$this->stringParam('name', '')), 201);
	}

	#[NoAdminRequired]
	public function update(int $id): JSONResponse
	{
		return new JSONResponse($this->workspaces->rename($this->access->currentUserId(), $id,
			$this->stringParam('name', '')));
	}

	#[NoAdminRequired]
	public function destroy(int $id): JSONResponse
	{
		$this->workspaces->delete($this->access->currentUserId(), $id);
		return new JSONResponse([], 204);
	}

	#[NoAdminRequired]
	public function members(int $id): JSONResponse
	{
		return new JSONResponse(['data' => $this->workspaces->members($this->access->currentUserId(), $id)]);
	}

	#[NoAdminRequired]
	public function invite(int $id): JSONResponse
	{
		$this->workspaces->invite($this->access->currentUserId(), $id,
			$this->stringParam('userId', ''), $this->stringParam('role', 'editor'));
		return new JSONResponse([], 201);
	}

	#[NoAdminRequired]
	public function respond(int $id): JSONResponse
	{
		$this->workspaces->respond($this->access->currentUserId(), $id,
			BoolParam::parse($this->request->getParam('accept'), 'accept'), $this->invitationId());
		return new JSONResponse([]);
	}

	/** Signed notification links use the same CSRF-protected mutations as the workspace UI. */
	#[NoAdminRequired]
	public function notificationAction(int $id): JSONResponse
	{
		$uid = $this->access->currentUserId();
		$invitationId = $this->invitationId(true);
		$decision = $this->stringParam('decision', '');
		$this->links->verify($uid, $id, $invitationId, $decision, $this->stringParam('token', ''));
		if ($decision === 'view') {
			$this->workspaces->dismissInvitation($uid, $id, $invitationId);
		} else {
			$this->workspaces->respond($uid, $id, $decision === 'accept', $invitationId);
		}
		return new JSONResponse([]);
	}

	#[NoAdminRequired]
	public function removeMember(int $id): JSONResponse
	{
		$this->workspaces->removeMember($this->access->currentUserId(), $id,
			$this->stringParam('userId', ''));
		return new JSONResponse([], 204);
	}

	#[NoAdminRequired]
	public function changeRole(int $id): JSONResponse
	{
		$this->workspaces->changeRole($this->access->currentUserId(), $id,
			$this->stringParam('userId', ''), $this->stringParam('role', ''));
		return new JSONResponse([]);
	}

	/** Directory discovery is limited to an owner actively inviting into their workspace. */
	#[NoAdminRequired]
	public function searchUsers(int $id): JSONResponse
	{
		$this->workspaces->requireAdmin($this->access->currentUserId(), $id);
		$q = trim((string)$this->request->getParam('q', ''));
		return new JSONResponse(['users' => mb_strlen($q) < 2 ? [] : $this->directory->searchUsers($q, 20)]);
	}
	private function invitationId(bool $required = false): ?int
	{
		$value = $this->request->getParam('invitationId');
		if ($value === null && !$required) { return null; }
		if ((!is_string($value) && !is_int($value))
			|| filter_var($value, FILTER_VALIDATE_INT) === false || (int)$value < 1) {
			throw new ValidationException('validation_failed', '', [['field' => 'invitationId', 'code' => 'invalid_type']]);
		}
		return (int)$value;
	}

	private function stringParam(string $key, string $default): string
	{
		$value = $this->request->getParam($key, $default);
		if (!is_string($value)) {
			throw new ValidationException('validation_failed', '', [['field' => $key, 'code' => 'invalid_type']]);
		}
		return $value;
	}

}
