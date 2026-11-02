import test from 'node:test';
import assert from 'node:assert/strict';
import { createRequire } from 'node:module';

const require = createRequire(import.meta.url);
const { entityUrl, urlWithId, workspaceResourceUrl, workspaceInvitationIntent } = require('../../js/app.js');

test('entity navigation keeps the workspace after appending an item id', () => {
	assert.equal(entityUrl('/apps/inventorycheck/items?workspaceId=7', 42), '/apps/inventorycheck/items/42?workspaceId=7');
	assert.equal(entityUrl('/api/items/', 42), '/api/items/42');
});

test('route placeholders are replaced before a workspace query string', () => {
	assert.equal(urlWithId('/stocktake/0?workspaceId=7', 42), '/stocktake/42?workspaceId=7');
	assert.equal(urlWithId('/api/cycle-counts/0/start', 42), '/api/cycle-counts/42/start');
});

test('downloads, photos and print pages retain the originating tab workspace', () => {
	assert.equal(workspaceResourceUrl({ workspaceId: 7 }, '/photo?v=2'), '/photo?v=2&workspaceId=7');
	assert.equal(workspaceResourceUrl({ workspaceId: 8 }, '/label.svg'), '/label.svg?workspaceId=8');
	assert.equal(workspaceResourceUrl({}, '/label.svg'), '/label.svg');
});

test('signed invitation links select only this account’s exact pending invitation and decision', () => {
	const pending = { id: 7, invitationId: 12, status: 'pending' };
	const link = '?invitationWorkspace=7&invitation=12&invitationToken=signed-action&decision=';
	for (const decision of ['view', 'accept', 'decline']) {
		assert.deepEqual(workspaceInvitationIntent([pending], link + decision), { workspace: pending, decision, token: 'signed-action' });
	}
	assert.equal(workspaceInvitationIntent([], link + 'accept'), null);
	assert.equal(workspaceInvitationIntent([{ ...pending, invitationId: 13 }], link + 'accept'), null);
	assert.equal(workspaceInvitationIntent([{ ...pending, status: 'accepted' }], link + 'accept'), null);
	assert.equal(workspaceInvitationIntent([pending], link + 'delete'), null);
	assert.equal(workspaceInvitationIntent([pending], '?invitationWorkspace=7&invitation=12&decision=accept'), null);
});
