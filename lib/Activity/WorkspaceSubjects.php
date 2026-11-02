<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Activity;

use OCP\IL10N;

/** One vocabulary for workspace activities, notifications and notification emails. */
final class WorkspaceSubjects
{
	public const INVITED = 'workspace_invited';
	public const CREATED = 'workspace_created';
	public const RENAMED = 'workspace_renamed';
	public const DELETED = 'workspace_deleted';
	public const ACCEPTED = 'workspace_accepted';
	public const DECLINED = 'workspace_declined';
	public const REVOKED = 'workspace_invitation_revoked';
	public const REMOVED = 'workspace_member_removed';
	public const LEFT = 'workspace_member_left';
	public const ROLE_CHANGED = 'workspace_role_changed';

	public static function render(IL10N $l, string $subject, array $p): ?string
	{
		$actor = (string)($p['actorName'] ?? '');
		$name = (string)($p['workspaceName'] ?? '');
		$target = (string)($p['targetName'] ?? '');
		return match ($subject) {
			self::CREATED => $l->t('%1$s created workspace “%2$s”.', [$actor, $name]),
			self::RENAMED => $l->t('%1$s renamed workspace “%2$s” to “%3$s”.', [$actor, (string)($p['oldName'] ?? ''), $name]),
			self::DELETED => $l->t('%1$s deleted workspace “%2$s”.', [$actor, $name]),
			self::INVITED => $l->t('%1$s invited %2$s to workspace “%3$s”.', [$actor, $target, $name]),
			self::ACCEPTED => $l->t('%1$s joined workspace “%2$s”.', [$actor, $name]),
			self::DECLINED => $l->t('%1$s declined the invitation to workspace “%2$s”.', [$actor, $name]),
			self::REVOKED => $l->t('%1$s revoked the invitation for %2$s to workspace “%3$s”.', [$actor, $target, $name]),
			self::REMOVED => $l->t('%1$s removed %2$s from workspace “%3$s”.', [$actor, $target, $name]),
			self::LEFT => $l->t('%1$s left workspace “%2$s”.', [$actor, $name]),
			self::ROLE_CHANGED => $l->t('%1$s changed the role of %2$s in workspace “%3$s”.', [$actor, $target, $name]),
			default => null,
		};
	}

	public static function invitationMessage(IL10N $l, string $role): string
	{
		return match ($role) {
			'admin' => $l->t('You are invited as an admin. Accept or decline this invitation in InventoryCheck.'),
			'viewer' => $l->t('You are invited with read access. Accept or decline this invitation in InventoryCheck.'),
			default => $l->t('You are invited with write access. Accept or decline this invitation in InventoryCheck.'),
		};
	}
}
