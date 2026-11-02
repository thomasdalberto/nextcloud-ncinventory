<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Tests\Unit\Service;

use OCA\InventoryCheck\Exception\PermissionDeniedException;
use OCA\InventoryCheck\Service\WorkspaceInvitationLinks;
use OCP\IURLGenerator;
use OCP\Security\ICrypto;
use PHPUnit\Framework\TestCase;

final class WorkspaceInvitationLinksTest extends TestCase
{
	private function links(): WorkspaceInvitationLinks
	{
		$url = $this->createMock(IURLGenerator::class);
		$url->method('linkToRouteAbsolute')->willReturnCallback(static fn ($route, $params) => 'https://nc.test/workspaces?' . http_build_query($params));
		$crypto = $this->createMock(ICrypto::class);
		$crypto->method('calculateHMAC')->willReturnCallback(static fn ($value) => hash_hmac('sha512', $value, 'test-key', true));
		return new WorkspaceInvitationLinks($url, $crypto);
	}

	public function testSignedDecisionsVerifyForTheirRecipient(): void
	{
		$links = $this->links();
		foreach (['view', 'accept', 'decline'] as $decision) {
			parse_str(parse_url($links->link('alice', 7, 12, $decision), PHP_URL_QUERY), $params);
			$links->verify('alice', 7, 12, $decision, $params['invitationToken']);
			self::assertSame($decision, $params['decision']);
			self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43}$/', $params['invitationToken']);
		}
	}

	/** @dataProvider alteredActions */
	public function testChangingRecipientWorkspaceGenerationOrDecisionInvalidatesTheLink(string $uid, int $workspace, int $invitation, string $decision): void
	{
		$links = $this->links();
		parse_str(parse_url($links->link('alice', 7, 12, 'accept'), PHP_URL_QUERY), $params);
		$this->expectException(PermissionDeniedException::class);
		$links->verify($uid, $workspace, $invitation, $decision, $params['invitationToken']);
	}

	public static function alteredActions(): array
	{
		return [['bob', 7, 12, 'accept'], ['alice', 8, 12, 'accept'], ['alice', 7, 13, 'accept'],
			['alice', 7, 12, 'decline'], ['alice', 7, 12, 'view'], ['alice', 7, 12, 'delete']];
	}

	public function testUnsignedAutomaticActionIsRejected(): void
	{
		$this->expectException(PermissionDeniedException::class);
		$this->links()->verify('alice', 7, 12, 'accept', '');
	}
}
