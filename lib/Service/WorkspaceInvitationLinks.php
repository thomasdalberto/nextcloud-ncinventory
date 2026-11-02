<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Service;

use OCA\InventoryCheck\Exception\PermissionDeniedException;
use OCP\IURLGenerator;
use OCP\Security\ICrypto;

/** Bind one-click web/email actions to the recipient, invitation generation and decision. */
class WorkspaceInvitationLinks
{
	public function __construct(private readonly IURLGenerator $url, private readonly ICrypto $crypto) {
	}

	public function link(string $uid, int $workspaceId, int $invitationId, string $decision): string
	{
		return $this->url->linkToRouteAbsolute('inventorycheck.page.workspaces', [
			'invitationWorkspace' => $workspaceId, 'invitation' => $invitationId, 'decision' => $decision,
			'invitationToken' => $this->token($uid, $workspaceId, $invitationId, $decision),
		]);
	}

	public function verify(string $uid, int $workspaceId, int $invitationId, string $decision, string $token): void
	{
		if (!in_array($decision, ['view', 'accept', 'decline'], true)
			|| !hash_equals($this->token($uid, $workspaceId, $invitationId, $decision), $token)) {
			throw new PermissionDeniedException();
		}
	}

	private function token(string $uid, int $workspaceId, int $invitationId, string $decision): string
	{
		$mac = $this->crypto->calculateHMAC(json_encode([
			'inventorycheck:workspace-invitation', $uid, $workspaceId, $invitationId, $decision,
		], JSON_THROW_ON_ERROR));
		// A 256-bit MAC in base64url fits Nextcloud's 255-byte notification action URL limit.
		return rtrim(strtr(base64_encode(substr($mac, 0, 32)), '+/', '-_'), '=');
	}
}
