# Workspace model

Each inventory row belongs to exactly one workspace. A user can own multiple workspaces and can join other people's workspaces by invitation. Nextcloud account IDs identify members; there are no public sharing links.

| Role | Browse | Change inventory and stocktakes | Manage invitations, rename, delete |
| --- | --- | --- | --- |
| Owner | Yes | Yes | Yes |
| Editor | Yes | Yes | No |
| Viewer | Yes | No | No |
| Pending invitee | Invitation metadata only | No | No |

Instance administrators retain instance configuration privileges, but membership is required to access inventory. Existing service methods named `isOffice`/`requireOffice` delegate to the workspace's owner/editor role. Legacy Office configuration fields remain for API compatibility and notification defaults; they cannot grant inventory access.

## Implementation boundaries

- `WorkspaceMapper` persists ownership and invitations. `WorkspaceService` is the authorization policy: only accepted members can read, and only owners manage membership. A member can remove their own membership; an owner cannot be removed through that endpoint.
- `WorkspaceContext` holds the request's selected workspace. It authorizes the selection and supplies the SQL predicate. Missing or unauthorized explicit selections fail; they never fall back to another inventory. A viewer is denied writes in the middleware and persistence layer.
- Inventory entities inherit `WorkspaceEntity`. Their mappers inherit `WorkspaceScopedMapper`, which scopes entity reads, assigns workspace IDs on insertion, and scopes updates and deletes. Aggregate queries and locking reads explicitly use the same context. Location ACL and scan replay queries use it too.
- Codes are unique within a workspace. Items, locations, balances, movements, stocktakes, photos, favourites, location grants, notification deduplication, scan replay records and invoice push records use the same boundary. Photos live in `item_photos_ws_{id}` app-data folders.
- Scanner tokens determine their workspace from the server's device record. Request parameters cannot override it. License entitlement counts and mobile seats remain instance-wide.
- Trusted maintenance and cron jobs iterate workspace IDs explicitly with `runAsSystem`; there is no general unscoped mode. Account deletion removes live memberships and clears ownership identifiers so recreating a UID cannot regain old ownership.
- The first-use demo uses the original demo catalog in `DemoInventory`. A per-account initialization marker survives workspace deletion. Initialization holds a per-user lock and commits the workspace, demo records and marker together. Repair never seeds shared rows.

These boundaries follow the coherence goal in [The Decoherence Trap](https://maevyn.codes/decoherence): the existing controllers and inventory services use one workspace policy and one persistence boundary. There is no parallel inventory engine or client-side permission model.

## Web API

Browser requests use the existing Nextcloud session and CSRF request token. The workspace management endpoints use the signed-in user's identity; clients cannot supply another actor ID.

| Method | Path | Body / result |
| --- | --- | --- |
| GET | `/api/workspaces` | `{data: [...]}` containing owned workspaces, accepted memberships and pending invitations |
| POST | `/api/workspaces` | `{name}`; creates an empty workspace |
| PUT | `/api/workspaces/{id}` | `{name}`; owner only |
| DELETE | `/api/workspaces/{id}` | Permanently deletes the workspace; owner only |
| GET | `/api/workspaces/{id}/members` | `{data: [...]}`; owner only |
| POST | `/api/workspaces/{id}/members` | `{userId, role: "editor" | "viewer"}`; creates a pending invitation |
| DELETE | `/api/workspaces/{id}/members` | `{userId}`; owner removes a member/invitation, or member leaves |
| POST | `/api/workspaces/{id}/invitation` | `{accept: true | false, invitationId?: number}`; recipient accepts or declines their own invitation; clients should include its generation ID |
| POST | `/api/workspaces/{id}/invitation/notification` | `{invitationId, decision: "view" | "accept" | "decline", token}`; verifies a signed notification link and applies it for the signed-in recipient |
| GET | `/api/workspaces/{id}/users?q=...` | Existing directory search, restricted to the workspace owner |

Inventory APIs accept the selected workspace as `X-IV-Workspace: {id}` or `?workspaceId={id}`. The header takes precedence. Generated navigation links, photos, exports and print URLs retain the query parameter. The web API helper attaches the page's workspace header, with no global selected-workspace preference shared between tabs.

Without an explicit selection, a session defaults to its first accepted workspace. Users with no accepted workspaces are directed to Workspaces. Mobile session callers use the same selector; device-token callers use their bound workspace. Mobile bootstrap reports `workspaceId` and the `workspaces` capability.

## Activity and notifications

`WorkspaceService` publishes successful domain transitions through `WorkspaceEventService`. `WorkspaceSubjects` supplies the same translated vocabulary for the Activity provider and notification renderer. Activity events cover creation, renaming, deletion, invitation, acceptance, decline, revocation, removal and leaving. Owners, accepted members and the affected invitee receive events; other pending invitees do not receive internal changes. The Activity setting `inventorycheck_workspaces` groups these under InventoryCheck and allows optional Activity emails without duplicating invitation bell notifications by default. Delivery failures are logged without undoing committed membership changes. The invitation remains available on the recipient's Workspaces page.

The native Activity `Filter` is registered in `appinfo/info.xml` and adds InventoryCheck to Activity's sidebar. It includes workspace and low-stock events, restricted to this app and the signed-in recipient. Activity and invitation notification icons use `img/app-dark.svg`, a black monochrome variant for Nextcloud's built-in theme inversion. The white `app.svg` remains the global app icon, following the same light/dark asset convention as Deck.

Invitations use Nextcloud's notification manager and this app's existing notifier. Notification `WEB` actions work in both the bell and email. Clicking Accept or Decline opens Workspaces and immediately submits the response through a CSRF-protected POST, without a confirmation dialog. Opening the notification body submits a dismiss-only action, leaving the invitation pending on Workspaces. After success the page reloads without action parameters so Nextcloud's header immediately reflects the processed notification.

`WorkspaceInvitationLinks` signs each automatic action with Nextcloud's HMAC service. The signature binds the recipient, workspace ID, membership row ID and decision; the POST endpoint validates it against the current session. Plain or modified query parameters cannot authorize an automatic response. A raw GET never changes membership: the authenticated page submits the signed intent with its CSRF token. The membership row ID identifies the specific invitation generation: after revocation and re-invitation, earlier notifications and emails cannot act on the new invitation. Acceptance, decline, removal and workspace deletion process the matching notification. The notifier also rejects stale invitations at render time.

Nextcloud's Notifications app renders the same Accept/Decline actions in its HTML and plain-text notification emails. Configure Nextcloud's outgoing mail server, each recipient's email address, background jobs and their **Personal settings → Notifications → Email notifications** frequency. Email follows those preferences and is not immediate; a notification answered or dismissed before the digest is sent is omitted. No separate mail sender or scheduling system is added. Activity and Notifications must be enabled for their respective channels; the in-app Workspaces page remains available independently.

## Upgrade and maintenance

Migration `Version1010Date20261022090000` adds the ownership/membership tables, workspace columns and scoped unique indexes. Existing shared rows get workspace ID `0`, which is never a valid user selection. They remain hidden and are not imported; new accounts receive private demos. Old scanner slots must be recreated inside a workspace.

Backup and uninstall catalogs include the new tables. The balance rebuild command and invoice retry job process workspaces individually. Global quantity rescaling remains an instance-wide administrator operation because the quantity scale is shared by the instance.

The migration contains its own frozen table snapshot. When adding a future workspace table, add its schema in a new migration, include it in `WorkspaceService::DATA_TABLES`, and update backup/uninstall catalogs. Use the scoped mapper or context for every query. Keep authorization in `WorkspaceService`/`WorkspaceContext` rather than adding controller-specific exceptions.

The live PostgreSQL regression test is `tests/Integration/WorkspaceIsolationIntegrationTest.php`; it covers duplicate codes, balances, exports, stocktakes, invitation acceptance/revocation, viewer write denial, replay isolation, permanent demo deletion, Activity audiences, notification cleanup and Nextcloud's real HTML/plain-text email rendering. The email check sends no external mail. Run it using [the local Docker setup](dev/README.md).
