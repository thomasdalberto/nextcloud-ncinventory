/**
 * InventoryCheck web app (Check-family, no build step).
 *
 * Bootstrap reads #app-content[data-iv-*] and dispatches to a page module.
 * DOM construction only (never innerHTML with user data). Mutations go through
 * api() with CSRF request token; SPEC §7.1 errors become ApiError objects.
 */
(function () {
	'use strict';

	var APP = 'inventorycheck';

	function tr(text, vars) {
		if (typeof window !== 'undefined' && typeof window.t === 'function') {
			return window.t(APP, text, vars);
		}
		var out = text;
		if (vars) {
			Object.keys(vars).forEach(function (k) {
				out = out.replace('{' + k + '}', String(vars[k]));
			});
		}
		return out;
	}

	function kindMeta(kind) {
		switch (kind) {
			case 'receive': return { label: tr('Receive'), badge: 'iv-badge--done' };
			case 'issue': return { label: tr('Issue'), badge: 'iv-badge--overdue' };
			case 'transfer_out': return { label: tr('Transfer out'), badge: 'iv-badge--scheduled' };
			case 'transfer_in': return { label: tr('Transfer in'), badge: 'iv-badge--scheduled' };
			case 'adjust': return { label: tr('Adjust'), badge: 'iv-badge--neutral' };
			default: return { label: String(kind || ''), badge: '' };
		}
	}

	function locationKindLabel(kind) {
		switch (kind) {
			case 'warehouse': return tr('Warehouse');
			case 'shelf': return tr('Shelf');
			case 'van': return tr('Van');
			case 'site': return tr('Site');
			default: return tr('Other');
		}
	}

	function formatQty(n, scale) {
		if (n === null || n === undefined || n === '') return '0';
		if (typeof n === 'string') {
			// Already display-formatted by the API (may include decimals).
			return n;
		}
		var s = Number(scale);
		if (s === 3) {
			var abs = Math.abs(n);
			var whole = Math.floor(abs / 1000);
			var frac = abs % 1000;
			var out = frac === 0 ? String(whole) : (whole + '.' + String(frac).padStart(3, '0')).replace(/0+$/, '').replace(/\.$/, '');
			return n < 0 ? '-' + out : out;
		}
		return String(Number(n));
	}

	function qtyStep(ctx) {
		return (ctx && ctx.qtyScale === 3) ? '0.001' : '1';
	}

	function qtyInputAttrs(ctx, opts) {
		opts = opts || {};
		var attrs = {
			type: 'number',
			max: '1000000',
			required: opts.required === false ? undefined : '',
			className: 'iv-input',
			step: qtyStep(ctx),
			value: opts.value != null ? String(opts.value) : (opts.adjust ? '0' : '1'),
		};
		if (!opts.adjust) {
			attrs.min = opts.min != null ? String(opts.min) : (ctx && ctx.qtyScale === 3 ? '0.001' : '1');
		} else {
			attrs.min = '0';
		}
		return attrs;
	}

	/**
	 * Pure helpers exported for Node unit tests (no DOM).
	 * Reverse is offered once per transfer group — on the out leg only —
	 * so users cannot double-compensate by reversing both legs (UJ-6).
	 */
	function canReverseMovement(ctx, row) {
		if (!row || !row.kind) return false;
		if (row.kind === 'transfer_in') {
			return false;
		}
		if (row.kind === 'receive' || row.kind === 'transfer_out') {
			return true;
		}
		if (row.kind === 'issue' || row.kind === 'adjust') {
			return !!(ctx && (ctx.workspaceRole !== 'viewer' && ctx.canWrite));
		}
		return false;
	}

	function movementListQuery(filters) {
		var f = filters || {};
		var limit = f.limit != null ? f.limit : 50;
		var offset = f.offset != null ? f.offset : 0;
		var parts = ['limit=' + limit, 'offset=' + offset];
		if (f.kind) parts.push('kind=' + encodeURIComponent(f.kind));
		if (f.itemId) parts.push('itemId=' + encodeURIComponent(String(f.itemId)));
		if (f.locationId) parts.push('locationId=' + encodeURIComponent(String(f.locationId)));
		if (f.from) parts.push('from=' + encodeURIComponent(String(f.from)));
		if (f.to) parts.push('to=' + encodeURIComponent(String(f.to)));
		if (f.transferGroup) parts.push('transferGroup=' + encodeURIComponent(f.transferGroup));
		return parts.join('&');
	}

	function dateInputToUnix(value, endOfDay) {
		var s = String(value || '').trim();
		if (!s) return null;
		var parts = s.split('-');
		if (parts.length !== 3) return null;
		var y = Number(parts[0]);
		var m = Number(parts[1]);
		var d = Number(parts[2]);
		if (!y || !m || !d) return null;
		var dt = endOfDay
			? new Date(y, m - 1, d, 23, 59, 59)
			: new Date(y, m - 1, d, 0, 0, 0);
		return Math.floor(dt.getTime() / 1000);
	}

	function masterLabel(row, codeKey) {
		if (!row) return '';
		return row.name + ' (' + row[codeKey] + ')';
	}

	/**
	 * Resolve a typed Item/Location query to an id (exact SKU/code, label, or unique name).
	 * Returns '' when empty; null when ambiguous / unknown (caller shows inline error).
	 */
	function resolveMasterId(query, rows, codeKey) {
		var q = String(query || '').trim().toLowerCase();
		if (!q) return '';
		var exactCode = null;
		var exactLabel = null;
		var exactName = null;
		var nameHits = [];
		(rows || []).forEach(function (r) {
			var code = String(r[codeKey] || '').toLowerCase();
			var name = String(r.name || '').toLowerCase();
			var label = masterLabel(r, codeKey).toLowerCase();
			if (code === q) exactCode = r;
			if (label === q) exactLabel = r;
			if (name === q) {
				exactName = r;
				nameHits.push(r);
			} else if (name.indexOf(q) >= 0 || code.indexOf(q) >= 0 || label.indexOf(q) >= 0) {
				nameHits.push(r);
			}
		});
		if (exactCode) return String(exactCode.id);
		if (exactLabel) return String(exactLabel.id);
		if (exactName && nameHits.length === 1) return String(exactName.id);
		if (nameHits.length === 1) return String(nameHits[0].id);
		if (nameHits.length === 0) return null;
		return null;
	}

	var IvApp = {
		kindMeta: kindMeta,
		locationKindLabel: locationKindLabel,
		formatQty: formatQty,
		qtyStep: qtyStep,
		canReverseMovement: canReverseMovement,
		movementListQuery: movementListQuery,
		dateInputToUnix: dateInputToUnix,
		entityUrl: entityUrl,
		urlWithId: urlWithId,
		workspaceResourceUrl: workspaceResourceUrl,
		workspaceInvitationIntent: workspaceInvitationIntent,
		masterLabel: masterLabel,
		resolveMasterId: resolveMasterId,
		isValidCode: function (value, max) {
			return typeof value === 'string'
				&& value.length >= 1
				&& value.length <= max
				&& /^[A-Za-z0-9._/-]+$/.test(value);
		},
	};
	if (typeof window !== 'undefined') {
		window.IvApp = IvApp;
	}
	if (typeof module !== 'undefined' && module.exports) {
		module.exports = IvApp;
	}

	if (typeof document === 'undefined') {
		return;
	}

	function $(sel, root) {
		return (root || document).querySelector(sel);
	}

	function el(tag, attrs, children) {
		var node = document.createElement(tag);
		if (attrs) {
			Object.keys(attrs).forEach(function (k) {
				if (k === 'text') {
					node.textContent = attrs[k];
				} else if (k === 'className') {
					node.className = attrs[k];
				} else if (k.slice(0, 2) === 'on' && typeof attrs[k] === 'function') {
					node.addEventListener(k.slice(2).toLowerCase(), attrs[k]);
				} else if (attrs[k] !== null && attrs[k] !== undefined && attrs[k] !== false) {
					node.setAttribute(k, attrs[k] === true ? '' : String(attrs[k]));
				}
			});
		}
		(children || []).forEach(function (c) {
			if (c == null) return;
			node.appendChild(typeof c === 'string' ? document.createTextNode(c) : c);
		});
		return node;
	}

	function ApiError(code, message, details, status) {
		this.name = 'ApiError';
		this.code = code || 'error';
		this.message = message || tr('Something went wrong.');
		this.details = details || [];
		this.status = status || 0;
	}
	ApiError.prototype = Object.create(Error.prototype);

	function requestToken() {
		// Nextcloud 28+ / 34: token lives on OC.requestToken and <head data-requesttoken>.
		// The legacy <meta name="requesttoken"> is gone — empty headers → 412 CSRF on every API call.
		if (typeof window.OC !== 'undefined' && window.OC.requestToken) {
			return window.OC.requestToken;
		}
		var head = document.querySelector('head[data-requesttoken]');
		if (head) {
			return head.getAttribute('data-requesttoken') || '';
		}
		var meta = document.querySelector('head meta[name="requesttoken"]');
		return meta ? meta.getAttribute('content') : '';
	}

	function api(method, url, body) {
		var opts = {
			method: method,
			credentials: 'same-origin',
			headers: {
				'requesttoken': requestToken(),
				'Accept': 'application/json',
				'X-IV-Workspace': ($('#app-content') && $('#app-content').getAttribute('data-iv-workspace-id')) || '',
			},
		};
		if (body !== undefined) {
			opts.headers['Content-Type'] = 'application/json';
			opts.body = JSON.stringify(body);
		}
		return fetch(url, opts).then(function (res) {
			return res.json().catch(function () { return {}; }).then(function (data) {
				if (!res.ok) {
					var err = (data && data.error) || {};
					throw new ApiError(err.code, err.message, err.details, res.status);
				}
				return data;
			});
		}).catch(function (err) {
			if (err instanceof ApiError || (err && err.name === 'AbortError')) {
				throw err;
			}
			throw new ApiError('network_error', tr('No internet connection or the server cannot be reached.'), null, 0);
		});
	}

	/**
	 * Toast dedup (Atlas learned class): identical kind+text toasts must not
	 * stack — re-announcing the same toast resets its dismiss timer instead of
	 * appending a duplicate node. Keyed on kind|text; entries are dropped when
	 * the toast is removed.
	 */
	var liveToasts = new Map();
	var TOAST_TTL_ERROR = 5000;
	var TOAST_TTL_OK = 3000;

	function toast(message, isError) {
		var text = String(message || '');
		var region = $('#iv-toast-region');
		var live = isError ? $('#iv-alert-region') : $('#iv-live-region');
		if (live) {
			live.textContent = '';
			window.setTimeout(function () { live.textContent = text; }, 10);
		}
		if (!region) return;
		var key = (isError ? 'error' : 'ok') + '|' + text;
		var ttl = isError ? TOAST_TTL_ERROR : TOAST_TTL_OK;
		var existing = liveToasts.get(key);
		if (existing && existing.node.isConnected) {
			// Re-announce: restart the dismiss timer, keep the single node.
			window.clearTimeout(existing.timer);
			existing.timer = window.setTimeout(function () {
				existing.node.remove();
				liveToasts.delete(key);
			}, ttl);
			return;
		}
		var node = el('div', {
			className: 'toast iv-toast ' + (isError ? 'toast--error iv-toast--error' : 'toast--success iv-toast--ok'),
			role: isError ? 'alert' : 'status',
		}, [
			el('div', { className: 'toast-content iv-toast__message' }, [text]),
		]);
		var entry = { node: node, timer: 0 };
		entry.timer = window.setTimeout(function () {
			node.remove();
			liveToasts.delete(key);
		}, ttl);
		liveToasts.set(key, entry);
		region.appendChild(node);
	}

	function setBusy(root, busy) {
		if (!root) return;
		root.setAttribute('aria-busy', busy ? 'true' : 'false');
	}

	/**
	 * Honest click feedback: masters-dependent dialogs fetch before opening —
	 * mark the triggering button busy/disabled until the dialog mounts (or the
	 * load fails), so a queued API call never looks like a dead click.
	 */
	function busyTriggerUntil(node, promise) {
		if (!node || node.tagName !== 'BUTTON') return;
		node.setAttribute('aria-busy', 'true');
		node.disabled = true;
		var release = function () {
			if (!node.isConnected) return;
			node.removeAttribute('aria-busy');
			node.disabled = false;
		};
		promise.then(release, release);
	}

	function emptyState(title, hint, cta) {
		var iconSvg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
		iconSvg.setAttribute('viewBox', '0 0 24 24');
		iconSvg.setAttribute('fill', 'none');
		iconSvg.setAttribute('stroke', 'currentColor');
		iconSvg.setAttribute('stroke-width', '1.75');
		iconSvg.setAttribute('stroke-linecap', 'round');
		iconSvg.setAttribute('stroke-linejoin', 'round');
		iconSvg.setAttribute('class', 'iv-icon');
		iconSvg.setAttribute('aria-hidden', 'true');
		iconSvg.setAttribute('focusable', 'false');
		var poly = document.createElementNS('http://www.w3.org/2000/svg', 'polyline');
		poly.setAttribute('points', '22 12 16 12 14 15 10 15 8 12 2 12');
		var path = document.createElementNS('http://www.w3.org/2000/svg', 'path');
		path.setAttribute('d', 'M5.45 5.11 2 12v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-6l-3.45-6.89A2 2 0 0 0 16.76 4H7.24a2 2 0 0 0-1.79 1.11Z');
		iconSvg.appendChild(poly);
		iconSvg.appendChild(path);
		var icon = el('div', {
			className: 'iv-empty__icon iv-empty-state__icon',
			'aria-hidden': 'true',
		}, [iconSvg]);
		var mainKids = [
			el('h2', { className: 'iv-empty__title', text: title }),
			el('p', { className: 'iv-empty__text', text: hint }),
		];
		var main = el('div', { className: 'iv-empty__main iv-empty-state__main' }, mainKids);
		var kids = [icon, main];
		if (cta) {
			kids.push(el('div', { className: 'iv-empty__action iv-empty-state__action' }, [cta]));
		}
		return el('div', {
			className: 'iv-empty iv-empty-state',
			role: 'status',
		}, kids);
	}

	/** Bachus: never leave a blank mount on load failure — always offer Retry. */
	function loadErrorPanel(message, onRetry) {
		return emptyState(
			tr('Could not load this page'),
			message || tr('Something went wrong. Try again.'),
			onRetry ? btn(tr('Try again'), { primary: true, className: 'iv-btn--touch', onclick: onRetry }) : null
		);
	}

	/** Bachus: progressive disclosure for secondary settings / dashboard panels. */
	function disclosureSection(summaryText, kids, opts) {
		opts = opts || {};
		var body = el('div', { className: 'iv-disclosure__body' }, kids);
		return el('details', {
			className: 'iv-disclosure iv-section' + (opts.className ? ' ' + opts.className : ''),
			open: opts.open ? '' : null,
		}, [
			el('summary', {
				className: 'iv-disclosure__summary',
				text: summaryText,
			}),
			body,
		]);
	}

	/**
	 * Page-header overflow menu (native <details>).
	 * Body is a popover (CSS absolute) so opening it never stretches the action row
	 * or vertically centers the primary button against Export/Import.
	 */
	function actionsMoreMenu(summaryText, kids) {
		var body = el('div', {
			className: 'iv-actions-more__body',
			role: 'group',
			'aria-label': summaryText,
		}, kids);
		var details = el('details', { className: 'iv-actions-more' }, [
			el('summary', {
				className: 'iv-actions-more__summary',
				text: summaryText,
			}),
			body,
		]);
		function onDocPointer(ev) {
			if (!details.open) return;
			if (details.contains(ev.target)) return;
			details.open = false;
		}
		details.addEventListener('toggle', function () {
			if (details.open) {
				document.addEventListener('pointerdown', onDocPointer, true);
			} else {
				document.removeEventListener('pointerdown', onDocPointer, true);
			}
		});
		body.addEventListener('click', function (ev) {
			var t = ev.target;
			if (t && (t.closest('button') || t.closest('a.button') || t.closest('a.iv-btn'))) {
				details.open = false;
			}
		});
		return details;
	}

	/**
	 * DutyCheck-parity dismissible instruction card.
	 * Fail-open: if localStorage is blocked, tips stay visible.
	 */
	function wireDismissibleHint(card, button, userId, hintKey) {
		if (!card || !button || !hintKey) return;
		var storageKey = 'iv:hint:' + String(userId || 'anon') + ':' + hintKey;
		var dismissed = false;
		try {
			dismissed = window.localStorage.getItem(storageKey) === '1';
		} catch (e) { /* fail-open */ }
		if (dismissed) {
			card.setAttribute('hidden', '');
			return;
		}
		card.removeAttribute('hidden');
		button.addEventListener('click', function () {
			try {
				window.localStorage.setItem(storageKey, '1');
			} catch (e2) { /* ignore */ }
			card.setAttribute('hidden', '');
		});
	}

	/**
	 * DutyCheck / design-system dismissible numbered how-to card.
	 * Title is topic-specific (never generic “Quick start” — Access tip stays separate).
	 */
	function howToCard(ctx, opts) {
		opts = opts || {};
		var id = opts.id;
		var hintKey = opts.hintKey;
		var title = opts.title;
		var lead = opts.lead;
		var steps = opts.steps || [];
		if (!id || !hintKey || !title || !lead || !steps.length) {
			return null;
		}
		var titleId = id + '-title';
		var dismissBtn = el('button', {
			type: 'button',
			className: 'button iv-hint-dismiss',
			'aria-label': tr('Hide tips'),
			title: tr('Hide tips'),
			'aria-controls': id,
			'aria-describedby': titleId,
			text: tr('Hide tips'),
		});
		dismissBtn.setAttribute('data-iv-dismiss-hint', hintKey);

		var list = el('ol', { className: 'iv-quickstart' });
		steps.forEach(function (step) {
			var kids = [
				el('strong', { text: step.title }),
				el('p', { text: step.body }),
			];
			if (step.cta) {
				kids.push(el('p', { className: 'iv-quickstart__cta' }, [step.cta]));
			}
			list.appendChild(el('li', {
				className: 'iv-quickstart__item',
				'data-step': step.key || '',
			}, kids));
		});

		var card = el('section', {
			/* Never use .iv-empty here — that class is a 2-col empty-state grid and
			 * smashes the header beside the step list (DutyCheck stacks header above). */
			className: 'iv-card iv-howto'
				+ (opts.className ? (' ' + opts.className) : ''),
			id: id,
			role: 'region',
			'aria-labelledby': titleId,
			hidden: '',
		}, [
			el('header', { className: 'iv-section__header iv-quickstart__header' }, [
				el('div', { className: 'iv-quickstart__intro' }, [
					el('h2', { id: titleId, className: 'iv-card__title', text: title }),
					el('p', { className: 'iv-section__sub', text: lead }),
				]),
				dismissBtn,
			]),
			list,
		]);
		wireDismissibleHint(card, dismissBtn, ctx.userId, hintKey);
		return card;
	}

	function linkCta(href, label, primary) {
		if (!href) return null;
		return el('a', {
			href: href,
			className: primary ? 'button primary' : 'button',
			text: label,
		});
	}

	/**
	 * Stocktake create / list / field-count how-tos (DutyCheck parity).
	 */
	function stocktakeHowToCard(ctx, opts) {
		opts = opts || {};
		var variant = opts.variant || 'create';
		var id = opts.id || ('iv-stocktake-howto-' + variant);
		var hintKey = opts.hintKey || ('stocktake_' + variant + '_howto_v1');
		var canStart = ctx.workspaceRole !== 'viewer' && ctx.canWrite;
		var spec;
		if (variant === 'count') {
			spec = {
				id: id,
				hintKey: hintKey,
				className: 'iv-stocktake-howto',
				title: tr('How to count'),
				lead: tr('Open an open stocktake, type what is on the shelf, and save as you go.'),
				steps: [
					{
						key: 'open',
						title: tr('1. Open a stocktake below'),
						body: tr('Only campaigns marked Open or Counting accept counts. Tap the name to open the count screen.'),
					},
					{
						key: 'type',
						title: tr('2. Type the quantity on the shelf'),
						body: tr('Each line autosaves when you leave the field. Blind mode hides the system quantity so you count without bias.'),
					},
					{
						key: 'office-close',
						title: tr('3. Office closes when finished'),
						body: tr('Closing posts one stock adjustment per counted line so on-hand matches what was counted.'),
					},
				],
			};
		} else if (variant === 'list') {
			spec = {
				id: id,
				hintKey: hintKey,
				className: 'iv-stocktake-howto',
				title: tr('How a stocktake works'),
				lead: tr('Compare system stock with what is actually on the shelf — then post the difference in one close.'),
				steps: [
					{
						key: 'start',
						title: tr('1. Start a new stocktake'),
						body: tr('Choose the location you are standing in. We snapshot every active item there, then open the count screen.'),
						cta: canStart
							? btn(tr('New stocktake'), {
							'data-iv-action': 'stocktake-new',
								primary: true,
								onclick: function () { openNewCampaignDialog(ctx); },
							})
							: null,
					},
					{
						key: 'count',
						title: tr('2. Count on the shelf'),
						body: tr('Type the quantity for each item. Favourites and search help when you have many locations.'),
					},
					{
						key: 'close',
						title: tr('3. Close to post adjustments'),
						body: tr('When counting is done, Close stocktake writes one adjustment per counted line. Uncounted lines stay unchanged unless you confirm otherwise.'),
					},
				],
			};
		} else if (variant === 'detail') {
			spec = {
				id: id,
				hintKey: hintKey,
				className: 'iv-stocktake-howto',
				title: tr('How to count'),
				lead: tr('Type what is on the shelf. Each line saves when you leave the field.'),
				steps: [
					{
						key: 'type',
						title: tr('1. Type the quantity on the shelf'),
						body: tr('Leave a field to autosave. Press Enter to jump to the next line.'),
					},
					{
						key: 'options',
						title: tr('2. Use Counting options if you need them'),
						body: tr('Blind count hides system quantities so you count without bias. Open Counting options above the table.'),
					},
					{
						key: 'close',
						title: tr('3. Close when you are finished'),
						body: canStart
							? tr('Office: Close stocktake posts one adjustment per counted line so on-hand matches the shelf.')
							: tr('When counting is done, office closes the stocktake to post adjustments.'),
					},
				],
			};
		} else {
			spec = {
				id: id,
				hintKey: hintKey,
				className: 'iv-stocktake-howto',
				title: tr('How a stocktake works'),
				lead: tr('Three short steps — tap a location below when you are ready.'),
				steps: [
					{
						key: 'choose',
						title: tr('1. Choose where you are counting'),
						body: tr('Tap a location in the list. Favourites appear first. Search if you have many warehouses or vans.'),
					},
					{
						key: 'count',
						title: tr('2. Count what is on the shelf'),
						body: tr('The next screen lists every active item at that location. Type the real quantity; each line autosaves.'),
					},
					{
						key: 'close',
						title: tr('3. Close when you are finished'),
						body: tr('Closing posts stock adjustments so the system matches the shelf. You can leave uncounted lines unchanged if you confirm.'),
					},
				],
			};
		}
		return howToCard(ctx, spec);
	}

	/**
	 * Fleet-wide page how-tos (DutyCheck dashboard/roster/locations pattern).
	 * Settings → Access keeps the compact tip — never a 3-step essay.
	 */
	function pageHowToCard(ctx, page) {
		var pages = ctx.urls && ctx.urls.pages ? ctx.urls.pages : {};
		var canOffice = ctx.workspaceRole !== 'viewer' && ctx.canWrite;
		if (page === 'dashboard') {
			if (canOffice) {
				return howToCard(ctx, {
					id: 'iv-howto-dashboard',
					hintKey: 'dashboard_howto_v1',
					title: tr('How booking works'),
					lead: tr('Three short steps — then low stock and recent bookings appear below.'),
					steps: [
						{
							key: 'masters',
							title: tr('1. Create items and locations'),
							body: tr('Items need a SKU. Locations are warehouses, vans, or site boxes. Favourites speed later booking.'),
							cta: linkCta(pages.items, tr('Open Items'), true),
						},
						{
							key: 'receive',
							title: tr('2. Receive stock'),
							body: tr('Scan or pick an item and location, enter the quantity, and confirm. That starts the ledger.'),
							cta: btn(tr('Receive stock'), {
							'data-iv-action': 'receive',
								primary: true,
								onclick: function () { openReceiveDialog(ctx); },
							}),
						},
						{
							key: 'issue',
							title: tr('3. Issue or transfer as needed'),
							body: tr('Field users issue from vans. Transfer moves stock between locations. Every booking lands under Recent movements.'),
							cta: btn(tr('Issue stock'), {
							'data-iv-action': 'issue',
								onclick: function () { openIssueDialog(ctx); },
							}),
						},
					],
				});
			}
			return howToCard(ctx, {
				id: 'iv-howto-dashboard-field',
				hintKey: 'dashboard_field_howto_v1',
				title: tr('How to issue stock'),
				lead: tr('Three short steps when you take stock from a van or shelf.'),
				steps: [
					{
						key: 'open',
						title: tr('1. Tap Issue stock'),
						body: tr('Use the primary button above. Favourites put your usual locations first.'),
						cta: btn(tr('Issue stock'), {
							'data-iv-action': 'issue',
							primary: true,
							onclick: function () { openIssueDialog(ctx); },
						}),
					},
					{
						key: 'pick',
						title: tr('2. Pick item and location'),
						body: tr('Scan a barcode or type a SKU. Choose the location you are standing at.'),
					},
					{
						key: 'confirm',
						title: tr('3. Confirm the quantity'),
						body: tr('The booking is saved immediately and shows under Recent movements.'),
					},
				],
			});
		}
		if (page === 'items') {
			return howToCard(ctx, {
				id: 'iv-howto-items',
				hintKey: 'items_howto_v1',
				title: tr('How items work'),
				lead: tr('Create a SKU once, then receive stock and print labels.'),
				steps: [
					{
						key: 'create',
						title: tr('1. Create an item'),
						body: tr('SKU and name are enough. Reorder level and more options sit under More when you need them.'),
						cta: canOffice
							? btn(tr('New item'), {
								'data-iv-action': 'item-new',
								primary: true,
								onclick: function () { openItemDialog(ctx, null); },
							})
							: null,
					},
					{
						key: 'receive',
						title: tr('2. Receive into a location'),
						body: tr('Open the item, then Receive — or use Receive stock from the Dashboard. Balances appear on the item page.'),
					},
					{
						key: 'labels',
						title: tr('3. Print or scan labels'),
						body: tr('Use Print label on the item, or select several items for bulk labels. Scanners read the same code.'),
					},
				],
			});
		}
		if (page === 'locations') {
			return howToCard(ctx, {
				id: 'iv-howto-locations',
				hintKey: 'locations_howto_v1',
				title: tr('How locations work'),
				lead: tr('Places that hold stock — warehouses, vans, and site boxes.'),
				steps: [
					{
						key: 'create',
						title: tr('1. Add a location'),
						body: tr('Code and name are enough. Kind (warehouse, van, …) sits under More options.'),
						cta: canOffice
							? btn(tr('New location'), {
								primary: true,
								onclick: function () { openLocationDialog(ctx); },
							})
							: null,
					},
					{
						key: 'favourite',
						title: tr('2. Star favourites'),
						body: tr('Favourites appear first when you book stock or start a stocktake.'),
					},
					{
						key: 'stock',
						title: tr('3. Receive stock or run a stocktake'),
						body: tr('Receive into this place from the Dashboard, or start a stocktake here when you count the shelf.'),
						cta: canOffice && pages.stocktake
							? linkCta(pages.stocktake, tr('Open Stocktake'), false)
							: null,
					},
				],
			});
		}
		if (page === 'movements') {
			return howToCard(ctx, {
				id: 'iv-howto-movements',
				hintKey: 'movements_howto_v1',
				title: tr('How movements work'),
				lead: tr('Every receive, issue, transfer, and adjust is listed here — append-only.'),
				steps: [
					{
						key: 'book',
						title: tr('1. Book stock'),
						body: tr('Use Receive or Issue above (or from the Dashboard). Transfers and adjusts sit under More.'),
						cta: canOffice
							? btn(tr('Receive stock'), {
							'data-iv-action': 'receive',
								primary: true,
								onclick: function () { openReceiveDialog(ctx); },
							})
							: btn(tr('Issue stock'), {
							'data-iv-action': 'issue',
								primary: true,
								onclick: function () { openIssueDialog(ctx); },
							}),
					},
					{
						key: 'filter',
						title: tr('2. Filter the ledger'),
						body: tr('Narrow by kind, item, location, or dates. Results update as you choose. Clear resets the list.'),
					},
					{
						key: 'reverse',
						title: tr('3. Reverse a mistake'),
						body: tr('Reverse posts a compensating booking. History stays complete — nothing is deleted.'),
					},
				],
			});
		}
		if (page === 'item-detail') {
			return howToCard(ctx, {
				id: 'iv-howto-item-detail',
				hintKey: 'item_detail_howto_v1',
				title: tr('How this item page works'),
				lead: tr('Balances, booking, and labels for one SKU.'),
				steps: [
					{
						key: 'balances',
						title: tr('1. Read balances by location'),
						body: tr('Each row is on-hand at a place. Empty means this item is not stocked anywhere yet.'),
					},
					{
						key: 'book',
						title: tr('2. Receive or issue from a row'),
						body: tr('Use Receive / Issue on a balance line, or the buttons above. Favourites speed location pick.'),
						cta: canOffice
							? btn(tr('Receive stock'), {
							'data-iv-action': 'receive',
								primary: true,
								onclick: function () {
									openReceiveDialog(ctx, { itemId: Number(ctx.entityId) || undefined });
								},
							})
							: btn(tr('Issue stock'), {
							'data-iv-action': 'issue',
								primary: true,
								onclick: function () {
									openIssueDialog(ctx, { itemId: Number(ctx.entityId) || undefined });
								},
							}),
					},
					{
						key: 'label',
						title: tr('3. Print the label'),
						body: tr('Print label opens a print-ready sheet. Scanners read the same scan code shown above.'),
					},
				],
			});
		}
		if (page === 'location-detail') {
			return howToCard(ctx, {
				id: 'iv-howto-location-detail',
				hintKey: 'location_detail_howto_v1',
				title: tr('How this location page works'),
				lead: tr('Everything on hand here — plus favourites and labels.'),
				steps: [
					{
						key: 'stock',
						title: tr('1. See what is here'),
						body: tr('Non-zero balances list every item at this place. Receive or transfer if the list is empty.'),
					},
					{
						key: 'favourite',
						title: tr('2. Star this place if you use it often'),
						body: tr('Favourites appear first when booking stock or starting a stocktake.'),
					},
					{
						key: 'book',
						title: tr('3. Receive, issue, or count here'),
						body: tr('Book from the row actions, or start a stocktake for this location from Stocktake.'),
						cta: canOffice
							? btn(tr('Receive stock'), {
							'data-iv-action': 'receive',
								primary: true,
								onclick: function () {
									openReceiveDialog(ctx, { locationId: Number(ctx.entityId) || undefined });
								},
							})
							: null,
					},
				],
			});
		}
		if (page === 'settings-notifications') {
			return howToCard(ctx, {
				id: 'iv-howto-settings-notifications',
				hintKey: 'settings_notifications_howto_v1',
				title: tr('How reorder notices work'),
				lead: tr('One notice the first time an item drops below its reorder level.'),
				steps: [
					{
						key: 'who',
						title: tr('1. Choose who gets the notice'),
						body: tr('Add users and groups from the directory. Empty means nobody is notified.'),
					},
					{
						key: 'hint',
						title: tr('2. Optional: show low stock by location'),
						body: tr('Turn on the Dashboard hint when vans need their own reorder view.'),
					},
					{
						key: 'save',
						title: tr('3. Save'),
						body: tr('Notices fire once per drop below reorder — not on every small issue.'),
					},
				],
			});
		}
		if (page === 'settings-quantities') {
			return howToCard(ctx, {
				id: 'iv-howto-settings-quantities',
				hintKey: 'settings_quantities_howto_v1',
				title: tr('How fractional quantities work'),
				lead: tr('Only for cable metres and similar — enabling cannot be undone.'),
				steps: [
					{
						key: 'read',
						title: tr('1. Read the warning'),
						body: tr('Every quantity becomes milli-units (×1000). Existing numbers are scaled. You cannot switch back.'),
					},
					{
						key: 'enable',
						title: tr('2. Enable only if you need decimals'),
						body: tr('Skip this if you only book whole pieces. Confirm the dialog before enabling.'),
					},
					{
						key: 'book',
						title: tr('3. Book with up to three decimals'),
						body: tr('After reload, receive and issue accept fractional amounts.'),
					},
				],
			});
		}
		if (page === 'settings-location-access') {
			return howToCard(ctx, {
				id: 'iv-howto-settings-location-access',
				hintKey: 'settings_location_access_howto_v1',
				title: tr('How location access works'),
				lead: tr('Optional. Limit field users and scanners to granted places.'),
				steps: [
					{
						key: 'enable',
						title: tr('1. Turn location access on'),
						body: tr('Office and app admins still see every location. Field users only see grants.'),
					},
					{
						key: 'grant',
						title: tr('2. Grant locations'),
						body: tr('Pick a user, group, or scanner device, then the places they may use.'),
					},
					{
						key: 'devices',
						title: tr('3. Tighten scanners if needed'),
						body: tr('Strict device mode blocks scanners without an explicit location grant.'),
					},
				],
			});
		}
		if (page === 'settings-connections') {
			return howToCard(ctx, {
				id: 'iv-howto-settings-connections',
				hintKey: 'settings_connections_howto_v1',
				title: tr('How app connections work'),
				lead: tr('Other Check apps can issue stock for you when you allow it.'),
				steps: [
					{
						key: 'toggle',
						title: tr('1. Turn on a connection'),
						body: tr('Enable MaintenanceCheck or ProjectCheck only when those apps are installed and ready.'),
					},
					{
						key: 'auto',
						title: tr('2. Let them book issues'),
						body: tr('They post issue movements automatically — you still see every line under Movements.'),
					},
					{
						key: 'audit',
						title: tr('3. Audit the ledger'),
						body: tr('Filter Movements by item or date if something looks wrong. Reverse from Movements when needed.'),
					},
				],
			});
		}
		if (page === 'settings-policies') {
			return howToCard(ctx, {
				id: 'iv-howto-settings-policies',
				hintKey: 'settings_policies_howto_v1',
				title: tr('How warehouse policies work'),
				lead: tr('Hygiene knobs for adjust reasons and inventur location scans.'),
				steps: [
					{
						key: 'reason',
						title: tr('1. Require an adjust reason when auditors need it'),
						body: tr('When on, every adjust must pick a reason code before it saves.'),
					},
					{
						key: 'scan',
						title: tr('2. Require a location scan for inventur'),
						body: tr('When on, stocktake start asks you to scan the location label first.'),
					},
					{
						key: 'save',
						title: tr('3. Save'),
						body: tr('Policies apply to the next booking or stocktake — not to past lines.'),
					},
				],
			});
		}
		if (page === 'settings-product-lookup') {
			return howToCard(ctx, {
				id: 'iv-howto-settings-product-lookup',
				hintKey: 'settings_product_lookup_howto_v1',
				title: tr('How online product lookup works'),
				lead: tr('Optional. Free product databases can name an unknown barcode for you.'),
				steps: [
					{
						key: 'enable',
						title: tr('1. Turn lookup on'),
						body: tr('Only the barcode leaves your server — never names, stock, or users.'),
					},
					{
						key: 'scan',
						title: tr('2. Create an item and scan the barcode'),
						body: tr('Name, description, and an empty SKU fill in — everything else stays yours.'),
					},
					{
						key: 'review',
						title: tr('3. Review and save'),
						body: tr('Suggestions never save by themselves. Not found? Enter the details manually as before.'),
					},
				],
			});
		}
		return null;
	}

	/** Prepend a settings how-to when the section has one (Access keeps the tip). */
	function withSettingsHowTo(ctx, section, kids) {
		var card = pageHowToCard(ctx, 'settings-' + section);
		if (!card) {
			return kids;
		}
		return [card].concat(kids);
	}

	/**
	 * Access settings lockout tip — one sentence, dismissible.
	 * (Replaces the old 3-step essay that duplicated the form.)
	 */
	function settingsSrTitle(id, text) {
		return el('h2', { id: id, className: 'iv-section__title iv-sr-only', text: text });
	}

	/**
	 * Boxed settings toggle (design-system switch-field / DutyCheck checkbox row).
	 * Hint is optional one-line guidance under the control.
	 */
	function settingsSwitchField(inputEl, labelText, hintText) {
		var forId = inputEl.id || '';
		var kids = [
			el('label', { className: 'iv-switch iv-switch-field', for: forId || undefined }, [
				inputEl,
				el('span', { className: 'iv-switch__label', text: labelText }),
			]),
		];
		if (hintText) {
			kids.push(el('p', { className: 'iv-field__hint', text: hintText }));
		}
		return el('div', { className: 'iv-field iv-field--switch' }, kids);
	}

	function settingsFormActions(primaryBtn) {
		return el('div', { className: 'iv-form-actions' }, [primaryBtn]);
	}

	function settingsPageSection(sectionSlug, labelledById, kids) {
		return el('section', {
			className: 'iv-section iv-settings-page iv-card',
			'aria-labelledby': labelledById,
			'data-iv-settings-section': sectionSlug,
		}, kids);
	}

	/** Bachus: type-to-filter without an extra Search click. */
	function bindLiveSearch(input, onSearch, delayMs) {
		var timer = null;
		var delay = delayMs == null ? 220 : delayMs;
		input.addEventListener('input', function () {
			if (timer) window.clearTimeout(timer);
			timer = window.setTimeout(function () {
				timer = null;
				onSearch();
			}, delay);
		});
	}

	function readShell() {
		var root = $('#app-content');
		if (!root) return null;
		var urls = {};
		try { urls = JSON.parse(root.getAttribute('data-iv-urls') || '{}'); } catch (e) { urls = {}; }
		return {
			root: root,
			page: root.getAttribute('data-iv-page') || '',
			workspaceId: root.getAttribute('data-iv-workspace-id') || '',
			workspaceRole: root.getAttribute('data-iv-workspace-role') || '',
			settingsSection: root.getAttribute('data-iv-settings-section') || '',
			entityId: root.getAttribute('data-iv-entity-id'),
			userId: root.getAttribute('data-iv-current-user') || '',
			isWorkspaceAdmin: root.getAttribute('data-iv-is-workspace-admin') === '1',
			isSystemAdmin: root.getAttribute('data-iv-is-system-admin') === '1',
			canWrite: root.getAttribute('data-iv-can-write') === '1',
			allowNegative: root.getAttribute('data-iv-allow-negative') === '1',
			locationReorderHintEnabled: root.getAttribute('data-iv-location-reorder-hint') === '1',
			requireAdjustReason: root.getAttribute('data-iv-require-adjust-reason') === '1',
			requireLocationScan: root.getAttribute('data-iv-require-location-scan') === '1',
			productLookupEnabled: root.getAttribute('data-iv-product-lookup') === '1',
			productLookupAutofire: root.getAttribute('data-iv-product-lookup-autofire') === '1',
			qtyScale: Number(root.getAttribute('data-iv-qty-scale') || '0') || 0,
			urls: urls,
			mount: $('#iv-page-root'),
			actions: $('#iv-page-actions'),
		};
	}

	function clear(node) {
		while (node.firstChild) node.removeChild(node.firstChild);
	}

	var CLS_SR_FALLBACK = 'iv-btn--sr-fallback';

	function btn(label, opts) {
		opts = opts || {};
		var attrs = {
			type: opts.type || 'button',
			className: 'button' + (opts.primary ? ' primary' : '') + (opts.className ? ' ' + opts.className : ''),
			disabled: !!opts.disabled || ($('#app-content') && $('#app-content').getAttribute('data-iv-workspace-role') === 'viewer'
				&& ['receive', 'issue', 'transfer', 'adjust', 'reverse', 'item-new', 'stocktake-new'].indexOf(opts['data-iv-action']) !== -1),
			onclick: opts.onclick,
		};
		['aria-label', 'aria-pressed', 'aria-busy', 'title', 'id', 'data-iv-action'].forEach(function (key) {
			if (opts[key] != null && opts[key] !== '') {
				attrs[key] = opts[key];
			}
		});
		return el('button', attrs, [label]);
	}

	function field(labelText, input, opts) {
		opts = opts || {};
		var id = input.getAttribute('id') || ('iv-f-' + Math.random().toString(36).slice(2, 8));
		input.setAttribute('id', id);
		input.removeAttribute('aria-invalid');
		var errId = id + '-err';
		var err = el('p', {
			className: 'iv-field__error',
			id: errId,
			hidden: '',
			role: 'alert',
		});
		input.setAttribute('aria-describedby', [input.getAttribute('aria-describedby'), errId].filter(Boolean).join(' ').trim());
		var wrap = el('div', {
			className: 'iv-field',
			'data-iv-field': opts.name || input.getAttribute('name') || id,
		}, [
			el('label', { className: 'iv-field__label', for: id, text: labelText }),
			input,
			err,
		]);
		wrap._ivInput = input;
		wrap._ivError = err;
		return wrap;
	}

	function clearFieldErrors(root) {
		if (!root) return;
		root.querySelectorAll('.iv-field__error').forEach(function (node) {
			node.hidden = true;
			node.textContent = '';
		});
		root.querySelectorAll('[aria-invalid="true"]').forEach(function (node) {
			node.removeAttribute('aria-invalid');
		});
	}

	function applyFieldErrors(root, details, fallbackMessage) {
		clearFieldErrors(root);
		if (!details || !details.length) return false;
		var applied = false;
		details.forEach(function (d) {
			var name = d && d.field ? String(d.field) : '';
			if (!name) return;
			var wrap = root.querySelector('[data-iv-field="' + name + '"]');
			if (!wrap || !wrap._ivError || !wrap._ivInput) return;
			wrap._ivInput.setAttribute('aria-invalid', 'true');
			wrap._ivError.hidden = false;
			var text = d.message || fallbackMessage || '';
			if (!text) {
				// Server details carry {field, code} only — translate known codes,
				// never leak a raw machine code into the UI.
				var codeMessages = {
					invalid_code_format: tr('The code format is not valid. Use letters, digits, and . _ / - only.'),
					name_required: tr('Please check this field.'),
					unknown_user: tr('This Nextcloud user does not exist.'),
					unknown_group: tr('This Nextcloud group does not exist.'),
					unknown_location: tr('Choose a location'),
					inactive_item: tr('This item is deactivated.'),
					inactive_location: tr('This location is deactivated.'),
					required: tr('Please check this field.'),
					same_location: tr('Transfer source and destination must be different.'),
					validation_failed: tr('Please check this field.'),
				};
				text = codeMessages[d.code] || tr('Please check this field.');
			}
			wrap._ivError.textContent = text;
			applied = true;
		});
		return applied;
	}

	var DIALOG_INERT_IDS = ['header', 'app-navigation', 'iv-main-content', 'iv-page-actions'];
	/** True while loadMasters runs between Change-close and the replacement dialog. */
	var movementDialogOpening = false;
	var stocktakeCreating = false;
	var stocktakeOpenBusy = false;

	function setChromeInert(on) {
		DIALOG_INERT_IDS.forEach(function (id) {
			var node = document.getElementById(id);
			if (!node) return;
			if (on) {
				node.setAttribute('inert', '');
			} else {
				node.removeAttribute('inert');
			}
		});
	}

	function dialog(title, bodyNodes, onConfirm, confirmLabel, opts) {
		opts = opts || {};
		// Single-overlay guard: a second booking dialog while one is open causes
		// Change/Close to hit the wrong node and double-posts are easy.
		var existing = document.querySelector('.iv-dialog-overlay');
		if (existing) {
			var existingClose = existing.querySelector('.iv-dialog__close');
			if (existingClose) {
				existingClose.click();
			} else {
				existing.remove();
				document.body.style.overflow = '';
				if (!movementDialogOpening) {
					setChromeInert(false);
				}
			}
		}
		// Dialogs that open after an async wait (loadMasters) must restore focus to
		// the element that triggered them: busyTriggerUntil disables that trigger
		// while waiting, which blurs it, so document.activeElement here would be
		// <body>. Callers pass their pre-captured trigger via opts.restoreFocusTo.
		var previouslyFocused = (opts.restoreFocusTo && typeof opts.restoreFocusTo.focus === 'function')
			? opts.restoreFocusTo
			: document.activeElement;
		var hideConfirm = !!opts.hideConfirm || typeof onConfirm !== 'function';
		var overlay = el('div', { className: 'modal-backdrop iv-dialog-overlay', role: 'presentation' });
		var dialogEl = el('div', {
			className: 'modal iv-dialog' + (opts.dialogClass ? (' ' + opts.dialogClass) : ''),
			role: 'dialog',
			'aria-modal': 'true',
			'aria-labelledby': 'iv-dialog-title',
		});
		document.body.style.overflow = 'hidden';
		setChromeInert(true);
		movementDialogOpening = false;
		var onKey = null;
		var closed = false;
		var close = function () {
			if (closed) {
				return;
			}
			closed = true;
			if (onKey) {
				document.removeEventListener('keydown', onKey);
				onKey = null;
			}
			// Clear any soft-keyboard pad left on the dialog body (never leave giant padding).
			if (typeof window.IVKeepFocusedVisible === 'object'
				&& window.IVKeepFocusedVisible
				&& typeof window.IVKeepFocusedVisible.ensureKeyboardScrollRoom === 'function') {
				window.IVKeepFocusedVisible.ensureKeyboardScrollRoom(document, 0, null);
			}
			overlay.remove();
			document.body.style.overflow = '';
			if (!movementDialogOpening) {
				setChromeInert(false);
			}
			if (previouslyFocused && typeof previouslyFocused.focus === 'function') {
				try {
					var restoreTarget = previouslyFocused;
					// Trigger may live inside an auto-closed <details> (e.g. the
					// "More" overflow menu) — a hidden node cannot take focus and
					// the browser drops to <body>. Fall back to the summary.
					if (restoreTarget.isConnected && typeof restoreTarget.closest === 'function') {
						var closedDetails = restoreTarget.closest('details:not([open])');
						if (closedDetails) {
							var summary = closedDetails.querySelector('summary');
							if (summary) {
								restoreTarget = summary;
							}
						}
					}
					if (restoreTarget.isConnected) {
						restoreTarget.focus();
					}
				} catch (e) { /* ignore */ }
			}
		};
		dialogEl.appendChild(el('header', { className: 'iv-dialog__header' }, [
			el('h2', { id: 'iv-dialog-title', className: 'iv-dialog__title', text: title }),
			el('button', {
				type: 'button',
				className: 'button iv-dialog__close',
				'aria-label': tr('Close'),
				title: tr('Close'),
				onclick: close,
				text: '×',
			}),
		]));
		dialogEl.appendChild(el('div', { className: 'iv-dialog__body' }, bodyNodes));
		var bodyEl = dialogEl.querySelector('.iv-dialog__body');
		if (!hideConfirm) {
			var confirmBtn = btn(confirmLabel || tr('Save'), {
				'data-iv-action': 'dialog-confirm',
				primary: true,
				onclick: function () {
					if (confirmBtn.disabled) {
						return;
					}
					clearFieldErrors(bodyEl);
					confirmBtn.disabled = true;
					confirmBtn.setAttribute('aria-busy', 'true');
					Promise.resolve(onConfirm()).then(function (ok) {
						if (ok !== false) {
							close();
							return;
						}
						confirmBtn.disabled = false;
						confirmBtn.removeAttribute('aria-busy');
					}).catch(function (err) {
						confirmBtn.disabled = false;
						confirmBtn.removeAttribute('aria-busy');
						var inline = applyFieldErrors(bodyEl, err && err.details, err && err.message);
						toast(err.message || tr('Something went wrong.'), true);
						if (inline) {
							var bad = bodyEl.querySelector('[aria-invalid="true"]');
							if (bad && typeof bad.focus === 'function') bad.focus();
						}
					});
				},
			});
			// Explicit Cancel beside every confirm — the header × alone is not a
			// discoverable way out for novices (destructive-confirm bar), and
			// close() restores focus to the captured trigger.
			var cancelBtn = btn(tr('Cancel'), {
				onclick: function () { close(); },
			});
			dialogEl.appendChild(el('div', { className: 'iv-dialog__actions' }, [
				cancelBtn,
				confirmBtn,
			]));
		}
		overlay.appendChild(dialogEl);
		overlay.addEventListener('click', function (ev) {
			if (ev.target === overlay) close();
		});
		onKey = function (ev) {
			if (ev.key === 'Escape') {
				ev.preventDefault();
				close();
				return;
			}
			if (ev.key !== 'Tab') {
				return;
			}
			var nodes = dialogEl.querySelectorAll('button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])');
			var list = [];
			for (var i = 0; i < nodes.length; i++) {
				var n = nodes[i];
				if (!n.disabled && n.getAttribute('aria-hidden') !== 'true' && !n.hidden) {
					list.push(n);
				}
			}
			if (!list.length) {
				return;
			}
			var first = list[0];
			var last = list[list.length - 1];
			if (ev.shiftKey && document.activeElement === first) {
				ev.preventDefault();
				last.focus();
			} else if (!ev.shiftKey && document.activeElement === last) {
				ev.preventDefault();
				first.focus();
			}
		};
		document.addEventListener('keydown', onKey);
		document.body.appendChild(overlay);
		var focusable = dialogEl.querySelector('input:not([hidden]), select:not([hidden]), textarea:not([hidden]), button');
		if (focusable) focusable.focus();
		return { close: close, overlay: overlay, dialogEl: dialogEl, bodyEl: bodyEl };
	}

	function tableOrCards(columns, rows, opts) {
		opts = opts || {};
		var wrap = el('div', { className: 'iv-table-wrap' });
		// Bachus: card reflow on narrow viewports (page-patterns .iv-table--responsive).
		var table = el('table', { className: 'table iv-table iv-table--responsive' });
		if (opts.caption) {
			table.appendChild(el('caption', { className: 'iv-sr-only', text: opts.caption }));
		}
		table.appendChild(el('thead', null, [
			el('tr', null, columns.map(function (c) {
				return el('th', { scope: 'col', text: c.label });
			})),
		]));
		var tbody = el('tbody');
		rows.forEach(function (row) {
			tbody.appendChild(el('tr', null, columns.map(function (c) {
				var cell = c.render ? c.render(row) : (row[c.key] == null ? '' : String(row[c.key]));
				var isActions = c.label === tr('Actions') || c.actionsCell;
				return el('td', {
					'data-label': c.label,
					className: isActions ? 'actions-cell' : undefined,
				}, [
					typeof cell === 'string' || typeof cell === 'number'
						? document.createTextNode(String(cell))
						: cell,
				]);
			})));
		});
		table.appendChild(tbody);
		wrap.appendChild(table);
		return wrap;
	}

	function formatMovementWhen(ts) {
		var n = Number(ts);
		if (!n) {
			return '—';
		}
		try {
			return new Date(n * 1000).toLocaleString();
		} catch (e) {
			return String(ts);
		}
	}

	function mountVarianceSection(ctx, mount, itemMap, locMap) {
		var host = el('div', {
			className: 'iv-variance-panel',
			role: 'status',
			'aria-live': 'polite',
			'aria-busy': 'true',
		});
		host.appendChild(el('p', { className: 'iv-muted', text: tr('Loading variance…') }));
		// Bachus: title lives on the disclosure summary when nested.
		mount.appendChild(el('p', {
			className: 'iv-muted iv-section__hint',
			text: tr('Recent adjust movements for on-screen variance review.'),
		}));
		mount.appendChild(host);
		api('GET', ctx.urls.api.movements + '?kind=adjust&limit=50&offset=0').then(function (res) {
			return hydrateMaps(ctx, itemMap, locMap, res.data || []).then(function () { return res; });
		}).then(function (res) {
			clear(host);
			host.removeAttribute('aria-busy');
			host.setAttribute('aria-busy', 'false');
			if (!res.data || res.data.length === 0) {
				host.appendChild(el('p', {
					className: 'iv-muted',
					text: tr('No variance adjustments yet.'),
				}));
				return;
			}
			host.appendChild(tableOrCards(
				[
					{ label: tr('When'), render: function (r) { return formatMovementWhen(r.createdAt); } },
					{ label: tr('Item'), render: function (r) {
						return labelFromMap(itemMap, r.itemId, 'sku');
					} },
					{ label: tr('Location'), render: function (r) {
						return labelFromMap(locMap, r.locationId, 'code');
					} },
					{ label: tr('Delta'), render: function (r) { return formatQty(r.qtyDelta); } },
					{ label: tr('Reason code'), render: function (r) {
						return r.reasonCode ? String(r.reasonCode) : '—';
					} },
					{ label: tr('Reason'), render: function (r) {
						return r.reason ? String(r.reason) : '—';
					} },
				],
				res.data,
				{ caption: tr('Variance adjustments') }
			));
		}).catch(function (err) {
			clear(host);
			host.removeAttribute('role');
			host.setAttribute('aria-busy', 'false');
			host.appendChild(loadErrorPanel(
				tr('Could not load variance.') + (err && err.message ? ' ' + err.message : ''),
				function () {
					clear(mount);
					mountVarianceSection(ctx, mount, itemMap, locMap);
				}
			));
		});
	}

	// ── Pages ───────────────────────────────────────────────────────────

	function isMobileDashboard() {
		return !!(window.matchMedia && window.matchMedia('(max-width: 760px), (pointer: coarse)').matches);
	}

	/** Mobile scan-and-count workflow. Edits remain local until explicit confirmation. */
	function renderMobileScan(ctx) {
		var mount = ctx.mount;
		ctx.mobileScanActive = true;
		clear(mount);
		clear(ctx.actions);
		ctx.actions.appendChild(btn(tr('Back to dashboard'), {onclick: function () { stopCamera(); renderDashboard(ctx); }}));
		mount.appendChild(el('section', {className: 'iv-section iv-card iv-mobile-scan'}, [
			el('h2', {className: 'iv-section__title', text: tr('Scan item')}),
			el('p', {className: 'iv-muted', text: tr('Scan a barcode or enter the SKU or scan code to review stock by location.')}),
		]));
		var host = mount.lastChild;
		var entry = el('input', {type: 'search', className: 'iv-input', placeholder: tr('Scan or type SKU / code'), autocomplete: 'off', autocapitalize: 'off', spellcheck: 'false', 'aria-label': tr('Scan or type SKU / code')});
		var scanButton = btn(tr('Find item'), {primary: true, className: 'iv-btn--touch'});
		var cameraButton = btn(tr('Try camera again'), {className: 'iv-btn--touch'});
		var status = el('p', {className: 'iv-field__hint', role: 'status'});
		var result = el('div', {className: 'iv-mobile-scan-result'});
		var actions = el('div', {className: 'iv-form-actions'}, [scanButton, cameraButton]);
		host.appendChild(field(tr('Item code'), entry));
		host.appendChild(actions);
		host.appendChild(status);
		host.appendChild(result);
		var qrScanner = null;
		var busy = false;
		function stopCamera() {
			if (qrScanner) {
				try {qrScanner.stop(); qrScanner.destroy();} catch (e) { /* already stopped */ }
				qrScanner = null;
			}
			var video = host.querySelector('video');
			if (video) video.remove();
		}
		function showItem(code) {
			code = String(code || '').trim();
			if (!code || busy) return;
			busy = true;
			entry.value = code;
			clear(result);
			status.textContent = tr('Looking up item…');
			var path = (ctx.urls.api.itemByCode || '').replace('__CODE__', encodeURIComponent(code));
			Promise.all([
				api('GET', path),
			]).then(function (values) {
				var item = values[0];
				return Promise.all([
					Promise.resolve(item),
					api('GET', ctx.urls.api.locations + '?active=1&limit=200&offset=0'),
					api('GET', ctx.urls.api.balances + '?itemId=' + encodeURIComponent(item.id) + '&limit=200&offset=0'),
				]);
			}).then(function (values) {
				var item = values[0], locations = values[1].data || [], balances = values[2].data || [];
				status.textContent = '';
				if (item.trackMode && item.trackMode !== 'none') {
					result.appendChild(el('p', {className: 'iv-field__hint', text: tr('This item uses lot or serial tracking. Use Adjust stock to record a tracked count.')}));
					return;
				}
				var balMap = {};
				balances.forEach(function (b) {balMap[String(b.locationId)] = Number(b.qty) || 0;});
				var edits = {};
				var step = Number(ctx.qtyScale) === 3 ? 0.001 : 1;
				var panel = el('section', {className: 'iv-mobile-count'});
				panel.appendChild(el('header', {className: 'iv-mobile-count__header'}, [
					el('h3', {text: item.name}),
					el('p', {className: 'iv-muted', text: tr('SKU') + ': ' + item.sku + (item.scanCode !== item.sku ? ' · ' + tr('Scan code') + ': ' + item.scanCode : '')}),
				]));
				if (!locations.length) panel.appendChild(el('p', {className: 'iv-muted', text: tr('No active locations are available to you.')}));
				locations.forEach(function (location) {
					var original = Number(balMap[String(location.id)] || 0);
					var state = {original: original, delta: 0, total: original};
					edits[String(location.id)] = state;
					var deltaInput = el('input', {type: 'number', className: 'iv-input iv-count-delta', step: String(step), value: '0', 'aria-label': tr('Change at {location}', {location: location.name})});
					var totalInput = el('input', {type: 'number', className: 'iv-input iv-count-total', step: String(step), min: ctx.allowNegative ? null : '0', value: String(original), 'aria-label': tr('New total at {location}', {location: location.name})});
					var originalNode = el('strong', {text: formatQty(original)});
					var deltaNode = el('strong', {text: formatQty(0)});
					var totalNode = el('strong', {text: formatQty(original)});
					var minus = btn('−', {className: 'iv-count-step', 'aria-label': tr('Decrease by {qty}', {qty: formatQty(step)}), onclick: function () {setDelta(state.delta - step);}});
					var plus = btn('+', {className: 'iv-count-step', 'aria-label': tr('Increase by {qty}', {qty: formatQty(step)}), onclick: function () {setDelta(state.delta + step);}});
					function cleanQty(value) {var n = Number(value); if (!Number.isFinite(n)) return 0; n = ctx.qtyScale === 3 ? Math.round(n * 1000) / 1000 : Math.round(n); return !ctx.allowNegative && n < 0 ? 0 : n;}
					function paint() {
						state.delta = cleanQty(state.delta); state.total = cleanQty(state.total);
						deltaInput.value = String(state.delta); totalInput.value = String(state.total);
						deltaNode.textContent = (state.delta > 0 ? '+' : '') + formatQty(state.delta);
						totalNode.textContent = formatQty(state.total);
						row.classList.toggle('is-changed', state.delta !== 0);
						confirm.disabled = !Object.keys(edits).some(function (key) {return edits[key].delta !== 0;});
					}
					function setDelta(value) {state.delta = cleanQty(value); if (!ctx.allowNegative && state.original + state.delta < 0) state.delta = -state.original; state.total = cleanQty(state.original + state.delta); paint();}
					deltaInput.addEventListener('input', function () {setDelta(deltaInput.value);});
					totalInput.addEventListener('input', function () {state.total = cleanQty(totalInput.value); state.delta = cleanQty(state.total - state.original); paint();});
					var row = el('article', {className: 'iv-mobile-count__location'}, [
						el('h4', {text: location.name + ' · ' + location.code}),
						el('div', {className: 'iv-mobile-count__equation'}, [el('span', {}, [el('small', {text: tr('Original')}), originalNode]), el('span', {}, [el('small', {text: tr('Change')}), deltaNode]), el('span', {}, [el('small', {text: tr('New total')}), totalNode])]),
						el('p', {className: 'iv-muted', text: tr('Edit change amount or new total')}),
						el('div', {className: 'iv-mobile-count__inputs'}, [minus, deltaInput, plus, totalInput]),
					]);
					panel.appendChild(row);
				});
				var reasonCode = el('select', {className: 'iv-input', 'aria-label': tr('Reason code')});
				var confirm = btn(tr('Confirm changes'), {primary: true, className: 'iv-btn--touch', disabled: true});
				var reasonHost = el('div');
				if (ctx.requireAdjustReason) {
					reasonHost.appendChild(field(tr('Reason code'), reasonCode));
					api('GET', ctx.urls.api.reasonCodes).then(function (catalog) {
						clear(reasonCode); reasonCode.appendChild(el('option', {value: '', text: tr('Choose a reason code')}));
						(catalog.data || []).forEach(function (r) {reasonCode.appendChild(el('option', {value: r.code, text: r.labelEn || r.code}));});
					}).catch(function () {status.textContent = tr('Could not load reason codes. Try again.');});
				}
				confirm.addEventListener('click', function () {
					var changed = Object.keys(edits).filter(function (key) {return edits[key].delta !== 0;});
					if (!changed.length || (ctx.requireAdjustReason && !reasonCode.value)) {status.textContent = tr('Select a reason and change at least one location.'); return;}
					confirm.disabled = true; status.textContent = tr('Saving location adjustments…');
					var done = [];
					changed.reduce(function (promise, key) {
						return promise.then(function () {
							return api('POST', ctx.urls.api.movementAdjust, {itemId: Number(item.id), locationId: Number(key), mode: 'set', qty: String(edits[key].total), reason: tr('Mobile scanned count'), reasonCode: reasonCode.value || null}).then(function () {done.push(key);});
						});
					}, Promise.resolve()).then(function () {
						toast(tr('Stock adjusted at {count} locations.', {count: done.length}));
						showItem(item.scanCode || item.sku);
					}).catch(function (err) {
						toast(tr('Some adjustments could not be saved. Current balances are being refreshed.'), true);
						showItem(item.scanCode || item.sku);
					});
				});
				panel.appendChild(reasonHost);
				panel.appendChild(confirm);
				result.appendChild(panel);
			}).catch(function (err) {
				status.textContent = err.message || tr('Item not found. Try another code.');
			}).finally(function () {busy = false;});
		}
		scanButton.addEventListener('click', function () {showItem(entry.value);});
		entry.addEventListener('keydown', function (ev) {if (ev.key === 'Enter') {ev.preventDefault(); showItem(entry.value);}});
		function startCamera() {
			if (!window.isSecureContext) {
				status.textContent = tr('Camera access requires HTTPS on this device. You can still scan with a handheld scanner or type the code.'); return;
			}
			if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
				status.textContent = tr('This browser cannot access the camera. Use a handheld scanner or enter the code.'); return;
			}
			if (typeof window.QrScanner !== 'function') {
				status.textContent = tr('QR scanning is unavailable in this browser. Use a handheld scanner or enter the code.'); return;
			}
			status.textContent = tr('Point the camera at an item QR code or barcode.');
			var video = el('video', {className: 'iv-mobile-scan-video', autoplay: '', playsinline: '', muted: ''});
			host.insertBefore(video, result);
			qrScanner = new window.QrScanner(video, function (decoded) {
				var value = typeof decoded === 'string' ? decoded : decoded.data;
				stopCamera(); showItem(value);
			}, {
				preferredCamera: 'environment',
				maxScansPerSecond: 12,
				highlightScanRegion: false,
				highlightCodeOutline: false,
				calculateScanRegion: function (videoElement) {
					var width = videoElement.videoWidth, height = videoElement.videoHeight;
					var scale = Math.min(1, 800 / Math.max(width, height));
					return {
						x: 0, y: 0, width: width, height: height,
						downScaledWidth: Math.max(1, Math.round(width * scale)),
						downScaledHeight: Math.max(1, Math.round(height * scale)),
					};
				},
			});
			qrScanner.start().then(function () {
				status.textContent = tr('Camera ready. Point it at the item QR code.');
			}).catch(function (err) {
				status.textContent = err && err.name === 'NotAllowedError'
					? tr('Camera permission was denied. Allow camera access or enter the code manually.')
					: tr('Camera is unavailable. You can enter the code manually.');
				stopCamera();
			});
		}
		cameraButton.addEventListener('click', startCamera);
		// The dashboard action is itself a user gesture, so attempt camera startup
		// immediately; keep manual/hardware scanner entry available as a fallback.
		startCamera();
	}

	function renderDashboard(ctx) {
		ctx.mobileScanActive = false;
		var mount = ctx.mount;
		setBusy(mount, true);
		clear(mount);
		var fetches = [
			api('GET', ctx.urls.api.lowStock + '?limit=20&offset=0'),
			api('GET', ctx.urls.api.movements + '?limit=20&offset=0'),
		];
		if (!ctx.allowNegative) {
			fetches.push(api('GET', ctx.urls.api.balances + '?negative=1&limit=20&offset=0'));
		}
		if (ctx.locationReorderHintEnabled && ctx.urls.api.lowStockPerLocation) {
			fetches.push(api('GET', ctx.urls.api.lowStockPerLocation + '?limit=20&offset=0'));
		}
		Promise.all(fetches).then(function (results) {
			if (ctx.mobileScanActive) return;
			var low = results[0];
			var mov = results[1];
			var idx = 2;
			var negatives = { data: [] };
			if (!ctx.allowNegative) {
				negatives = results[idx++] || { data: [] };
			}
			var perLoc = { data: [] };
			if (ctx.locationReorderHintEnabled && ctx.urls.api.lowStockPerLocation) {
				perLoc = results[idx] || { data: [] };
			}
			clear(mount);
			setBusy(mount, false);

			clear(ctx.actions);
			var mobileDashboard = isMobileDashboard();
			if (mobileDashboard) {
				ctx.actions.appendChild(btn(tr('Scan'), {
					primary: true, className: 'iv-btn--touch', disabled: !ctx.canWrite,
					onclick: function () { renderMobileScan(ctx); },
				}));
			} else {
				// Desktop keeps the receive/issue shortcuts; transfer and adjust stay under More.
				if (ctx.workspaceRole !== 'viewer' && ctx.canWrite) {
					ctx.actions.appendChild(btn(tr('Receive stock'), {
						'data-iv-action': 'receive', primary: true, className: 'iv-btn--touch',
						onclick: function () { openReceiveDialog(ctx); },
					}));
				}
				ctx.actions.appendChild(btn(tr('Issue stock'), {
					'data-iv-action': 'issue', primary: !ctx.canWrite, className: 'iv-btn--touch',
					onclick: function () { openIssueDialog(ctx); },
				}));
			}
			var moreKids = [];
			if (mobileDashboard) {
				moreKids.push(btn(tr('Receive stock'), {onclick: function () { openReceiveDialog(ctx); }}));
				moreKids.push(btn(tr('Issue stock'), {onclick: function () { openIssueDialog(ctx); }}));
			}
			moreKids.push(btn(tr('Transfer stock'), {onclick: function () { openTransferDialog(ctx); }}));
			if (ctx.workspaceRole !== 'viewer' && ctx.canWrite) {
				moreKids.push(btn(tr('Adjust stock'), {
					onclick: function () { openAdjustDialog(ctx); },
				}));
				moreKids.push(el('a', {
					className: 'button',
					href: exportCsvHref(ctx, 'reorder'),
					text: tr('Export reorder CSV'),
				}));
				moreKids.push(el('a', {
					className: 'button',
					href: exportCsvHref(ctx, 'variance'),
					text: tr('Export variance CSV'),
				}));
			}
			ctx.actions.appendChild(actionsMoreMenu(tr('More'), moreKids));

			var dashHowTo = pageHowToCard(ctx, 'dashboard');
			if (dashHowTo) {
				mount.appendChild(dashHowTo);
			}

			return Promise.all([
				api('GET', ctx.urls.api.items + '?limit=200&offset=0&active=1'),
				api('GET', ctx.urls.api.locations + '?limit=200&offset=0&active=1'),
			]).then(function (lookups) {
				if (ctx.mobileScanActive) return;
				var itemMap = indexById(lookups[0].data);
				var locMap = indexById(lookups[1].data);

				if (!ctx.allowNegative && negatives.data && negatives.data.length) {
					mount.appendChild(el('section', {
						className: 'iv-section',
						'aria-labelledby': 'iv-neg-title',
					}, [
						el('h2', {
							id: 'iv-neg-title',
							className: 'iv-section__title',
							text: tr('Negative balances'),
						}),
						el('p', {
							className: 'iv-muted',
							text: tr('Negative stock is turned off. Set each line to zero to clear it.'),
						}),
						tableOrCards(
							[
								{ label: tr('Item'), render: function (r) {
									return labelFromMap(itemMap, r.itemId, 'sku');
								} },
								{ label: tr('Location'), render: function (r) {
									return labelFromMap(locMap, r.locationId, 'code');
								} },
								{ label: tr('On hand'), render: function (r) {
									return el('span', {
										className: 'iv-badge iv-badge--overdue',
										text: formatQty(r.qty) + ' — ' + tr('negative stock'),
									});
								} },
								{ label: tr('Actions'), render: function (r) {
									if (ctx.workspaceRole === 'viewer' || !ctx.canWrite) {
										return el('span', { className: 'iv-muted', text: '—' });
									}
									return el('div', { className: 'iv-row__actions', 'data-iv-neg-actions': '1' }, [
										btn(tr('Set to zero'), {
											primary: true,
											className: 'iv-btn--touch',
											onclick: function () {
												openAdjustDialog(ctx, {
													itemId: r.itemId,
													locationId: r.locationId,
													qty: 0,
												});
											},
										}),
									]);
								} },
							],
							negatives.data,
							{ caption: tr('Negative balances') }
						),
					]));
				}

				mount.appendChild(el('section', { className: 'iv-section', 'aria-labelledby': 'iv-low-title' }, [
					el('h2', { id: 'iv-low-title', className: 'iv-section__title', text: tr('Low stock') }),
					low.data.length === 0
						? el('p', { className: 'iv-muted', text: tr('Nothing is below reorder level.') })
						: tableOrCards(
							[
								{ label: tr('Item'), render: function (r) { return r.item.name; } },
								{ label: tr('SKU'), render: function (r) { return r.item.sku; } },
								{ label: tr('On hand'), render: function (r) { return formatQty(r.totalQty); } },
								{ label: tr('Reorder at'), render: function (r) { return formatQty(r.reorderLevel); } },
								{ label: tr('Suggested'), render: function (r) { return formatQty(r.suggestedQty); } },
								{ label: tr('Actions'), render: function (r) {
									var kids = [];
									if (ctx.workspaceRole !== 'viewer' && ctx.canWrite) {
										kids.push(btn(tr('Receive'), {
											primary: true,
											className: 'iv-btn--touch',
											onclick: function () {
												openReceiveDialog(ctx, {
													itemId: r.item.id,
													qty: r.suggestedQty != null ? r.suggestedQty : undefined,
												});
											},
										}));
									} else {
										kids.push(el('span', { className: 'iv-muted', text: '—' }));
									}
									return el('div', { className: 'iv-row__actions', 'data-iv-low-stock-actions': '1' }, kids);
								} },
							],
							low.data,
							{ caption: tr('Low stock') }
						),
				]));

				// Bachus: variance is expert chrome — load only when opened.
				var varianceHost = el('div');
				var varianceDisclosure = disclosureSection(tr('Variance adjustments (optional)'), [varianceHost], {
					className: 'iv-variance-disclosure',
					open: false,
				});
				var varianceLoaded = false;
				varianceDisclosure.addEventListener('toggle', function () {
					if (varianceDisclosure.open && !varianceLoaded) {
						varianceLoaded = true;
						mountVarianceSection(ctx, varianceHost, itemMap, locMap);
					}
				});
				mount.appendChild(varianceDisclosure);

				// Bachus: hide empty per-location chrome — only surface when there are shortfalls.
				if (ctx.locationReorderHintEnabled && perLoc.data && perLoc.data.length > 0) {
					mount.appendChild(el('section', {
						className: 'iv-section',
						'aria-labelledby': 'iv-perloc-title',
					}, [
						el('h2', {
							id: 'iv-perloc-title',
							className: 'iv-section__title',
							text: tr('Low stock by location'),
						}),
						el('p', {
							className: 'iv-muted',
							text: tr('Hints where a single location is below the item reorder level.'),
						}),
						tableOrCards(
							[
								{ label: tr('Item'), render: function (r) { return r.item.name; } },
								{ label: tr('Location'), render: function (r) {
									return labelFromMap(locMap, r.locationId, 'code');
								} },
								{ label: tr('On hand'), render: function (r) { return formatQty(r.qty); } },
								{ label: tr('Reorder at'), render: function (r) { return formatQty(r.reorderLevel); } },
								{ label: tr('Actions'), render: function (r) {
									if (ctx.workspaceRole === 'viewer' || !ctx.canWrite) {
										return el('span', { className: 'iv-muted', text: '—' });
									}
									return el('div', { className: 'iv-row__actions', 'data-iv-perloc-actions': '1' }, [
										btn(tr('Receive'), {
											primary: true,
											className: 'iv-btn--touch',
											onclick: function () {
												openReceiveDialog(ctx, {
													itemId: r.item.id,
													locationId: r.locationId,
												});
											},
										}),
									]);
								} },
							],
							perLoc.data,
							{ caption: tr('Low stock by location') }
						),
					]));
				}

				mount.appendChild(el('section', { className: 'iv-section', 'aria-labelledby': 'iv-mov-title' }, [
					el('h2', { id: 'iv-mov-title', className: 'iv-section__title', text: tr('Recent movements') }),
					mov.data.length === 0
						? emptyState(
							tr('No movements yet'),
							tr('Receive stock to start the ledger.'),
							(ctx.workspaceRole !== 'viewer' && ctx.canWrite)
								? btn(tr('Receive stock'), {
							'data-iv-action': 'receive', primary: true, onclick: function () { openReceiveDialog(ctx); } })
								: null
						)
						: tableOrCards(
							[
								{ label: tr('Kind'), render: function (r) {
									var m = kindMeta(r.kind);
									return el('span', { className: 'iv-badge ' + m.badge, text: m.label });
								} },
								{ label: tr('Item'), render: function (r) {
									return labelFromMap(itemMap, r.itemId, 'sku');
								} },
								{ label: tr('Location'), render: function (r) {
									return labelFromMap(locMap, r.locationId, 'code');
								} },
								{ label: tr('Delta'), render: function (r) { return formatQty(r.qtyDelta); } },
								{ label: tr('After'), render: function (r) {
									var n = Number(r.qtyAfter);
									if (n < 0) {
										return el('span', {
											className: 'iv-badge iv-badge--overdue',
											text: formatQty(n) + ' — ' + tr('negative stock'),
										});
									}
									return formatQty(n);
								} },
							],
							mov.data,
							{ caption: tr('Recent movements') }
						),
				]));
			});
		}).catch(function (err) {
			if (ctx.mobileScanActive) return;
			setBusy(mount, false);
			clear(mount);
			mount.appendChild(loadErrorPanel(err.message || tr('Could not load dashboard'), function () {
				renderDashboard(ctx);
			}));
		});
	}

	function indexById(rows) {
		var map = {};
		(rows || []).forEach(function (r) { map[String(r.id)] = r; });
		return map;
	}

	function labelFromMap(map, id, codeKey) {
		var row = map[String(id)];
		if (!row) return '#' + id;
		return row.name + ' (' + row[codeKey] + ')';
	}

	function movementApi(ctx, kind) {
		var map = {
			receive: ctx.urls.api.movementReceive,
			issue: ctx.urls.api.movementIssue,
			transfer: ctx.urls.api.movementTransfer,
			adjust: ctx.urls.api.movementAdjust,
		};
		return map[kind] || ctx.urls.api.movements;
	}

	function workspaceResourceUrl(ctx, url) {
		if (!ctx.workspaceId) return url;
		return url + (url.indexOf('?') === -1 ? '?' : '&') + 'workspaceId=' + encodeURIComponent(ctx.workspaceId);
	}

	function exportCsvHref(ctx, kind) {
		var lang = (document.documentElement.getAttribute('lang') || 'en').toLowerCase().slice(0, 2);
		var href = ctx.urls.api.export + '?kind=' + encodeURIComponent(kind);
		if (lang === 'de') {
			href += '&lang=de';
		}
		return workspaceResourceUrl(ctx, href);
	}

	function byCodeUrl(ctx, code) {
		var tmpl = ctx.urls.api.itemByCode || '';
		return tmpl.replace('__CODE__', encodeURIComponent(code));
	}

	function entityUrl(base, id) {
		var parts = String(base || '').split('?');
		return parts[0].replace(/\/?$/, '/') + id + (parts[1] ? '?' + parts[1] : '');
	}

	function labelPrintUrl(ctx, id) {
		return workspaceResourceUrl(ctx, urlWithId(ctx.urls.api.itemLabelPrint, id));
	}

	function labelSvgUrl(ctx, id) {
		return workspaceResourceUrl(ctx, urlWithId(ctx.urls.api.itemLabel, id));
	}

	function itemQrUrl(ctx, id) {
		return workspaceResourceUrl(ctx, urlWithId(ctx.urls.api.itemQr, id));
	}

	/**
	 * Substitute the `0` placeholder id baked into route templates generated
	 * with `['id' => 0]` (or `lineId`/`locationId` => 0) — same trick as
	 * {@see labelPrintUrl}, generalised for the Wave A–B endpoints.
	 */
	function urlWithId(tmpl, id) {
		return String(tmpl || '').replace(/\/0(\/|\?|$)/, '/' + id + '$1');
	}

	function fillSelect(selectEl, rows, valueKey, labelFn, placeholder) {
		clear(selectEl);
		selectEl.appendChild(el('option', { value: '', text: placeholder }));
		(rows || []).forEach(function (r) {
			selectEl.appendChild(el('option', {
				value: String(r[valueKey]),
				text: labelFn(r),
			}));
		});
	}

	function fillDatalist(listEl, rows, codeKey) {
		clear(listEl);
		(rows || []).forEach(function (r) {
			listEl.appendChild(el('option', {
				value: masterLabel(r, codeKey),
			}));
		});
	}

	/** Fetch missing item/location rows so the table never shows raw #id stubs. */
	function hydrateMaps(ctx, itemMap, locMap, movements) {
		var missingItems = {};
		var missingLocs = {};
		(movements || []).forEach(function (r) {
			if (r.itemId && !itemMap[String(r.itemId)]) missingItems[String(r.itemId)] = true;
			if (r.locationId && !locMap[String(r.locationId)]) missingLocs[String(r.locationId)] = true;
		});
		var jobs = [];
		Object.keys(missingItems).slice(0, 40).forEach(function (id) {
			jobs.push(
				api('GET', entityUrl(ctx.urls.api.items, id)).then(function (row) {
					if (row && row.id != null) itemMap[String(row.id)] = row;
				}).catch(function () { /* keep #id fallback */ })
			);
		});
		Object.keys(missingLocs).slice(0, 40).forEach(function (id) {
			jobs.push(
				api('GET', entityUrl(ctx.urls.api.locations, id)).then(function (row) {
					if (row && row.id != null) locMap[String(row.id)] = row;
				}).catch(function () { /* keep #id fallback */ })
			);
		});
		return jobs.length ? Promise.all(jobs) : Promise.resolve();
	}

	function loadMasters(ctx) {
		return Promise.all([
			api('GET', ctx.urls.api.items + '?limit=200&offset=0&active=1'),
			api('GET', ctx.urls.api.locations + '?limit=200&offset=0&active=1'),
			api('GET', ctx.urls.api.favouriteLocations).catch(function () { return { data: [] }; }),
		]).then(function (res) {
			var favIds = (res[2].data || []).map(function (f) { return Number(f.id); });
			var locations = (res[1].data || []).slice().sort(function (a, b) {
				var af = favIds.indexOf(Number(a.id)) >= 0 ? 0 : 1;
				var bf = favIds.indexOf(Number(b.id)) >= 0 ? 0 : 1;
				if (af !== bf) return af - bf;
				return String(a.name).localeCompare(String(b.name));
			});
			return {
				items: res[0].data || [],
				locations: locations,
				favouriteIds: favIds,
			};
		});
	}

	function announceBalances(balances) {
		if (!balances || !balances.length) return;
		balances.forEach(function (b) {
			var key = b.itemId + ':' + b.locationId;
			var cell = document.querySelector('[data-iv-balance="' + key + '"]');
			if (cell) {
				cell.textContent = formatQty(b.qty);
				cell.classList.add('iv-flash');
				window.setTimeout(function () { cell.classList.remove('iv-flash'); }, 1400);
			}
		});
		var live = $('#iv-live-region');
		if (live) {
			live.textContent = tr('Stock updated.') + ' ' + balances.map(function (b) {
				return formatQty(b.qty);
			}).join(', ');
		}
	}

	function refreshAfterMutation(ctx, result) {
		announceBalances(result && result.balances);
		switch (ctx.page) {
			case 'movements': renderMovements(ctx); break;
			case 'item-detail': renderItemDetail(ctx); break;
			case 'location-detail': renderLocationDetail(ctx); break;
			case 'items': renderItems(ctx); break;
			case 'locations': renderLocations(ctx); break;
			default: renderDashboard(ctx); break;
		}
	}

	function openMovementDialog(ctx, opts) {
		movementDialogOpening = true;
		setChromeInert(true);
		var itemSelect = el('select', { required: '', className: 'iv-input', 'aria-required': 'true' });
		var locSelect = el('select', { required: '', className: 'iv-input', 'aria-required': 'true' });
		var toSelect = opts.transfer
			? el('select', { required: '', className: 'iv-input', 'aria-required': 'true' })
			: null;
		var codePaste = el('input', {
			type: 'text',
			className: 'iv-input',
			placeholder: tr('Paste SKU or scan code'),
			'aria-label': tr('Paste SKU or scan code'),
			autocomplete: 'off',
			spellcheck: 'false',
		});
		var qty = el('input', qtyInputAttrs(ctx, { adjust: !!opts.adjust }));
		var lotCode = el('input', {
			type: 'text',
			className: 'iv-input',
			maxlength: '64',
			placeholder: tr('Lot / serial (when required)'),
			'aria-label': tr('Lot or serial number'),
			autocomplete: 'off',
			spellcheck: 'false',
		});
		var reason = el('input', { type: 'text', maxlength: '512', className: 'iv-input' });
		var reasonCode = el('select', { className: 'iv-input', 'aria-label': tr('Reason code') });
		var locCodeInput = el('input', {
			type: 'text',
			className: 'iv-input',
			'data-iv-location-code': '1',
			placeholder: tr('Scan or type location barcode'),
			'aria-label': tr('Scan or type location barcode'),
			autocomplete: 'off',
			spellcheck: 'false',
			autocapitalize: 'characters',
		});
		var toLocCodeInput = el('input', {
			type: 'text',
			className: 'iv-input',
			'data-iv-to-location-code': '1',
			placeholder: tr('Scan or type destination barcode'),
			'aria-label': tr('Scan or type destination barcode'),
			autocomplete: 'off',
			spellcheck: 'false',
			autocapitalize: 'characters',
		});
		var hint = el('p', {
			className: 'iv-field__hint',
			id: 'iv-bycode-hint',
			text: tr('Tip: wedge scanners type into the paste field — we resolve the item automatically.'),
		});
		codePaste.setAttribute('aria-describedby', 'iv-bycode-hint');

		function resolveCodePaste() {
			var code = (codePaste.value || '').trim();
			if (!code) return;
			api('GET', byCodeUrl(ctx, code)).then(function (item) {
				itemSelect.value = String(item.id);
				itemSelect.dispatchEvent(new Event('change'));
				toast(tr('Item found:') + ' ' + item.name);
			}).catch(function (err) {
				toast(err.message || tr('Code not found.'), true);
			});
		}
		codePaste.addEventListener('change', resolveCodePaste);
		codePaste.addEventListener('keydown', function (ev) {
			if (ev.key === 'Enter') {
				ev.preventDefault();
				resolveCodePaste();
			}
		});

		var moveTrigger = document.activeElement;
		var moveDialogReady = loadMasters(ctx).then(function (masters) {
			fillSelect(itemSelect, masters.items, 'id', function (r) {
				return r.name + ' — ' + r.sku;
			}, tr('Choose an item'));
			fillSelect(locSelect, masters.locations, 'id', function (r) {
				var star = (masters.favouriteIds || []).indexOf(Number(r.id)) >= 0 ? '★ ' : '';
				return star + r.name + ' — ' + r.code;
			}, tr('Choose a location'));
			if (toSelect) {
				fillSelect(toSelect, masters.locations, 'id', function (r) {
					var star = (masters.favouriteIds || []).indexOf(Number(r.id)) >= 0 ? '★ ' : '';
					return star + r.name + ' — ' + r.code;
				}, tr('Choose destination'));
			}

			var isDeltaReverse = !!(opts.adjust && opts.prefill && opts.prefill.mode === 'delta');

			// Apply prefills + smart location defaults before deciding confirm-only.
			if (opts.prefill) {
				if (opts.prefill.itemId) itemSelect.value = String(opts.prefill.itemId);
				if (opts.prefill.locationId) locSelect.value = String(opts.prefill.locationId);
				if (opts.prefill.toLocationId && toSelect) toSelect.value = String(opts.prefill.toLocationId);
				if (opts.prefill.qty != null) qty.value = String(opts.prefill.qty);
				if (opts.prefill.lotCode) lotCode.value = String(opts.prefill.lotCode);
				if (opts.prefill.reason) reason.value = opts.prefill.reason;
			}
			if ((!opts.prefill || !opts.prefill.locationId)) {
				var prefItemEarly = opts.prefill && opts.prefill.itemId
					? (masters.items || []).find(function (it) { return Number(it.id) === Number(opts.prefill.itemId); })
					: null;
				if (prefItemEarly && prefItemEarly.defaultLocationId) {
					locSelect.value = String(prefItemEarly.defaultLocationId);
				} else if (masters.favouriteIds && masters.favouriteIds.length) {
					locSelect.value = String(masters.favouriteIds[0]);
				}
			}
			if (toSelect && (!opts.prefill || !opts.prefill.toLocationId) && masters.favouriteIds && masters.favouriteIds.length > 1) {
				var destEarly = masters.favouriteIds.find(function (id) {
					return String(id) !== String(locSelect.value);
				});
				if (destEarly) toSelect.value = String(destEarly);
			}

			var prefItemRow = (masters.items || []).find(function (row) {
				return String(row.id) === String(itemSelect.value);
			});
			var prefLocRow = (masters.locations || []).find(function (row) {
				return String(row.id) === String(locSelect.value);
			});
			var needsLotPref = !!(prefItemRow && (prefItemRow.trackMode === 'lot' || prefItemRow.trackMode === 'serial'));
			var hasQtyPref = isDeltaReverse
				|| (opts.prefill && opts.prefill.qty != null && String(opts.prefill.qty) !== '');
			var prefToRow = toSelect
				? (masters.locations || []).find(function (row) {
					return String(row.id) === String(toSelect.value);
				})
				: null;
			// Bachus: item + location(s) known (balance-row / reverse) → qty-only strip.
			// Qty may be empty; user types it on the strip. forceFullForm expands via Change.
			var mastersKnown = !!itemSelect.value
				&& !!locSelect.value
				&& (!opts.transfer || !!toSelect.value);
			var confirmOnly = !opts.forceFullForm
				&& !needsLotPref
				&& mastersKnown
				&& !(opts.adjust && ctx.requireAdjustReason)
				// AF-IV12 honesty: never skip the barcode fields when policy requires a real scan.
				&& !(ctx.requireLocationScan && (opts.path === 'issue' || opts.transfer));

			var lotWrap = el('div', {
				className: 'iv-lot-fields',
				hidden: '',
				'data-iv-lot-fields': '1',
			}, [
				field(tr('Lot / serial'), lotCode, { name: 'lotCode' }),
				el('p', {
					className: 'iv-field__hint',
					text: tr('Required for lot- or serial-tracked items. Serial items always use quantity 1.'),
				}),
			]);

			var fields = [];
			var changeBtn = null;
			if (confirmOnly) {
				var summaryText = (prefItemRow ? (prefItemRow.name + ' (' + prefItemRow.sku + ')') : ('#' + itemSelect.value))
					+ ' · '
					+ (prefLocRow ? (prefLocRow.name + ' (' + prefLocRow.code + ')') : ('#' + locSelect.value));
				if (opts.transfer && prefToRow) {
					summaryText += ' → ' + prefToRow.name + ' (' + prefToRow.code + ')';
				}
				fields.push(el('div', {
					className: 'iv-quick-confirm',
					'data-iv-quick-confirm': '1',
					role: 'status',
				}, [
					el('p', {
						className: 'iv-quick-confirm__lead',
						text: hasQtyPref
							? tr('Looking good — confirm this booking.')
							: tr('Enter the quantity, then confirm.'),
					}),
					el('p', {
						className: 'iv-quick-confirm__summary',
						text: summaryText,
					}),
				]));
				if (isDeltaReverse) {
					fields.push(el('p', {
						className: 'iv-field__hint iv-delta-reverse-hint',
						role: 'status',
						'data-iv-delta-reverse': '1',
						text: tr('This reverses the earlier adjustment by {qty}. The quantity field is fixed for safety.', {
							qty: formatQty(opts.prefill.qtyDelta),
						}),
					}));
				} else {
					fields.push(field(opts.adjust ? tr('New quantity') : tr('Quantity'), qty, {
						name: opts.adjust ? 'qty' : 'qty',
					}));
				}
				changeBtn = btn(tr('Change item or location'), {
					className: 'iv-btn--touch',
				});
				changeBtn.setAttribute('data-iv-quick-change', '1');
				fields.push(changeBtn);
			} else {
				// Bachus cold-start: scan + qty + location first; browse items on demand.
				var itemBrowse = el('details', { className: 'iv-item-browse' }, [
					el('summary', {
						className: 'iv-item-browse__summary',
						text: tr('Or browse items'),
					}),
					el('div', { className: 'iv-item-browse__body' }, [
						field(tr('Item'), itemSelect, { name: 'itemId' }),
					]),
				]);
				if (opts.prefill && opts.prefill.itemId) {
					itemBrowse.open = true;
				}
				fields = [
					field(tr('Find by code'), codePaste, { name: 'code' }),
					hint,
					itemBrowse,
					field(opts.transfer ? tr('From location') : tr('Location'), locSelect, { name: 'locationId' }),
				];
				if (toSelect) {
					fields.push(field(tr('To location'), toSelect, { name: 'toLocationId' }));
				}
				if (ctx.requireLocationScan && (opts.path === 'issue' || opts.transfer)) {
					fields.push(field(tr('Scan from-location barcode'), locCodeInput, { name: 'locationCode' }));
					fields.push(el('p', {
						className: 'iv-field__hint',
						'data-iv-location-code-hint': '1',
						text: tr('Type or scan the barcode on the shelf or van — picking a name alone is not enough.'),
					}));
					if (opts.transfer) {
						fields.push(field(tr('Scan to-location barcode'), toLocCodeInput, { name: 'toLocationCode' }));
					}
				}
				if (isDeltaReverse) {
					fields.push(el('p', {
						className: 'iv-field__hint iv-delta-reverse-hint',
						role: 'status',
						'data-iv-delta-reverse': '1',
						text: tr('This reverses the earlier adjustment by {qty}. The quantity field is fixed for safety.', {
							qty: formatQty(opts.prefill.qtyDelta),
						}),
					}));
				} else {
					fields.push(field(opts.adjust ? tr('New quantity') : tr('Quantity'), qty, { name: opts.adjust ? 'qty' : 'qty' }));
				}
				fields.push(lotWrap);
				if (opts.adjust && ctx.requireAdjustReason) {
					fields.push(field(tr('Reason code'), reasonCode, { name: 'reasonCode' }));
					fields.push(el('p', {
						className: 'iv-field__hint',
						text: tr('Required when adjust reason codes are enforced in settings.'),
					}));
				}
				if (opts.adjust) {
					fields.push(field(tr('Reason note (optional)'), reason, { name: 'reason' }));
				}
			}
			var reasonCodesUrl = ctx.urls.api.reasonCodes;
			var reasonCodesReady = opts.adjust && ctx.requireAdjustReason && reasonCodesUrl && !confirmOnly
				? api('GET', reasonCodesUrl).then(function (res) {
					clear(reasonCode);
					reasonCode.appendChild(el('option', { value: '', text: tr('Choose a reason code') }));
					(res.data || []).forEach(function (c) {
						var label = (document.documentElement.getAttribute('lang') || 'en').toLowerCase().slice(0, 2) === 'de'
							? (c.labelDe || c.code)
							: (c.labelEn || c.code);
						reasonCode.appendChild(el('option', { value: c.code, text: label }));
					});
					if (opts.prefill && opts.prefill.reasonCode) reasonCode.value = String(opts.prefill.reasonCode);
				}).catch(function () {
					clear(reasonCode);
					reasonCode.appendChild(el('option', { value: '', text: tr('Could not load reason codes') }));
					reasonCode.dataset.loadFailed = '1';
				})
				: Promise.resolve();

			function syncLotVisibility() {
				var it = (masters.items || []).find(function (row) { return String(row.id) === String(itemSelect.value); });
				var needs = !!(it && (it.trackMode === 'lot' || it.trackMode === 'serial'));
				if (needs) {
					lotWrap.removeAttribute('hidden');
				} else {
					lotWrap.setAttribute('hidden', '');
					lotCode.value = '';
				}
			}
			itemSelect.addEventListener('change', function () {
				var it = (masters.items || []).find(function (row) { return String(row.id) === String(itemSelect.value); });
				if (it && it.defaultLocationId) {
					locSelect.value = String(it.defaultLocationId);
				}
				syncLotVisibility();
			});
			syncLotVisibility();

			function qtyPayload() {
				return qty.value.trim();
			}
			function lotPayload() {
				var v = lotCode.value.trim();
				return v === '' ? null : v;
			}

			function reasonCodePayload() {
				if (!opts.adjust) return null;
				var v = reasonCode.value.trim();
				return v === '' ? null : v;
			}

			/** Kept for display helpers only — never auto-fill booking locationCode (AF-IV12). */
			function codeForLocationId(id) {
				var row = (masters.locations || []).find(function (r) { return Number(r.id) === Number(id); });
				return row && row.code ? String(row.code) : null;
			}

			reasonCodesReady.then(function () {
			var dlg = dialog(opts.title, fields, function () {
				var itemId = Number(itemSelect.value);
				var locationId = Number(locSelect.value);
				if (!itemId || !locationId) {
					var missing = [];
					if (!itemId) missing.push({ field: 'itemId', code: 'required', message: tr('Choose an item') });
					if (!locationId) missing.push({ field: 'locationId', code: 'required', message: tr('Choose a location') });
					return Promise.reject(new ApiError('validation_failed', tr('Choose an item and location.'), missing));
				}
				if (opts.transfer) {
					var toLocationId = Number(toSelect.value);
					if (!toLocationId || toLocationId === locationId) {
						var destDetails = toLocationId === locationId
							? [{ field: 'toLocationId', code: 'same_location', message: tr('Choose two different locations.') }]
							: [{ field: 'toLocationId', code: 'required', message: tr('Choose destination') }];
						return Promise.reject(new ApiError('validation_failed', tr('Choose two different locations.'), destDetails));
					}
					var transferBody = {
						itemId: itemId,
						fromLocationId: locationId,
						toLocationId: toLocationId,
						qty: qtyPayload(),
						lotCode: lotPayload(),
						reason: reason.value || null,
					};
					if (ctx.requireLocationScan) {
						// AF-IV12 honesty: typed/scanned codes only — never invent from the picker.
						transferBody.locationCode = (locCodeInput.value || '').trim();
						transferBody.toLocationCode = (toLocCodeInput.value || '').trim();
						if (!transferBody.locationCode || !transferBody.toLocationCode) {
							var scanMissing = [];
							if (!transferBody.locationCode) {
								scanMissing.push({ field: 'locationCode', code: 'required', message: tr('Confirm the location code.') });
							}
							if (!transferBody.toLocationCode) {
								scanMissing.push({ field: 'toLocationCode', code: 'required', message: tr('Confirm the location code.') });
							}
							return Promise.reject(new ApiError('validation_failed', tr('Confirm both location codes.'), scanMissing));
						}
					}
					return api('POST', movementApi(ctx, 'transfer'), transferBody).then(function (result) {
						toast(tr('Stock transferred.'));
						refreshAfterMutation(ctx, result);
					});
				}
				if (opts.adjust) {
					if (ctx.requireAdjustReason && !reasonCodePayload()) {
						return Promise.reject(new ApiError('validation_failed', tr('Choose a reason code.'), [
							{ field: 'reasonCode', code: 'required', message: tr('Choose a reason code.') },
						]));
					}
					if (reasonCode.dataset.loadFailed === '1' && ctx.requireAdjustReason) {
						return Promise.reject(new ApiError('validation_failed', tr('Could not load reason codes. Try again.'), [
							{ field: 'reasonCode', code: 'load_failed', message: tr('Could not load reason codes. Try again.') },
						]));
					}
					// Reverse of an adjust posts delta mode; stocktake UI posts set mode.
					if (opts.prefill && opts.prefill.mode === 'delta') {
						return api('POST', movementApi(ctx, 'adjust'), {
							itemId: itemId,
							locationId: locationId,
							mode: 'delta',
							qtyDelta: opts.prefill.qtyDelta,
							lotCode: lotPayload(),
							reason: reason.value || null,
							reasonCode: reasonCodePayload(),
						}).then(function (result) {
							toast(tr('Stock adjusted.'));
							refreshAfterMutation(ctx, result);
						});
					}
					return api('POST', movementApi(ctx, 'adjust'), {
						itemId: itemId,
						locationId: locationId,
						mode: 'set',
						qty: qtyPayload(),
						lotCode: lotPayload(),
						reason: reason.value || null,
						reasonCode: reasonCodePayload(),
					}).then(function (result) {
						toast(tr('Stock adjusted.'));
						refreshAfterMutation(ctx, result);
					});
				}
				var body = {
					itemId: itemId,
					locationId: locationId,
					qty: qtyPayload(),
					lotCode: lotPayload(),
					reason: reason.value || null,
				};
				// AF-IV12: issue must send a typed/scanned locationCode (not picker auto-fill).
				if (ctx.requireLocationScan && opts.path === 'issue') {
					body.locationCode = (locCodeInput.value || '').trim();
					if (!body.locationCode) {
						return Promise.reject(new ApiError('validation_failed', tr('Confirm the location code.'), [
							{ field: 'locationCode', code: 'required', message: tr('Confirm the location code.') },
						]));
					}
				}
				return api('POST', movementApi(ctx, opts.path), body).then(function (result) {
					toast(opts.okToast || tr('Stock updated.'));
					refreshAfterMutation(ctx, result);
				});
			}, opts.confirm, { restoreFocusTo: moveTrigger });
			if (confirmOnly && changeBtn) {
				changeBtn.addEventListener('click', function () {
					if (changeBtn.disabled) {
						return;
					}
					changeBtn.disabled = true;
					var nextPrefill = Object.assign({}, opts.prefill || {});
					if (itemSelect.value) nextPrefill.itemId = Number(itemSelect.value);
					if (locSelect.value) nextPrefill.locationId = Number(locSelect.value);
					if (toSelect && toSelect.value) nextPrefill.toLocationId = Number(toSelect.value);
					if (qty.value !== '') nextPrefill.qty = qty.value;
					if (lotCode.value) nextPrefill.lotCode = lotCode.value;
					if (reason.value) nextPrefill.reason = reason.value;
					if (reasonCode.value) nextPrefill.reasonCode = reasonCode.value;
					// Delta reverse must not carry a default qty:'0' from the adjust input.
					if (nextPrefill.mode === 'delta') {
						delete nextPrefill.qty;
					}
					movementDialogOpening = true;
					setChromeInert(true);
					dlg.close();
					openMovementDialog(ctx, Object.assign({}, opts, {
						forceFullForm: true,
						prefill: nextPrefill,
					}));
				});
			}
			});
		}).catch(function (err) {
			movementDialogOpening = false;
			if (!document.querySelector('.iv-dialog-overlay')) {
				setChromeInert(false);
			}
			toast(err.message || tr('Could not load items.'), true);
		});
		busyTriggerUntil(moveTrigger, moveDialogReady);
	}

	function openReceiveDialog(ctx, prefill) {
		if (ctx.workspaceRole === 'viewer') return;
		openMovementDialog(ctx, {
			title: tr('Receive stock'),
			path: 'receive',
			confirm: tr('Receive'),
			okToast: tr('Stock received.'),
			prefill: prefill || null,
		});
	}

	function openIssueDialog(ctx, prefill) {
		if (ctx.workspaceRole === 'viewer') return;
		openMovementDialog(ctx, {
			title: tr('Issue stock'),
			path: 'issue',
			confirm: tr('Issue'),
			okToast: tr('Stock issued.'),
			prefill: prefill || null,
		});
	}

	function openTransferDialog(ctx, prefill) {
		if (ctx.workspaceRole === 'viewer') return;
		openMovementDialog(ctx, {
			title: tr('Transfer stock'),
			transfer: true,
			confirm: tr('Transfer'),
			okToast: tr('Stock transferred.'),
			prefill: prefill || null,
		});
	}

	function openAdjustDialog(ctx, prefill) {
		if (ctx.workspaceRole === 'viewer') return;
		openMovementDialog(ctx, {
			title: tr('Adjust stock'),
			adjust: true,
			confirm: tr('Adjust'),
			okToast: tr('Stock adjusted.'),
			prefill: prefill || null,
		});
	}

	function openReverseFromMovement(ctx, row) {
		var reason = 'reversal of #' + row.id;
		var qty = Math.abs(Number(row.qtyDelta) || 0);
		if (!qty) {
			toast(tr('Nothing to reverse.'), true);
			return;
		}
		if (row.kind === 'receive') {
			openIssueDialog(ctx, {
				itemId: row.itemId,
				locationId: row.locationId,
				qty: qty,
				reason: reason,
			});
			return;
		}
		if (row.kind === 'issue') {
			openReceiveDialog(ctx, {
				itemId: row.itemId,
				locationId: row.locationId,
				qty: qty,
				reason: reason,
			});
			return;
		}
		if (row.kind === 'adjust') {
			// Compensate with delta of −qtyDelta against *current* balance (S3).
			// Never mode=set to the historical pre-adjust qty — later movements
			// would be silently clobbered (UJ-6 / ledger safety).
			openAdjustDialog(ctx, {
				itemId: row.itemId,
				locationId: row.locationId,
				mode: 'delta',
				qtyDelta: -Number(row.qtyDelta),
				reason: reason,
			});
			return;
		}
		if (row.kind === 'transfer_out') {
			openTransferDialog(ctx, {
				itemId: row.itemId,
				locationId: row.counterpartyLocId,
				toLocationId: row.locationId,
				qty: qty,
				reason: reason,
			});
		}
	}

	/** Wave A2: dry-run then commit CSV import, both against the pasted/loaded text. */
	function openImportDialog(ctx) {
		var fileInput = el('input', {
			type: 'file',
			accept: '.csv,text/csv',
			className: 'iv-input',
			'aria-label': tr('CSV file'),
		});
		var csvText = el('textarea', {
			className: 'iv-input',
			rows: '6',
			placeholder: tr('Paste CSV here, or choose a file above.'),
		});
		var skipErrors = el('input', {
			type: 'checkbox',
			className: 'iv-switch__input',
			id: 'iv-import-skip',
			// Bachus: kept for AT/tests but hidden — confirming after dry-run skips bad rows.
			hidden: '',
		});
		var resultBox = el('div', {
			className: 'iv-import-result',
			'aria-live': 'polite',
			'data-iv-import-result': '1',
		});
		var dryRunTimer = null;
		var dryRunSeq = 0;
		var lastDry = { done: false, ok: 0, errors: 0, csv: '' };

		function readSelectedCsv() {
			if (fileInput.files && fileInput.files[0]) {
				return fileInput.files[0].text();
			}
			return Promise.resolve(csvText.value || '');
		}

		function runDryRun() {
			var seq = ++dryRunSeq;
			clear(resultBox);
			resultBox.appendChild(el('p', { className: 'iv-muted', text: tr('Checking…') }));
			return readSelectedCsv().then(function (text) {
				if (seq !== dryRunSeq) {
					return null;
				}
				if (!text.trim()) {
					lastDry = { done: false, ok: 0, errors: 0, csv: '' };
					clear(resultBox);
					resultBox.appendChild(el('p', { className: 'iv-muted', text: tr('Choose a file or paste CSV text first.') }));
					return null;
				}
				return api('POST', ctx.urls.api.importDryRun, { csv: text }).then(function (report) {
					if (seq !== dryRunSeq) {
						return null;
					}
					lastDry = {
						done: true,
						ok: Number(report.ok) || 0,
						errors: (report.errors && report.errors.length) || 0,
						csv: text,
					};
					clear(resultBox);
					resultBox.appendChild(el('p', {
						text: tr('{ok} row(s) look fine.', { ok: String(report.ok) }),
					}));
					if (report.errors && report.errors.length) {
						resultBox.appendChild(el('ul', { className: 'iv-import-errors' }, report.errors.slice(0, 20).map(function (e) {
							return el('li', { text: tr('Line {line}: {message}', { line: String(e.line), message: e.message || e.code }) });
						})));
						resultBox.appendChild(el('p', {
							className: 'iv-field__hint',
							role: 'status',
							text: tr('Import will skip rows with errors and keep the good ones.'),
						}));
						skipErrors.checked = true;
					} else {
						skipErrors.checked = false;
					}
					return report;
				});
			}).catch(function (err) {
				if (seq !== dryRunSeq) {
					return null;
				}
				lastDry = { done: false, ok: 0, errors: 0, csv: '' };
				clear(resultBox);
				resultBox.appendChild(el('p', { className: 'iv-muted', text: err.message || tr('Could not check the file.') }));
				return null;
			});
		}

		function scheduleDryRun() {
			if (dryRunTimer) window.clearTimeout(dryRunTimer);
			dryRunTimer = window.setTimeout(function () {
				dryRunTimer = null;
				runDryRun();
			}, 350);
		}

		fileInput.addEventListener('change', function () { runDryRun(); });
		csvText.addEventListener('input', scheduleDryRun);

		dialog(tr('Import items from CSV'), [
			disclosureSection(tr('CSV columns (optional help)'), [
				el('p', {
					className: 'iv-field__hint',
					text: tr('Columns: sku, scan_code, name, description, uom, reorder_level, active, supplier_note, last_price_minor, opening_location_code, opening_qty. German headers also work.'),
				}),
			], { className: 'iv-import-columns', open: false }),
			field(tr('CSV file'), fileInput, { name: 'file' }),
			field(tr('Or paste CSV text'), csvText, { name: 'csv' }),
			el('p', {
				className: 'iv-field__hint',
				text: tr('We check your file automatically as you paste or choose it.'),
			}),
			resultBox,
			skipErrors,
		], function () {
			return readSelectedCsv().then(function (text) {
				if (!text.trim()) {
					return Promise.reject(new ApiError('validation_failed', tr('Choose a file or paste CSV text.'), [
						{ field: 'csv', code: 'required', message: tr('Choose a file or paste CSV text.') },
					]));
				}
				// Re-check when the pasted/file text no longer matches the last dry-run.
				var ensure = (lastDry.done && lastDry.csv === text) ? Promise.resolve(null) : runDryRun();
				return ensure.then(function () {
					if (!(lastDry.done && lastDry.csv === text && lastDry.ok > 0)) {
						if (lastDry.done && lastDry.csv === text && lastDry.ok === 0) {
							return Promise.reject(new ApiError(
								'validation_failed',
								tr('Nothing to import — fix the errors first.'),
							));
						}
						return Promise.reject(new ApiError(
							'validation_failed',
							tr('Still checking the file… Try Import again in a moment.'),
						));
					}
					// Bachus: confirming after a dry-run that listed errors = consent to skip them.
					var willSkip = lastDry.errors > 0 || !!skipErrors.checked;
					return api('POST', ctx.urls.api.importCommit, { csv: text, skipErrors: willSkip }).then(function (report) {
						toast(tr('Imported: {created} new, {received} received, {skipped} skipped.', {
							created: String(report.created),
							received: String(report.received),
							skipped: String(report.skipped),
						}));
						renderItems(ctx);
					});
				});
			});
		}, tr('Import'));
	}

	function renderItems(ctx) {
		var mount = ctx.mount;
		var selected = {};
		var bulkBtn = null;
		function syncBulkBtn() {
			var count = Object.keys(selected).length;
			if (count === 0) {
				if (bulkBtn && bulkBtn.parentNode) {
					bulkBtn.parentNode.removeChild(bulkBtn);
				}
				bulkBtn = null;
				return;
			}
			if (!bulkBtn) {
				bulkBtn = btn(tr('Print labels ({count})', { count: String(count) }), {
					onclick: function () {
						var ids = Object.keys(selected);
						if (!ids.length) return;
						window.open(workspaceResourceUrl(ctx, ctx.urls.api.itemBulkLabels + '?ids=' + ids.join(',')), '_blank', 'noopener');
					},
				});
				// Prefer omit over disable: insert after New item when present.
				if (ctx.actions.firstChild && ctx.actions.firstChild.nextSibling) {
					ctx.actions.insertBefore(bulkBtn, ctx.actions.firstChild.nextSibling);
				} else {
					ctx.actions.appendChild(bulkBtn);
				}
			} else {
				bulkBtn.textContent = tr('Print labels ({count})', { count: String(count) });
			}
		}
		setBusy(mount, true);
		clear(mount);
		if (ctx.canWrite) {
			clear(ctx.actions);
			ctx.actions.appendChild(btn(tr('New item'), {
				'data-iv-action': 'item-new',
				primary: true,
				onclick: function () { openItemDialog(ctx, null); },
			}));
			ctx.actions.appendChild(actionsMoreMenu(tr('More'), [
				el('a', {
					href: exportCsvHref(ctx, 'items'),
					className: 'button',
					text: tr('Export CSV'),
				}),
				btn(tr('Import CSV'), {
					onclick: function () { openImportDialog(ctx); },
				}),
			]));
		}
		var itemsHowTo = pageHowToCard(ctx, 'items');
		if (itemsHowTo) {
			mount.appendChild(itemsHowTo);
		}
		var q = el('input', {
			id: 'iv-items-q',
			type: 'search',
			className: 'iv-input form-input',
			placeholder: tr('Search name or SKU'),
			autocomplete: 'off',
		});
		var listHost = el('div', { id: 'iv-items-list', className: 'iv-card' });
		var loadSeq = 0;

		function appliedQuery() {
			return (q.value || '').trim();
		}

		mount.appendChild(el('section', {
			id: 'iv-items-filter-panel',
			className: 'iv-card iv-filter-panel',
			'aria-labelledby': 'iv-items-filter-title',
		}, [
			el('header', { className: 'iv-filter-panel__head' }, [
				el('div', { className: 'iv-filter-panel__head-text' }, [
					el('h2', { id: 'iv-items-filter-title', className: 'iv-sr-only', text: tr('Search items') }),
					el('p', {
						className: 'iv-filter-panel__intro iv-sr-only',
						text: tr('Results update as you type.'),
					}),
				]),
			]),
			el('div', { className: 'iv-filter-panel__body' }, [
				el('form', {
					className: 'iv-filter-panel__form iv-filterbar',
					role: 'search',
					'aria-label': tr('Search items'),
					onsubmit: function (ev) {
						ev.preventDefault();
						load();
					},
				}, [
					el('div', {
						className: 'iv-filter-grid iv-filter-grid--simple',
						role: 'group',
						'aria-label': tr('Filter options'),
					}, [
						el('div', { className: 'iv-filter-field iv-filter-field--search' }, [
							el('label', { className: 'iv-filter-field__label', for: 'iv-items-q', text: tr('Search') }),
							el('div', { className: 'iv-filter-field__control' }, [q]),
						]),
						el('div', { className: 'iv-filter-field iv-filter-field--actions' }, [
							el('span', { className: 'iv-filter-field__label iv-sr-only', text: tr('Actions') }),
							el('div', { className: 'iv-filter-field__control iv-filter-field__control--actions' }, [
								btn(tr('Search'), {
							'data-iv-action': 'filter-search',
									type: 'submit',
								}),
								(function () {
									var clearBtn = btn(tr('Clear'), {
							'data-iv-action': 'filter-clear',
										type: 'button',
										onclick: function () {
											q.value = '';
											syncItemsClear();
											load();
										},
									});
									function syncItemsClear() {
										clearBtn.hidden = !(q.value || '').trim();
									}
									q.addEventListener('input', syncItemsClear);
									syncItemsClear();
									return clearBtn;
								})(),
							]),
						]),
					]),
				]),
			]),
		]));
		mount.appendChild(listHost);
		// Bachus: searching while typing — Search stays as Enter / AT fallback (not primary).
		bindLiveSearch(q, function () { load(); });

		function load() {
			var seq = ++loadSeq;
			var term = appliedQuery();
			setBusy(listHost, true);
			api('GET', ctx.urls.api.items + '?limit=50&offset=0&q=' + encodeURIComponent(term)).then(function (res) {
				if (seq !== loadSeq) return;
				clear(listHost);
				setBusy(listHost, false);
				listHost.appendChild(el('header', { className: 'iv-card__header' }, [
					el('h2', { className: 'iv-card__title', text: tr('Items') }),
				]));
				var body = el('div', { className: 'iv-card__body' });
				listHost.appendChild(body);
				if (!res.data.length) {
					body.appendChild(emptyState(
						term ? tr('No items match these filters') : tr('No items yet'),
						term
							? tr('Clear the search or try another name or SKU.')
							: tr('Create an item with a SKU and reorder level.'),
						term
							? btn(tr('Clear'), {
							'data-iv-action': 'filter-clear',
								primary: true,
								onclick: function () {
									q.value = '';
									load();
								},
							})
							: ((ctx.workspaceRole !== 'viewer' && ctx.canWrite)
								? btn(tr('New item'), { 'data-iv-action': 'item-new', primary: true, onclick: function () { openItemDialog(ctx, null); } })
								: null)
					));
					return;
				}
				var columns = [];
				if (ctx.canWrite) {
					columns.push({ label: tr('Select'), render: function (r) {
						return el('label', { className: 'iv-check-hit' }, [el('input', {
							type: 'checkbox',
							'aria-label': tr('Select {name} for bulk labels', { name: r.name }),
							checked: selected[r.id] ? '' : null,
							onchange: function (ev) {
								if (ev.target.checked) {
									selected[r.id] = true;
								} else {
									delete selected[r.id];
								}
								syncBulkBtn();
							},
						})]);
					} });
				}
				columns.push(
					{ label: tr('Name'), render: function (r) {
						var kids = [el('a', { href: entityUrl(ctx.urls.pages.items, r.id), text: r.name })];
						if (!r.active) {
							kids.push(el('span', {
								className: 'iv-badge iv-badge--neutral',
								text: tr('Inactive'),
							}));
						}
						return el('span', { className: 'iv-name-with-status' }, kids);
					} },
					{ label: tr('SKU'), render: function (r) {
						var qrUrl = itemQrUrl(ctx, r.id);
						var preview = el('img', {className: 'iv-sku-qr', src: qrUrl + (qrUrl.indexOf('?') === -1 ? '?' : '&') + 'v=' + encodeURIComponent(String(r.updatedAt || r.scanCode || r.id)), alt: tr('QR code for {code}', {code: r.sku}), loading: 'lazy'});
						var holder = el('button', {type: 'button', className: 'iv-sku-qr-button', title: tr('Click to enlarge QR code'), 'aria-label': tr('Show QR code for {code}', {code: r.sku}), onclick: function () { holder.classList.toggle('is-zoomed'); }});
						holder.appendChild(preview);
						holder.appendChild(el('span', {className: 'iv-sku-qr-code', text: r.sku}));
						return holder;
					} },
					{ label: tr('Scan code'), key: 'scanCode' },
					{ label: tr('UoM'), key: 'uom' },
					{ label: tr('Reorder'), key: 'reorderLevel' },
					{ label: tr('Actions'), render: function (r) {
						if (ctx.workspaceRole === 'viewer' || !ctx.canWrite) {
							return el('span', { className: 'iv-muted', text: '—' });
						}
						// Bachus: Edit only on the list — Deactivate lives on detail under More.
						var kids = [
							btn(tr('Edit'), {
								onclick: function () { openItemDialog(ctx, r); },
							}),
						];
						if (!r.active) {
							kids.push(btn(tr('Reactivate'), {
								primary: true,
								onclick: function () {
									api('PUT', entityUrl(ctx.urls.api.items, r.id), { active: true }).then(function () {
										toast(tr('Item reactivated.'));
										renderItems(ctx);
									}).catch(function (err) { toast(err.message, true); });
								},
							}));
						}
						return el('div', { className: 'iv-row__actions' }, kids);
					} },
				);
				setBusy(mount, false);
				body.appendChild(tableOrCards(columns, res.data));
			}).catch(function (err) {
				if (seq !== loadSeq) return;
				setBusy(mount, false);
				setBusy(listHost, false);
				clear(listHost);
				listHost.appendChild(loadErrorPanel(err.message, function () { load(); }));
				toast(err.message, true);
			});
		}
		load();
	}

	/**
	 * Online product lookup (admin opt-in). Logic lives in
	 * js/common/product-lookup.js — upstream text is untrusted and is only
	 * ever assigned via .value / textContent, never innerHTML.
	 */
	function productLookupPanel(ctx, f) {
		var mod = typeof window !== 'undefined' ? window.InventoryCheckProductLookup : null;
		if (!mod || typeof mod.createPanel !== 'function') {
			return null;
		}
		return mod.createPanel({
			tr: tr,
			el: el,
			btn: btn,
			clear: clear,
			api: api,
			lookupUrl: ctx.urls.api.productLookup,
			autofire: ctx.productLookupAutofire === true,
			fields: f,
		});
	}

	function openItemDialog(ctx, existing) {
		var sku = el('input', { type: 'text', className: 'iv-input', maxlength: '64', value: existing ? existing.sku : '', required: '' });
		var scan = el('input', { type: 'text', className: 'iv-input', maxlength: '128', value: existing ? existing.scanCode : '' });
		var name = el('input', { type: 'text', className: 'iv-input', maxlength: '255', value: existing ? existing.name : '', required: '' });
		var description = el('textarea', {
			className: 'iv-input', rows: '3', maxlength: '10000',
		});
		description.value = existing && existing.description ? existing.description : '';
		var uom = el('input', { type: 'text', className: 'iv-input', maxlength: '32', value: existing ? existing.uom : 'pcs' });
		var reorder = el('input', qtyInputAttrs(ctx, {
			adjust: true,
			value: existing ? existing.reorderLevel : '0',
			required: false,
		}));
		reorder.removeAttribute('required');
		var targetStock = el('input', qtyInputAttrs(ctx, {
			adjust: true,
			value: existing && existing.targetStock != null ? existing.targetStock : '',
			required: false,
		}));
		targetStock.removeAttribute('required');
		targetStock.placeholder = tr('Optional target / order-up-to');
		var defaultLocation = el('select', { className: 'iv-input', 'aria-label': tr('Default location') });
		var trackMode = el('select', { className: 'iv-input', 'aria-label': tr('Tracking mode') }, [
			el('option', { value: 'none', text: tr('No lot / serial tracking') }),
			el('option', { value: 'lot', text: tr('Lot / batch tracking') }),
			el('option', { value: 'serial', text: tr('Serial number tracking') }),
		]);
		trackMode.value = existing && existing.trackMode ? existing.trackMode : 'none';
		var supplierNote = el('input', {
			type: 'text', className: 'iv-input', maxlength: '255',
			value: existing && existing.supplierNote ? existing.supplierNote : '',
		});
		var lastPrice = el('input', {
			type: 'number', className: 'iv-input', min: '0', step: '1',
			value: existing && existing.lastPriceMinor != null ? String(existing.lastPriceMinor) : '',
		});
		var itemTrigger = document.activeElement;
		var itemDialogReady = loadMasters(ctx).then(function (masters) {
		clear(defaultLocation);
		defaultLocation.appendChild(el('option', { value: '', text: tr('No default location') }));
		(masters.locations || []).forEach(function (r) {
			defaultLocation.appendChild(el('option', { value: String(r.id), text: r.name + ' — ' + r.code }));
		});
		if (existing && existing.defaultLocationId) defaultLocation.value = String(existing.defaultLocationId);
		var essentials = [
			field(tr('SKU'), sku, { name: 'sku' }),
			field(tr('Name'), name, { name: 'name' }),
		];
		var moreKids = [
			field(tr('Scan code (optional)'), scan, { name: 'scanCode' }),
			// Mirror the API's requireOffice gate: field users must never see
			// a lookup affordance that can only 403 on them.
			ctx.productLookupEnabled && ctx.canWrite
				? productLookupPanel(ctx, { scan: scan, sku: sku, name: name, description: description })
				: null,
			field(tr('Description (optional)'), description, { name: 'description' }),
			field(tr('Unit'), uom, { name: 'uom' }),
			field(tr('Reorder level'), reorder, { name: 'reorderLevel' }),
			field(tr('Target stock (optional)'), targetStock, { name: 'targetStock' }),
			field(tr('Default location (optional)'), defaultLocation, { name: 'defaultLocationId' }),
			field(tr('Tracking mode'), trackMode, { name: 'trackMode' }),
			el('p', {
				className: 'iv-field__hint',
				text: tr('Lot and serial tracking require a code on every stock movement. Serial items always move one unit at a time. Clear stock to zero before switching an existing item up to lot or serial tracking.'),
			}),
			field(tr('Supplier note (optional)'), supplierNote, { name: 'supplierNote' }),
			field(tr('Last price in cents (optional)'), lastPrice, { name: 'lastPriceMinor' }),
		];
		var fields = essentials.concat([
			disclosureSection(tr('More item options'), moreKids, {
				className: 'iv-item-more',
				open: !!existing,
			}),
			existing ? el('p', {
				className: 'iv-field__hint',
				text: tr('Changing SKU or scan code does not update already printed labels.'),
			}) : null,
		].filter(Boolean));
		dialog(existing ? tr('Edit item') : tr('New item'), fields, function () {
			var body = {
				sku: sku.value.trim(),
				scanCode: scan.value.trim(),
				name: name.value.trim(),
				uom: uom.value.trim() || 'pcs',
				reorderLevel: reorder.value.trim(),
				targetStock: targetStock.value.trim() === '' ? null : targetStock.value.trim(),
				defaultLocationId: defaultLocation.value === '' ? null : Number(defaultLocation.value),
				trackMode: trackMode.value,
				supplierNote: supplierNote.value.trim() || null,
				lastPriceMinor: lastPrice.value.trim() === '' ? null : Number(lastPrice.value),
				description: description.value.trim() || null,
			};
			if (existing) {
				return api('PUT', entityUrl(ctx.urls.api.items, existing.id), body).then(function () {
					toast(tr('Item saved.'));
					if (ctx.page === 'item-detail') renderItemDetail(ctx);
					else renderItems(ctx);
				});
			}
			return api('POST', ctx.urls.api.items, body).then(function () {
				toast(tr('Item created.'));
				renderItems(ctx);
			});
		}, undefined, { restoreFocusTo: itemTrigger });
		}).catch(function (err) { toast(err.message || tr('Could not load items.'), true); });
		busyTriggerUntil(itemTrigger, itemDialogReady);
	}

	/** Wave A4: one primary item photo. Office may upload/replace/remove; everyone may view. */
	function renderItemPhoto(ctx, host, item, id) {
		clear(host);
		if (item.hasPhoto) {
			host.appendChild(el('img', {
				className: 'iv-item-photo__img',
				src: workspaceResourceUrl(ctx, urlWithId(ctx.urls.api.itemPhoto, id) + '?v=' + String(item.updatedAt || Date.now())),
				alt: item.name,
				loading: 'lazy',
			}));
		} else {
			host.appendChild(el('p', { className: 'iv-muted', text: tr('No photo yet.') }));
		}
		if (ctx.workspaceRole === 'viewer' || !ctx.canWrite) {
			return;
		}
		var fileInput = el('input', {
			type: 'file',
			accept: 'image/jpeg,image/png,image/webp',
			'aria-label': tr('Upload photo'),
			onchange: function () {
				if (!fileInput.files || !fileInput.files[0]) return;
				var fd = new FormData();
				fd.append('file', fileInput.files[0]);
				fetch(workspaceResourceUrl(ctx, urlWithId(ctx.urls.api.itemPhotoUpload, id)), {
					method: 'POST',
					credentials: 'same-origin',
					headers: { requesttoken: requestToken() },
					body: fd,
				}).then(function (res) {
					return res.json().catch(function () { return {}; }).then(function (data) {
						if (!res.ok) {
							var err = (data && data.error) || {};
							throw new ApiError(err.code, err.message, err.details, res.status);
						}
						return data;
					});
				}).then(function (updated) {
					toast(tr('Photo uploaded.'));
					renderItemPhoto(ctx, host, updated, id);
				}).catch(function (err) {
					toast(err.message || tr('Could not upload the photo.'), true);
				});
			},
		});
		var actions = [fileInput];
		if (item.hasPhoto) {
			actions.push(btn(tr('Remove photo'), {
				onclick: function () {
					api('DELETE', urlWithId(ctx.urls.api.itemPhotoDelete, id)).then(function (updated) {
						toast(tr('Photo removed.'));
						renderItemPhoto(ctx, host, updated, id);
					}).catch(function (err) { toast(err.message, true); });
				},
			}));
		}
		host.appendChild(el('div', { className: 'iv-item-photo__actions' }, actions));
	}

	function renderItemDetail(ctx) {
		var mount = ctx.mount;
		var id = Number(ctx.entityId);
		setBusy(mount, true);
		Promise.all([
			api('GET', entityUrl(ctx.urls.api.items, id)),
			api('GET', ctx.urls.api.balances + '?itemId=' + id + '&limit=50&offset=0'),
			api('GET', ctx.urls.api.locations + '?limit=200&offset=0&active=1'),
		]).then(function (results) {
			var item = results[0];
			var bals = results[1];
			var locMap = indexById(results[2].data);
			clear(mount);
			setBusy(mount, false);
			clear(ctx.actions);
			if (ctx.workspaceRole !== 'viewer' && ctx.canWrite) {
				ctx.actions.appendChild(btn(tr('Edit'), {
					onclick: function () { openItemDialog(ctx, item); },
				}));
			}
			ctx.actions.appendChild(el('a', {
				href: labelPrintUrl(ctx, id),
				className: 'button primary',
				target: '_blank',
				rel: 'noopener noreferrer',
				text: tr('Print label'),
			}));
			if (ctx.workspaceRole !== 'viewer' && ctx.canWrite) {
				ctx.actions.appendChild(actionsMoreMenu(tr('More'), [
					btn(item.active ? tr('Archive') : tr('Restore from archive'), {
						onclick: function () {
							api('PUT', entityUrl(ctx.urls.api.items, id), { active: !item.active }).then(function () {
								toast(item.active ? tr('Item archived.') : tr('Item restored.'));
								renderItemDetail(ctx);
							}).catch(function (err) { toast(err.message, true); });
						},
					}),
					el('a', {
						href: labelSvgUrl(ctx, id),
						className: 'button',
						download: 'label-' + id + '.svg',
						text: tr('Download SVG'),
					}),
				]));
			}
			var infoKids = [
				el('h2', { className: 'iv-section__title', text: item.name }),
				el('p', { className: 'iv-muted', text: tr('SKU') + ': ' + item.sku + ' · ' + tr('Scan code') + ': ' + item.scanCode }),
			];
			if (item.supplierNote) {
				infoKids.push(el('p', { className: 'iv-muted', text: tr('Supplier note') + ': ' + item.supplierNote }));
			}
			if (item.trackMode && item.trackMode !== 'none') {
				infoKids.push(el('p', {
					className: 'iv-muted',
					text: tr('Tracking mode') + ': ' + (item.trackMode === 'serial'
						? tr('Serial number tracking')
						: tr('Lot / batch tracking')),
				}));
			}
			if (item.lastPriceMinor != null) {
				infoKids.push(el('p', { className: 'iv-muted', text: tr('Last price') + ': ' + (Number(item.lastPriceMinor) / 100).toFixed(2) }));
			}
			var photoHost = el('div', { className: 'iv-item-photo' });
			infoKids.push(photoHost);
			var itemHowTo = pageHowToCard(ctx, 'item-detail');
			if (itemHowTo) {
				mount.appendChild(itemHowTo);
			}
			mount.appendChild(el('section', { className: 'iv-section' }, infoKids));
			renderItemPhoto(ctx, photoHost, item, id);
			mount.appendChild(disclosureSection(tr('Label preview'), [
				el('figure', { className: 'iv-label-preview' }, [
					el('img', {
						className: 'iv-label-preview__img',
						src: labelSvgUrl(ctx, id) + '?v=' + String(item.updatedAt || item.scanCode || id),
						alt: tr('QR and barcode for {code}', { code: item.scanCode }),
						width: '320',
						height: '400',
					}),
					el('figcaption', {
						className: 'iv-label-code',
						id: 'iv-label-code',
						text: item.scanCode,
					}),
				]),
			], { open: false, className: 'iv-label-preview-disclosure' }));
			mount.appendChild(el('section', { className: 'iv-section', 'aria-labelledby': 'iv-bal-title' }, [
				el('h2', { id: 'iv-bal-title', className: 'iv-section__title', text: tr('Balances by location') }),
				bals.data.length === 0
					? emptyState(
						tr('No stock yet.'),
						tr('Receive stock at a location to start tracking this item.'),
						(ctx.workspaceRole !== 'viewer' && ctx.canWrite)
							? btn(tr('Receive stock'), {
							'data-iv-action': 'receive',
								primary: true,
								className: 'iv-btn--touch',
								onclick: function () { openReceiveDialog(ctx, { itemId: id }); },
							})
							: null
					)
					: tableOrCards([
						{ label: tr('Location'), render: function (r) {
							return labelFromMap(locMap, r.locationId, 'code');
						} },
						{ label: tr('Qty'), render: function (r) {
							var n = Number(r.qty);
							var attrs = { 'data-iv-balance': id + ':' + r.locationId };
							if (n < 0) {
								return el('span', Object.assign({
									className: 'iv-badge iv-badge--overdue',
									text: formatQty(n) + ' — ' + tr('negative stock'),
								}, attrs));
							}
							return el('span', Object.assign({ text: formatQty(n) }, attrs));
						} },
						{ label: tr('Actions'), render: function (r) {
							var kids = [];
							if (ctx.workspaceRole !== 'viewer' && ctx.canWrite) {
								kids.push(btn(tr('Receive'), {
									className: 'iv-btn--touch',
									onclick: function () {
										openReceiveDialog(ctx, { itemId: id, locationId: r.locationId });
									},
								}));
							}
							kids.push(btn(tr('Issue'), {
								primary: !ctx.canWrite,
								className: 'iv-btn--touch',
								onclick: function () {
									openIssueDialog(ctx, { itemId: id, locationId: r.locationId });
								},
							}));
							return el('div', { className: 'iv-row__actions', 'data-iv-balance-actions': '1' }, kids);
						} },
					], bals.data, { caption: tr('Balances by location') }),
			]));
		}).catch(function (err) {
			setBusy(mount, false);
			clear(mount);
			mount.appendChild(loadErrorPanel(err.message, function () { renderItemDetail(ctx); }));
			toast(err.message, true);
		});
	}

	function renderLocations(ctx) {
		var mount = ctx.mount;
		clear(mount);
		if (ctx.canWrite) {
			clear(ctx.actions);
			ctx.actions.appendChild(btn(tr('New location'), {
				primary: true,
				onclick: function () { openLocationDialog(ctx); },
			}));
		}

		var locsHowTo = pageHowToCard(ctx, 'locations');
		if (locsHowTo) {
			mount.appendChild(locsHowTo);
		}

		var q = el('input', {
			id: 'iv-loc-q',
			type: 'search',
			className: 'iv-input form-input',
			placeholder: tr('Code or name'),
			autocomplete: 'off',
		});
		var listHost = el('div', { id: 'iv-locations-list', className: 'iv-card' });
		var loadSeq = 0;

		function appliedQuery() {
			return (q.value || '').trim();
		}

		mount.appendChild(el('section', {
			id: 'iv-loc-filter-panel',
			className: 'iv-card iv-filter-panel',
			'aria-labelledby': 'iv-loc-filter-title',
		}, [
			el('header', { className: 'iv-filter-panel__head' }, [
				el('div', { className: 'iv-filter-panel__head-text' }, [
					el('h2', { id: 'iv-loc-filter-title', className: 'iv-sr-only', text: tr('Search locations') }),
					el('p', {
						className: 'iv-filter-panel__intro iv-sr-only',
						text: tr('Results update as you type.'),
					}),
				]),
			]),
			el('div', { className: 'iv-filter-panel__body' }, [
				el('form', {
					className: 'iv-filter-panel__form iv-filterbar',
					role: 'search',
					'aria-label': tr('Search locations'),
					onsubmit: function (ev) {
						ev.preventDefault();
						load();
					},
				}, [
					el('div', {
						className: 'iv-filter-grid iv-filter-grid--simple',
						role: 'group',
						'aria-label': tr('Filter options'),
					}, [
						el('div', { className: 'iv-filter-field iv-filter-field--search' }, [
							el('label', { className: 'iv-filter-field__label', for: 'iv-loc-q', text: tr('Search') }),
							el('div', { className: 'iv-filter-field__control' }, [q]),
						]),
						el('div', { className: 'iv-filter-field iv-filter-field--actions' }, [
							el('span', { className: 'iv-filter-field__label iv-sr-only', text: tr('Actions') }),
							el('div', { className: 'iv-filter-field__control iv-filter-field__control--actions' }, [
								btn(tr('Search'), {
							'data-iv-action': 'filter-search',
									type: 'submit',
								}),
								(function () {
									var clearBtn = btn(tr('Clear'), {
							'data-iv-action': 'filter-clear',
										type: 'button',
										onclick: function () {
											q.value = '';
											syncLocClear();
											load();
										},
									});
									function syncLocClear() {
										clearBtn.hidden = !(q.value || '').trim();
									}
									q.addEventListener('input', syncLocClear);
									syncLocClear();
									return clearBtn;
								})(),
							]),
						]),
					]),
				]),
			]),
		]));
		mount.appendChild(listHost);
		bindLiveSearch(q, function () { load(); });

		function load() {
			var seq = ++loadSeq;
			var term = appliedQuery();
			setBusy(listHost, true);
			api('GET', ctx.urls.api.locations + '?limit=50&offset=0&q=' + encodeURIComponent(term)).then(function (res) {
				if (seq !== loadSeq) return;
				clear(listHost);
				setBusy(listHost, false);
				if (!res.data.length) {
					listHost.appendChild(el('header', { className: 'iv-card__header' }, [
						el('h2', { id: 'iv-locations-title', className: 'iv-card__title', text: tr('Locations') }),
					]));
					var emptyBody = el('div', { className: 'iv-card__body' });
					listHost.appendChild(emptyBody);
					emptyBody.appendChild(emptyState(
						term ? tr('No locations match these filters') : tr('No locations yet'),
						term
							? tr('Clear the search or try another code or name.')
							: tr('Add a warehouse or van to hold stock.'),
						term
							? btn(tr('Clear'), {
							'data-iv-action': 'filter-clear',
								primary: true,
								onclick: function () {
									q.value = '';
									load();
								},
							})
							: ((ctx.workspaceRole !== 'viewer' && ctx.canWrite)
								? btn(tr('New location'), { primary: true, onclick: function () { openLocationDialog(ctx); } })
								: null)
					));
					return;
				}
				listHost.appendChild(el('header', { className: 'iv-card__header' }, [
					el('h2', { id: 'iv-locations-title', className: 'iv-card__title', text: tr('Locations') }),
				]));
				listHost.appendChild(el('div', { className: 'iv-card__body' }, [
					tableOrCards([
						{ label: tr('Name'), render: function (r) {
							var kids = [el('a', { href: entityUrl(ctx.urls.pages.locations, r.id), text: r.name })];
							if (!r.active) {
								kids.push(el('span', {
									className: 'iv-badge iv-badge--neutral',
									text: tr('Inactive'),
								}));
							}
							return el('span', { className: 'iv-name-with-status' }, kids);
						} },
						{ label: tr('Code'), key: 'code' },
						{ label: tr('Kind'), render: function (r) { return locationKindLabel(r.kind); } },
						{ label: tr('Actions'), render: function (r) {
							if (ctx.workspaceRole === 'viewer' || !ctx.canWrite) {
								return el('span', { className: 'iv-muted', text: '—' });
							}
							var kids = [
								btn(tr('Edit'), {
									onclick: function () { openLocationDialog(ctx, r); },
								}),
							];
							if (!r.active) {
								kids.push(btn(tr('Reactivate'), {
									primary: true,
									onclick: function () {
										api('PUT', entityUrl(ctx.urls.api.locations, r.id), { active: true }).then(function () {
											toast(tr('Location reactivated.'));
											renderLocations(ctx);
										}).catch(function (err) { toast(err.message, true); });
									},
								}));
							}
							return el('div', { className: 'iv-row__actions' }, kids);
						} },
					], res.data),
				]));
			}).catch(function (err) {
				if (seq !== loadSeq) return;
				setBusy(mount, false);
				setBusy(listHost, false);
				clear(listHost);
				listHost.appendChild(loadErrorPanel(err.message, function () { load(); }));
				toast(err.message, true);
			});
		}
		load();
	}

	function openLocationDialog(ctx, existing) {
		var code = el('input', {
			type: 'text', className: 'iv-input', maxlength: '64', required: '',
			value: existing ? existing.code : '',
		});
		var name = el('input', {
			type: 'text', className: 'iv-input', maxlength: '255', required: '',
			value: existing ? existing.name : '',
		});
		var kind = el('select', { className: 'iv-input' }, [
			el('option', { value: 'warehouse', text: tr('Warehouse') }),
			el('option', { value: 'shelf', text: tr('Shelf') }),
			el('option', { value: 'van', text: tr('Van') }),
			el('option', { value: 'site', text: tr('Site') }),
			el('option', { value: 'other', text: tr('Other') }),
		]);
		kind.value = existing ? existing.kind : 'warehouse';
		var fields = [
			field(tr('Code'), code, { name: 'code' }),
			field(tr('Name'), name, { name: 'name' }),
		];
		if (existing) {
			fields.push(field(tr('Kind'), kind, { name: 'kind' }));
		} else {
			fields.push(disclosureSection(tr('More location options'), [
				field(tr('Kind'), kind, { name: 'kind' }),
			], { className: 'iv-location-more', open: false }));
		}
		dialog(existing ? tr('Edit location') : tr('New location'), fields, function () {
			var body = {
				code: code.value.trim(),
				name: name.value.trim(),
				kind: kind.value,
			};
			if (existing) {
				return api('PUT', entityUrl(ctx.urls.api.locations, existing.id), body).then(function () {
					toast(tr('Location saved.'));
					if (ctx.page === 'location-detail') renderLocationDetail(ctx);
					else renderLocations(ctx);
				});
			}
			return api('POST', ctx.urls.api.locations, body).then(function () {
				toast(tr('Location created.'));
				renderLocations(ctx);
			});
		});
	}

	function renderLocationDetail(ctx) {
		var mount = ctx.mount;
		var id = Number(ctx.entityId);
		Promise.all([
			api('GET', entityUrl(ctx.urls.api.locations, id)),
			api('GET', ctx.urls.api.balances + '?locationId=' + id + '&nonZero=1&limit=50&offset=0'),
			api('GET', ctx.urls.api.items + '?limit=200&offset=0&active=1'),
			api('GET', ctx.urls.api.favouriteLocations),
		]).then(function (results) {
			var loc = results[0];
			var bals = results[1];
			var itemMap = indexById(results[2].data);
			var favourites = results[3].data || [];
			var isFavourite = favourites.some(function (f) { return Number(f.id) === id; });
			clear(mount);
			clear(ctx.actions);
			if (ctx.workspaceRole !== 'viewer' && ctx.canWrite) {
				ctx.actions.appendChild(btn(tr('Edit'), {
					onclick: function () { openLocationDialog(ctx, loc); },
				}));
			}
			ctx.actions.appendChild(btn(isFavourite ? tr('Remove favourite') : tr('Add favourite'), {
				'aria-pressed': isFavourite ? 'true' : 'false',
				onclick: function () {
					var call = isFavourite
						? api('DELETE', urlWithId(ctx.urls.api.favouriteLocationRemove, id))
						: api('POST', ctx.urls.api.favouriteLocations, { locationId: id });
					call.then(function () {
						toast(isFavourite ? tr('Removed from favourites.') : tr('Added to favourites.'));
						renderLocationDetail(ctx);
					}).catch(function (err) { toast(err.message, true); });
				},
			}));
			if (ctx.urls.api.locationLabelPrint) {
				ctx.actions.appendChild(el('a', {
					className: 'button primary',
					href: workspaceResourceUrl(ctx, urlWithId(ctx.urls.api.locationLabelPrint, id)),
					target: '_blank',
					rel: 'noopener',
					text: tr('Print location label'),
				}));
			}
			if (ctx.workspaceRole !== 'viewer' && ctx.canWrite) {
				ctx.actions.appendChild(actionsMoreMenu(tr('More'), [
					btn(loc.active ? tr('Archive') : tr('Restore from archive'), {
						onclick: function () {
							api('PUT', entityUrl(ctx.urls.api.locations, id), { active: !loc.active }).then(function () {
								toast(loc.active ? tr('Location archived.') : tr('Location restored.'));
								renderLocationDetail(ctx);
							}).catch(function (err) { toast(err.message, true); });
						},
					}),
				]));
			}
			mount.appendChild(el('h2', { className: 'iv-section__title', text: loc.name }));
			mount.appendChild(el('p', { className: 'iv-muted', text: loc.code + ' · ' + locationKindLabel(loc.kind) }));
			var locHowTo = pageHowToCard(ctx, 'location-detail');
			if (locHowTo) {
				mount.appendChild(locHowTo);
			}
			mount.appendChild(bals.data.length === 0
				? emptyState(
					tr('No stock at this location.'),
					tr('Receive stock here, or transfer from another location.'),
					(ctx.workspaceRole !== 'viewer' && ctx.canWrite)
						? btn(tr('Receive stock'), {
							'data-iv-action': 'receive',
							primary: true,
							className: 'iv-btn--touch',
							onclick: function () { openReceiveDialog(ctx, { locationId: id }); },
						})
						: btn(tr('Transfer stock'), {
							primary: true,
							className: 'iv-btn--touch',
							onclick: function () { openTransferDialog(ctx, { toLocationId: id }); },
						})
				)
				: tableOrCards([
					{ label: tr('Item'), render: function (r) {
						return labelFromMap(itemMap, r.itemId, 'sku');
					} },
					{ label: tr('Qty'), render: function (r) {
						return el('span', {
							'data-iv-balance': r.itemId + ':' + id,
							text: formatQty(r.qty),
						});
					} },
					{ label: tr('Actions'), render: function (r) {
						var kids = [];
						if (ctx.workspaceRole !== 'viewer' && ctx.canWrite) {
							kids.push(btn(tr('Receive'), {
								className: 'iv-btn--touch',
								onclick: function () {
									openReceiveDialog(ctx, { itemId: r.itemId, locationId: id });
								},
							}));
						}
						kids.push(btn(tr('Issue'), {
							primary: !ctx.canWrite,
							className: 'iv-btn--touch',
							onclick: function () {
								openIssueDialog(ctx, { itemId: r.itemId, locationId: id });
							},
						}));
						return el('div', { className: 'iv-row__actions', 'data-iv-balance-actions': '1' }, kids);
					} },
				], bals.data, { caption: tr('Balances at this location') }));
		}).catch(function (err) {
			setBusy(mount, false);
			clear(mount);
			mount.appendChild(loadErrorPanel(err.message, function () { renderLocationDetail(ctx); }));
			toast(err.message, true);
		});
	}

	function paginationBar(total, limit, offset, onPage) {
		var page = Math.floor(offset / limit) + 1;
		var pages = Math.max(1, Math.ceil(total / limit));
		var from = total === 0 ? 0 : offset + 1;
		var to = Math.min(offset + limit, total);
		var kids = [];
		// Prefer omit over disable (plan §7): no dead Previous/Next buttons.
		if (offset > 0) {
			kids.push(btn(tr('Previous'), {
				onclick: function () { onPage(Math.max(0, offset - limit)); },
			}));
		}
		kids.push(el('p', {
			className: 'iv-pagination__info',
			text: tr('Showing {from}–{to} of {total}', {
				from: String(from),
				to: String(to),
				total: String(total),
			}) + (pages > 1 ? ' · ' + tr('Page {page} of {pages}', {
				page: String(page),
				pages: String(pages),
			}) : ''),
		}));
		if (offset + limit < total) {
			kids.push(btn(tr('Next'), {
				onclick: function () { onPage(offset + limit); },
			}));
		}
		return el('nav', {
			className: 'iv-pagination',
			'aria-label': tr('Pagination'),
		}, kids);
	}

	function renderMovements(ctx, filters) {
		var mount = ctx.mount;
		var state = Object.assign({
			kind: '',
			itemId: '',
			itemQuery: '',
			locationId: '',
			locationQuery: '',
			fromDate: '',
			toDate: '',
			transferGroup: null,
			limit: 50,
			offset: 0,
			_reuseShell: false,
		}, filters || {});
		var reuseShell = !!(state._reuseShell
			&& mount.querySelector('#iv-mov-filter-panel')
			&& mount.querySelector('#iv-movements-results'));
		delete state._reuseShell;
		if (!reuseShell) {
			setBusy(mount, true);
			clear(ctx.actions);
			if (ctx.workspaceRole !== 'viewer' && ctx.canWrite) {
				ctx.actions.appendChild(btn(tr('Receive'), {
					primary: true,
					className: 'iv-btn--touch',
					onclick: function () { openReceiveDialog(ctx); },
				}));
			}
			ctx.actions.appendChild(btn(tr('Issue'), {
				primary: !ctx.canWrite,
				className: 'iv-btn--touch',
				onclick: function () { openIssueDialog(ctx); },
			}));
			if (ctx.workspaceRole !== 'viewer' && ctx.canWrite) {
				ctx.actions.appendChild(actionsMoreMenu(tr('More'), [
					el('a', {
						href: exportCsvHref(ctx, 'movements'),
						className: 'button',
						text: tr('Export CSV'),
					}),
					el('a', {
						href: exportCsvHref(ctx, 'movements_datev'),
						className: 'button',
						text: tr('Export DATEV-style CSV'),
					}),
				]));
			}
		}
		var resultsHost = mount.querySelector('#iv-movements-results');
		if (reuseShell && resultsHost) {
			setBusy(resultsHost, true);
		}
		var fromUnix = dateInputToUnix(state.fromDate, false);
		var toUnix = dateInputToUnix(state.toDate, true);
		var query = movementListQuery({
			kind: state.kind || null,
			itemId: state.itemId || null,
			locationId: state.locationId || null,
			from: fromUnix,
			to: toUnix,
			transferGroup: state.transferGroup || null,
			limit: state.limit,
			offset: state.offset,
		});
		var loadSeq = (mount._ivMovLoadSeq = (mount._ivMovLoadSeq || 0) + 1);
		Promise.all([
			api('GET', ctx.urls.api.movements + '?' + query),
			loadMasters(ctx),
		]).then(function (results) {
			if (loadSeq !== mount._ivMovLoadSeq) {
				return;
			}
			var res = results[0];
			var masters = results[1];
			var items = masters.items || [];
			var locations = masters.locations || [];
			var itemMap = indexById(items);
			var locMap = indexById(locations);
			hydrateMaps(ctx, itemMap, locMap, res.data || []).then(function () {
				if (loadSeq !== mount._ivMovLoadSeq) return;
				if (!mount.querySelector('#iv-mov-filter-panel')) return;
				mount.querySelectorAll('[data-iv-label-item]').forEach(function (node) {
					var id = node.getAttribute('data-iv-label-item');
					node.textContent = labelFromMap(itemMap, id, 'sku');
				});
				mount.querySelectorAll('[data-iv-label-loc]').forEach(function (node) {
					var id = node.getAttribute('data-iv-label-loc');
					node.textContent = labelFromMap(locMap, id, 'code');
				});
			});

			function paintResults(listMount, kindSelectRef, itemQueryRef, locQueryRef, readFiltersFn) {
				clear(listMount);
				setBusy(listMount, false);
				var filtersActive = !!(
					state.kind
					|| state.itemId
					|| state.locationId
					|| state.fromDate
					|| state.toDate
					|| state.transferGroup
				);
				// Bachus: panel already shows kind/item/location/dates — only surface
				// invisible transfer-group filters as a chip (Clear stays in the panel).
				if (state.transferGroup) {
					listMount.appendChild(el('div', {
						className: 'iv-callout iv-callout--info iv-filter-active',
						role: 'status',
					}, [
						el('p', {
							className: 'iv-callout__text',
							text: tr('Showing transfer group') + ': ' + state.transferGroup,
						}),
						btn(tr('Clear'), {
							'data-iv-action': 'filter-clear',
							onclick: function () { renderMovements(ctx, null); },
						}),
					]));
				}
				if (!res.data.length) {
					listMount.appendChild(emptyState(
						filtersActive ? tr('No movements match these filters') : tr('No movements yet'),
						filtersActive
							? tr('Clear filters or widen the date range.')
							: tr('Bookings appear here after receive, issue, transfer, or adjust.'),
						filtersActive
							? btn(tr('Clear'), {
							'data-iv-action': 'filter-clear', primary: true, onclick: function () { renderMovements(ctx, null); } })
							: ((ctx.workspaceRole !== 'viewer' && ctx.canWrite)
								? btn(tr('Receive stock'), {
							'data-iv-action': 'receive', primary: true, onclick: function () { openReceiveDialog(ctx); } })
								: btn(tr('Issue stock'), {
							'data-iv-action': 'issue', primary: true, onclick: function () { openIssueDialog(ctx); } }))
					));
					return;
				}
				listMount.appendChild(tableOrCards([
					{ label: tr('Kind'), render: function (r) {
						var m = kindMeta(r.kind);
						return el('span', { className: 'iv-badge ' + m.badge, text: m.label });
					} },
					{ label: tr('Item'), render: function (r) {
						return el('span', {
							'data-iv-label-item': String(r.itemId),
							text: labelFromMap(itemMap, r.itemId, 'sku'),
						});
					} },
					{ label: tr('Location'), render: function (r) {
						return el('span', {
							'data-iv-label-loc': String(r.locationId),
							text: labelFromMap(locMap, r.locationId, 'code'),
						});
					} },
					{ label: tr('Delta'), key: 'qtyDelta' },
					{ label: tr('After'), key: 'qtyAfter' },
					{ label: tr('Group'), render: function (r) {
						if (!r.transferGroup) {
							return el('span', { className: 'iv-muted', text: '—' });
						}
						return el('button', {
							type: 'button',
							className: 'button iv-linkish',
							text: String(r.transferGroup).slice(0, 8) + '…',
							title: r.transferGroup,
							'aria-label': tr('Filter by transfer group'),
							onclick: function () {
								renderMovements(ctx, Object.assign(readFiltersFn({
									transferGroup: r.transferGroup,
									offset: 0,
								}), { _reuseShell: true }));
							},
						});
					} },
					{ label: tr('By'), key: 'createdBy' },
					{ label: tr('Actions'), render: function (r) {
						if (!canReverseMovement(ctx, r)) {
							return el('span', { className: 'iv-muted', text: '—' });
						}
						return btn(tr('Reverse'), {
							'data-iv-action': 'reverse',
							onclick: function () { openReverseFromMovement(ctx, r); },
						});
					} },
				], res.data));
				listMount.appendChild(paginationBar(res.total, state.limit, state.offset, function (nextOffset) {
					renderMovements(ctx, Object.assign(readFiltersFn({
						offset: nextOffset,
					}), { _reuseShell: true }));
				}));
			}

			if (reuseShell) {
				var existingKind = mount.querySelector('#iv-mov-kind');
				var existingItem = mount.querySelector('#iv-mov-item');
				var existingLoc = mount.querySelector('#iv-mov-loc');
				paintResults(
					mount.querySelector('#iv-movements-results'),
					existingKind,
					existingItem ? existingItem.value : state.itemQuery,
					existingLoc ? existingLoc.value : state.locationQuery,
					function (extra) {
						return Object.assign({
							kind: existingKind ? existingKind.value : state.kind,
							itemId: state.itemId,
							itemQuery: existingItem ? existingItem.value : state.itemQuery,
							locationId: state.locationId,
							locationQuery: existingLoc ? existingLoc.value : state.locationQuery,
							fromDate: (mount.querySelector('#iv-mov-from') || {}).value || '',
							toDate: (mount.querySelector('#iv-mov-to') || {}).value || '',
							transferGroup: state.transferGroup,
							limit: state.limit,
							offset: 0,
						}, extra || {});
					}
				);
				return;
			}

			clear(mount);
			setBusy(mount, false);

			var movHowTo = pageHowToCard(ctx, 'movements');
			if (movHowTo) {
				mount.appendChild(movHowTo);
			}

			var kindSelect = el('select', {
				className: 'iv-input form-select',
				id: 'iv-mov-kind',
			});
			[
				{ v: '', t: tr('All kinds') },
				{ v: 'receive', t: tr('Receive') },
				{ v: 'issue', t: tr('Issue') },
				{ v: 'transfer_out', t: tr('Transfer out') },
				{ v: 'transfer_in', t: tr('Transfer in') },
				{ v: 'adjust', t: tr('Adjust') },
			].forEach(function (o) {
				kindSelect.appendChild(el('option', {
					value: o.v,
					text: o.t,
					selected: state.kind === o.v ? '' : null,
				}));
			});

			var itemQuery = '';
			if (state.itemId && itemMap[String(state.itemId)]) {
				itemQuery = masterLabel(itemMap[String(state.itemId)], 'sku');
			} else if (state.itemQuery) {
				itemQuery = state.itemQuery;
			}
			var locQuery = '';
			if (state.locationId && locMap[String(state.locationId)]) {
				locQuery = masterLabel(locMap[String(state.locationId)], 'code');
			} else if (state.locationQuery) {
				locQuery = state.locationQuery;
			}

			var itemInput = el('input', {
				type: 'search',
				className: 'iv-input form-input',
				id: 'iv-mov-item',
				value: itemQuery,
				placeholder: tr('SKU or name'),
				autocomplete: 'off',
				list: 'iv-mov-item-dl',
			});
			var itemList = el('datalist', { id: 'iv-mov-item-dl' });
			fillDatalist(itemList, items, 'sku');

			var locInput = el('input', {
				type: 'search',
				className: 'iv-input form-input',
				id: 'iv-mov-loc',
				value: locQuery,
				placeholder: tr('Code or name'),
				autocomplete: 'off',
				list: 'iv-mov-loc-dl',
			});
			var locList = el('datalist', { id: 'iv-mov-loc-dl' });
			fillDatalist(locList, locations, 'code');

			var fromInput = el('input', {
				type: 'date',
				className: 'iv-input form-input',
				id: 'iv-mov-from',
				value: state.fromDate || '',
			});
			var toInput = el('input', {
				type: 'date',
				className: 'iv-input form-input',
				id: 'iv-mov-to',
				value: state.toDate || '',
			});

			var filterError = el('p', {
				id: 'iv-mov-date-error',
				className: 'iv-callout iv-callout--danger iv-filter-feedback',
				role: 'alert',
				hidden: '',
			});

			function readFilters(extra) {
				return Object.assign({
					kind: kindSelect.value || '',
					itemId: state.itemId || '',
					itemQuery: itemInput.value || '',
					locationId: state.locationId || '',
					locationQuery: locInput.value || '',
					fromDate: fromInput.value || '',
					toDate: toInput.value || '',
					transferGroup: state.transferGroup,
					limit: state.limit,
					offset: 0,
				}, extra || {});
			}

			function dateRangeInvalid() {
				return !!(fromInput.value && toInput.value && fromInput.value > toInput.value);
			}

			function showFilterError(msg) {
				filterError.hidden = !msg;
				filterError.textContent = msg || '';
			}

			function syncDateValidity() {
				var bad = dateRangeInvalid();
				fromInput.setAttribute('aria-invalid', bad ? 'true' : 'false');
				toInput.setAttribute('aria-invalid', bad ? 'true' : 'false');
				if (bad) {
					showFilterError(tr('"From" must be on or before "To".'));
				} else if (filterError.textContent === tr('"From" must be on or before "To".')) {
					showFilterError('');
				}
				return !bad;
			}

			function applyFilters() {
				if (!syncDateValidity()) {
					fromInput.focus();
					return;
				}
				var nextItemId = '';
				var itemText = (itemInput.value || '').trim();
				if (itemText) {
					var resolvedItem = resolveMasterId(itemText, items, 'sku');
					if (resolvedItem === null) {
						itemInput.setAttribute('aria-invalid', 'true');
						showFilterError(tr('No matching item — pick a suggestion or clear the field.'));
						itemInput.focus();
						return;
					}
					nextItemId = resolvedItem;
				}
				itemInput.setAttribute('aria-invalid', 'false');

				var nextLocId = '';
				var locText = (locInput.value || '').trim();
				if (locText) {
					var resolvedLoc = resolveMasterId(locText, locations, 'code');
					if (resolvedLoc === null) {
						locInput.setAttribute('aria-invalid', 'true');
						showFilterError(tr('No matching location — pick a suggestion or clear the field.'));
						locInput.focus();
						return;
					}
					nextLocId = resolvedLoc;
				}
				locInput.setAttribute('aria-invalid', 'false');
				showFilterError('');

				renderMovements(ctx, Object.assign(readFilters({
					itemId: nextItemId,
					itemQuery: itemText,
					locationId: nextLocId,
					locationQuery: locText,
				}), { _reuseShell: true }));
			}

			fromInput.setAttribute('aria-describedby', 'iv-mov-date-error');
			toInput.setAttribute('aria-describedby', 'iv-mov-date-error');
			itemInput.setAttribute('aria-describedby', 'iv-mov-date-error');
			locInput.setAttribute('aria-describedby', 'iv-mov-date-error');
			fromInput.addEventListener('change', function () {
				if (syncDateValidity()) {
					applyFilters();
				}
			});
			toInput.addEventListener('change', function () {
				if (syncDateValidity()) {
					applyFilters();
				}
			});
			// Kind is a closed list — apply immediately (one less click).
			kindSelect.addEventListener('change', function () {
				applyFilters();
			});
			// Bachus: auto-apply when item/location resolve (or are cleared); Apply stays for AT.
			function tryAutoApplyFilters() {
				if (!syncDateValidity()) {
					return;
				}
				var itemText = (itemInput.value || '').trim();
				if (itemText && resolveMasterId(itemText, items, 'sku') === null) {
					return;
				}
				var locText = (locInput.value || '').trim();
				if (locText && resolveMasterId(locText, locations, 'code') === null) {
					return;
				}
				applyFilters();
			}
			itemInput.addEventListener('change', tryAutoApplyFilters);
			locInput.addEventListener('change', tryAutoApplyFilters);
			bindLiveSearch(itemInput, tryAutoApplyFilters, 420);
			bindLiveSearch(locInput, tryAutoApplyFilters, 420);

			mount.appendChild(el('section', {
				id: 'iv-mov-filter-panel',
				className: 'iv-card iv-filter-panel',
				'aria-labelledby': 'iv-mov-filter-title',
			}, [
				el('header', { className: 'iv-filter-panel__head' }, [
					el('div', { className: 'iv-filter-panel__head-text' }, [
						el('h2', { id: 'iv-mov-filter-title', className: 'iv-sr-only', text: tr('Filter movements') }),
						el('p', {
							className: 'iv-filter-panel__intro',
							text: tr('Filters update as you choose.'),
						}),
					]),
				]),
				el('div', { className: 'iv-filter-panel__body' }, [
					el('form', {
						className: 'iv-filter-panel__form iv-filterbar',
						role: 'search',
						'aria-label': tr('Filter movements'),
						novalidate: '',
						onsubmit: function (ev) {
							ev.preventDefault();
							applyFilters();
						},
					}, [
						el('div', {
							className: 'iv-filter-grid iv-filter-grid--movements',
							role: 'group',
							'aria-label': tr('Filter options'),
						}, [
							el('div', { className: 'iv-filter-field iv-filter-field--kind' }, [
								el('label', { className: 'iv-filter-field__label', for: 'iv-mov-kind', text: tr('Kind') }),
								el('div', { className: 'iv-filter-field__control' }, [kindSelect]),
							]),
							el('div', { className: 'iv-filter-field iv-filter-field--item' }, [
								el('label', { className: 'iv-filter-field__label', for: 'iv-mov-item', text: tr('Item') }),
								el('div', { className: 'iv-filter-field__control' }, [itemInput, itemList]),
							]),
							el('div', { className: 'iv-filter-field iv-filter-field--location' }, [
								el('label', { className: 'iv-filter-field__label', for: 'iv-mov-loc', text: tr('Location') }),
								el('div', { className: 'iv-filter-field__control' }, [locInput, locList]),
							]),
							el('div', { className: 'iv-filter-field iv-filter-field--actions' }, [
								el('span', { className: 'iv-filter-field__label iv-sr-only', text: tr('Actions') }),
								el('div', { className: 'iv-filter-field__control iv-filter-field__control--actions' }, [
								btn(tr('Apply'), {
							'data-iv-action': 'filter-apply',
									type: 'submit',
								}),
									btn(tr('Clear'), {
							'data-iv-action': 'filter-clear',
										type: 'button',
										onclick: function () { renderMovements(ctx, null); },
									}),
								]),
							]),
							el('div', {
								className: 'iv-filter-field iv-filter-field--dates',
								role: 'group',
								'aria-labelledby': 'iv-mov-date-range-label',
							}, [
								el('span', {
									id: 'iv-mov-date-range-label',
									className: 'iv-filter-field__label',
									text: tr('Date range'),
								}),
								el('div', { className: 'iv-filter-field__control' }, [
									el('div', { className: 'iv-date-range' }, [
										el('div', { className: 'iv-date-range__part' }, [
											el('label', {
												className: 'iv-date-range__sublabel iv-sr-only',
												for: 'iv-mov-from',
												text: tr('From'),
											}),
											fromInput,
										]),
										el('span', {
											className: 'iv-date-range__sep',
											'aria-hidden': 'true',
											text: tr('to'),
										}),
										el('div', { className: 'iv-date-range__part' }, [
											el('label', {
												className: 'iv-date-range__sublabel iv-sr-only',
												for: 'iv-mov-to',
												text: tr('To'),
											}),
											toInput,
										]),
									]),
								]),
							]),
						]),
						filterError,
					]),
				]),
			]));
			syncDateValidity();

			var resultsHost = el('div', {
				id: 'iv-movements-results',
				className: 'iv-movements-results',
				'aria-live': 'polite',
			});
			mount.appendChild(resultsHost);
			paintResults(resultsHost, kindSelect, itemQuery, locQuery, readFilters);
		}).catch(function (err) {
			if (loadSeq !== mount._ivMovLoadSeq) {
				return;
			}
			setBusy(mount, false);
			var resultsHost = mount.querySelector('#iv-movements-results');
			if (reuseShell && resultsHost) {
				setBusy(resultsHost, false);
				clear(resultsHost);
				resultsHost.appendChild(loadErrorPanel(err.message, function () { renderMovements(ctx); }));
			} else {
				clear(mount);
				mount.appendChild(loadErrorPanel(err.message, function () { renderMovements(ctx); }));
			}
			toast(err.message, true);
		});
	}

	function announce(message) {
		var live = $('#iv-live-region');
		if (!live) return;
		live.textContent = '';
		window.setTimeout(function () { live.textContent = message; }, 10);
	}

	var pickerIdSeq = 0;
	function nextPickerId(prefix) {
		pickerIdSeq += 1;
		return prefix + '-' + pickerIdSeq;
	}

	function pickerItemLabel(item) {
		return item.displayName === item.id ? item.id : (item.displayName + ' (' + item.id + ')');
	}

	/**
	 * Search + pick + chips control for Nextcloud user/group ids.
	 * Portfolio rule (planning/check-productivity-suite/ACCESS-AND-DIRECTORY-PICKERS.md
	 * §1 "Never ask humans to type raw IDs"): this is the *only* way settings
	 * collect a uid/gid — there is no free-text fallback. The server still
	 * re-validates every id on save regardless of what this widget sends.
	 *
	 * opts.multi=true (default): chips accumulate, each with a remove button.
	 * opts.multi=false: picking replaces the single current selection (used
	 * for the per-location ACL subject and mobile seat assignment).
	 * opts.kind: fixed 'user' | 'group'. opts.kindFn(): overrides opts.kind
	 * per-call for controls whose target type changes at runtime (ACL subject
	 * type dropdown).
	 */
	function createIdPicker(opts) {
		opts = opts || {};
		var multi = opts.multi !== false;
		var disabled = !!opts.disabled;
		var state = (opts.initialIds || []).map(function (id) {
			return { id: String(id), displayName: String(id) };
		});
		var searchId = nextPickerId('iv-pk-search');
		var resultsId = nextPickerId('iv-pk-results');
		var timer = null;

		function currentKind() {
			return typeof opts.kindFn === 'function' ? opts.kindFn() : (opts.kind || 'user');
		}

		function searchUrlFor(kind) {
			return (opts.searchUrls || {})[kind] || '';
		}

		function dedupeById(items) {
			var seen = {};
			var out = [];
			items.forEach(function (it) {
				if (!it || !it.id || seen[it.id]) return;
				seen[it.id] = true;
				out.push(it);
			});
			return out;
		}

		var chipsList = el('ul', { className: 'iv-chips iv-picker__chips' });
		var searchInput = el('input', {
			type: 'search',
			className: 'iv-input',
			id: searchId,
			autocomplete: 'off',
			spellcheck: 'false',
			role: 'combobox',
			'aria-expanded': 'false',
			'aria-controls': resultsId,
			'aria-autocomplete': 'list',
			placeholder: tr('Search by name…'),
			disabled: disabled ? '' : null,
		});
		var resultsList = el('ul', {
			className: 'iv-picker__results',
			id: resultsId,
			role: 'listbox',
			hidden: '',
		});

		function renderChips() {
			clear(chipsList);
			if (!state.length) {
				chipsList.appendChild(el('li', { className: 'iv-muted iv-picker__empty-chip', text: opts.emptyLabel || tr('None selected') }));
				return;
			}
			state.forEach(function (item) {
				var label = pickerItemLabel(item);
				var kids = [el('span', { text: label })];
				if (!disabled) {
					kids.push(el('button', {
						type: 'button',
						className: 'iv-chip__remove',
						'aria-label': tr('Remove {name}', { name: label }),
						text: '\u00d7',
						onclick: function () {
							state = state.filter(function (s) { return s.id !== item.id; });
							renderChips();
							announce(tr('Removed {name}.', { name: label }));
							if (opts.onChange) opts.onChange(getIds());
						},
					}));
				}
				chipsList.appendChild(el('li', { className: 'iv-chip' }, kids));
			});
		}

		function closeResults() {
			clear(resultsList);
			resultsList.hidden = true;
			searchInput.setAttribute('aria-expanded', 'false');
		}

		function showResultsMessage(text) {
			clear(resultsList);
			resultsList.hidden = false;
			searchInput.setAttribute('aria-expanded', 'true');
			resultsList.appendChild(el('li', { className: 'iv-picker__empty', 'aria-disabled': 'true', text: text }));
		}

		function focusOption(optionEls, index) {
			optionEls.forEach(function (node, i) {
				node.tabIndex = i === index ? 0 : -1;
				node.setAttribute('aria-selected', i === index ? 'true' : 'false');
			});
			if (optionEls[index]) optionEls[index].focus();
		}

		function pick(item) {
			var label = pickerItemLabel(item);
			if (multi) {
				state = dedupeById(state.concat([item]));
			} else {
				state = [item];
			}
			renderChips();
			searchInput.value = '';
			closeResults();
			searchInput.focus();
			announce(tr('Added {name}.', { name: label }));
			if (opts.onChange) opts.onChange(getIds());
		}

		function renderResults(items) {
			clear(resultsList);
			if (!items.length) {
				showResultsMessage(tr('No matches. Keep typing to refine your search.'));
				return;
			}
			resultsList.hidden = false;
			searchInput.setAttribute('aria-expanded', 'true');
			items.forEach(function (item, index) {
				var li = el('li', {
					role: 'option',
					tabindex: index === 0 ? '0' : '-1',
					'aria-selected': index === 0 ? 'true' : 'false',
					text: pickerItemLabel(item),
				});
				li.addEventListener('click', function () { pick(item); });
				li.addEventListener('keydown', function (event) {
					if (event.key === 'Enter' || event.key === ' ') {
						event.preventDefault();
						pick(item);
						return;
					}
					if (event.key !== 'ArrowDown' && event.key !== 'ArrowUp') return;
					event.preventDefault();
					var optionEls = Array.prototype.slice.call(resultsList.querySelectorAll('[role="option"]'));
					var idx = optionEls.indexOf(li);
					var nextIdx = event.key === 'ArrowDown'
						? Math.min(idx + 1, optionEls.length - 1)
						: Math.max(idx - 1, 0);
					focusOption(optionEls, nextIdx);
				});
				resultsList.appendChild(li);
			});
		}

		function runSearch(q) {
			var kind = currentKind();
			var url = searchUrlFor(kind);
			if (!url) {
				// No directory search configured for this kind — never fall back
				// to a free-text id field; surface the gap instead.
				showResultsMessage(tr('Search is not available right now.'));
				return Promise.resolve([]);
			}
			showResultsMessage(tr('Searching…'));
			return api('GET', url + '?q=' + encodeURIComponent(q)).then(function (res) {
				var raw = (kind === 'group' ? (res && res.groups) : (res && res.users)) || [];
				var items = raw.map(function (it) {
					return { id: String(it.id), displayName: String(it.displayName || it.id) };
				});
				if (searchInput.value.trim() === q) {
					renderResults(items);
				}
				return items;
			}).catch(function (err) {
				if (searchInput.value.trim() === q) {
					showResultsMessage(tr('Could not load results. Try again.'));
				}
				toast(err.message, true);
				return [];
			});
		}

		searchInput.addEventListener('input', function () {
			if (disabled) return;
			if (timer) window.clearTimeout(timer);
			var q = searchInput.value.trim();
			if (q.length < 2) {
				if (q.length === 0) {
					closeResults();
				} else {
					showResultsMessage(tr('Type at least 2 characters to search.'));
				}
				return;
			}
			timer = window.setTimeout(function () { runSearch(q); }, 250);
		});
		searchInput.addEventListener('keydown', function (event) {
			if (event.key !== 'ArrowDown') return;
			var first = resultsList.querySelector('[role="option"]');
			if (first) {
				event.preventDefault();
				first.focus();
			}
		});
		searchInput.addEventListener('blur', function () {
			// Delay so a click on a result (which also blurs the input) still
			// registers before the listbox is torn down.
			window.setTimeout(function () {
				if (!resultsList.contains(document.activeElement)) closeResults();
			}, 150);
		});

		renderChips();

		var wrap = el('div', { className: 'iv-picker' }, [searchInput, resultsList, chipsList]);

		function getIds() { return state.map(function (s) { return s.id; }); }

		return {
			root: wrap,
			searchInput: searchInput,
			getIds: getIds,
			getId: function () { return state.length ? state[0].id : ''; },
			reset: function (ids) {
				state = (ids || []).map(function (id) { return { id: String(id), displayName: String(id) }; });
				renderChips();
			},
		};
	}

	/**
	 * Bachus: searchable multi-select for a fixed option list (e.g. locations).
	 * Same chip UX as directory pickers — no Ctrl/Cmd native <select multiple>.
	 */
	function createLocalOptionPicker(opts) {
		opts = opts || {};
		var multi = opts.multi !== false;
		var options = (opts.options || []).map(function (o) {
			return { id: String(o.id), displayName: String(o.label || o.displayName || o.id) };
		});
		var state = [];
		var searchId = opts.searchId || nextPickerId('iv-loc-pk-search');
		var resultsId = nextPickerId('iv-loc-pk-results');
		var chipsList = el('ul', { className: 'iv-chips iv-picker__chips' });
		var searchInput = el('input', {
			type: 'search',
			className: 'iv-input',
			id: searchId,
			autocomplete: 'off',
			spellcheck: 'false',
			role: 'combobox',
			'aria-expanded': 'false',
			'aria-controls': resultsId,
			'aria-autocomplete': 'list',
			placeholder: opts.placeholder || tr('Search locations…'),
			'aria-label': opts.ariaLabel || tr('Locations to grant'),
		});
		var resultsList = el('ul', {
			className: 'iv-picker__results',
			id: resultsId,
			role: 'listbox',
			hidden: '',
		});

		function selectedSet() {
			var set = {};
			state.forEach(function (s) { set[s.id] = true; });
			return set;
		}

		function renderChips() {
			clear(chipsList);
			if (!state.length) {
				chipsList.appendChild(el('li', {
					className: 'iv-muted iv-picker__empty-chip',
					text: opts.emptyLabel || tr('None selected'),
				}));
				return;
			}
			state.forEach(function (item) {
				chipsList.appendChild(el('li', { className: 'iv-chip' }, [
					el('span', { text: item.displayName }),
					el('button', {
						type: 'button',
						className: 'iv-chip__remove',
						'aria-label': tr('Remove {name}', { name: item.displayName }),
						text: '\u00d7',
						onclick: function () {
							state = state.filter(function (s) { return s.id !== item.id; });
							renderChips();
							announce(tr('Removed {name}.', { name: item.displayName }));
						},
					}),
				]));
			});
		}

		function closeResults() {
			clear(resultsList);
			resultsList.hidden = true;
			searchInput.setAttribute('aria-expanded', 'false');
		}

		function pick(item) {
			if (selectedSet()[item.id]) return;
			state = multi ? state.concat([item]) : [item];
			renderChips();
			searchInput.value = '';
			closeResults();
			searchInput.focus();
			announce(tr('Added {name}.', { name: item.displayName }));
		}

		function filterOptions(q) {
			var needle = (q || '').toLowerCase();
			var taken = selectedSet();
			return options.filter(function (o) {
				if (taken[o.id]) return false;
				if (!needle) return true;
				return o.displayName.toLowerCase().indexOf(needle) >= 0;
			}).slice(0, 40);
		}

		function focusOption(optionEls, nextIdx) {
			optionEls.forEach(function (opt, i) {
				opt.tabIndex = i === nextIdx ? 0 : -1;
				opt.setAttribute('aria-selected', i === nextIdx ? 'true' : 'false');
			});
			optionEls[nextIdx].focus();
		}

		function renderResults(items) {
			clear(resultsList);
			if (!items.length) {
				resultsList.hidden = false;
				searchInput.setAttribute('aria-expanded', 'true');
				resultsList.appendChild(el('li', {
					className: 'iv-picker__empty',
					'aria-disabled': 'true',
					text: tr('No matches. Keep typing to refine your search.'),
				}));
				return;
			}
			resultsList.hidden = false;
			searchInput.setAttribute('aria-expanded', 'true');
			items.forEach(function (item, index) {
				var li = el('li', {
					role: 'option',
					tabindex: index === 0 ? '0' : '-1',
					'aria-selected': index === 0 ? 'true' : 'false',
					text: item.displayName,
				});
				li.addEventListener('click', function () { pick(item); });
				li.addEventListener('keydown', function (event) {
					if (event.key === 'Enter' || event.key === ' ') {
						event.preventDefault();
						pick(item);
						return;
					}
					if (event.key !== 'ArrowDown' && event.key !== 'ArrowUp') return;
					event.preventDefault();
					var optionEls = Array.prototype.slice.call(resultsList.querySelectorAll('[role="option"]'));
					var idx = optionEls.indexOf(li);
					var nextIdx = event.key === 'ArrowDown'
						? Math.min(idx + 1, optionEls.length - 1)
						: Math.max(idx - 1, 0);
					focusOption(optionEls, nextIdx);
				});
				resultsList.appendChild(li);
			});
		}

		searchInput.addEventListener('input', function () {
			var q = searchInput.value.trim();
			if (q.length === 0) {
				closeResults();
				return;
			}
			renderResults(filterOptions(q));
		});
		searchInput.addEventListener('focus', function () {
			if (searchInput.value.trim().length || options.length <= 12) {
				renderResults(filterOptions(searchInput.value.trim()));
			}
		});
		searchInput.addEventListener('keydown', function (event) {
			if (event.key !== 'ArrowDown') return;
			var first = resultsList.querySelector('[role="option"]');
			if (first) {
				event.preventDefault();
				first.focus();
			}
		});
		searchInput.addEventListener('blur', function () {
			window.setTimeout(function () {
				if (!resultsList.contains(document.activeElement)) closeResults();
			}, 150);
		});

		renderChips();
		var wrap = el('div', { className: 'iv-picker iv-local-option-picker' }, [searchInput, resultsList, chipsList]);
		if (opts.rootId) {
			wrap.id = opts.rootId;
		}
		return {
			root: wrap,
			searchInput: searchInput,
			getIds: function () { return state.map(function (s) { return s.id; }); },
			getId: function () { return state.length ? state[0].id : ''; },
			reset: function () {
				state = [];
				renderChips();
			},
		};
	}

	/**
	 * Field wrapper for a picker (mirrors field()'s label/error contract so
	 * applyFieldErrors()/clearFieldErrors() keep working for unknown_user /
	 * unknown_group server responses).
	 */
	function pickerField(labelText, picker, opts) {
		opts = opts || {};
		var id = picker.searchInput.getAttribute('id');
		var errId = id + '-err';
		var err = el('p', { className: 'iv-field__error', id: errId, hidden: '', role: 'alert' });
		picker.searchInput.setAttribute(
			'aria-describedby',
			[picker.searchInput.getAttribute('aria-describedby'), errId].filter(Boolean).join(' ').trim()
		);
		var wrap = el('div', {
			className: 'iv-field',
			'data-iv-field': opts.name || id,
		}, [
			el('label', { className: 'iv-field__label', for: id, text: labelText }),
			picker.root,
			err,
		]);
		wrap._ivInput = picker.searchInput;
		wrap._ivError = err;
		return wrap;
	}


	function renderSettings(ctx) {
		var mount = ctx.mount;
		clear(mount);
		if (!ctx.isWorkspaceAdmin) {
			mount.appendChild(emptyState(tr('Access denied'), tr('Only workspace admins can open these settings.'), null));
			return;
		}
		var section = ctx.settingsSection || 'members';
		if (section === 'members') {
			renderWorkspaceMembers(ctx, { id: ctx.workspaceId }, mount);
			return;
		}
		setBusy(mount, true);
		Promise.all([
			api('GET', ctx.urls.api.config),
			section === 'connections' ? api('GET', ctx.urls.api.flangeStatus) : Promise.resolve({}),
			['connections', 'location-access'].includes(section) ? api('GET', ctx.urls.api.locations + '?limit=200&offset=0&active=1') : Promise.resolve({data: []}),
			['scanners', 'location-access'].includes(section) ? api('GET', ctx.urls.api.scanners + '?limit=200&offset=0') : Promise.resolve({data: []}),
			section === 'notifications' ? api('GET', entityUrl(ctx.urls.api.workspaces, ctx.workspaceId) + '/members') : Promise.resolve({data: []}),
		]).then(function (results) {
			var cfg = results[0], flange = results[1], flangeLocations = results[2].data || [], scanners = results[3].data || [];
			var directoryUrls = {user: ctx.urls.api.directorySearchUsers, group: ctx.urls.api.directorySearchGroups};
			clear(mount);
			setBusy(mount, false);
			function save(url, payload, trigger) {
				if (trigger) trigger.disabled = true;
				clearFieldErrors(mount);
				return api('POST', url, payload).then(function () { toast(tr('Settings saved.')); })
					.catch(function (err) { applyFieldErrors(mount, err.details, err.message); toast(err.message, true); })
					.finally(function () { if (trigger) trigger.disabled = false; });
			}
			if (section === 'codes') {
				var autoSku = el('input', {type: 'checkbox', className: 'iv-switch__input', id: 'iv-auto-sku', checked: cfg.autoSku ? '' : null});
				var skuPrefix = el('input', {type: 'text', className: 'iv-input', maxlength: '48', value: cfg.skuPrefix || 'SKU-'});
				var labelElements = el('fieldset', {className: 'iv-fieldset'}, [el('legend', {text: tr('Label elements')})]);
				var elementInputs = {};
				[
					['qr', tr('QR code')], ['barcode', tr('Code 128 barcode')], ['name', tr('Item name')],
					['sku', tr('SKU text')], ['code', tr('Scan code text')], ['branding', tr('InventoryCheck name')],
				].forEach(function (entry) {
					var input = el('input', {type: 'checkbox', className: 'iv-switch__input', id: 'iv-label-element-' + entry[0], checked: (cfg.labelElements || []).includes(entry[0]) ? '' : null});
					elementInputs[entry[0]] = input;
					labelElements.appendChild(settingsSwitchField(input, entry[1]));
				});
				mount.appendChild(settingsPageSection('codes', 'iv-codes-title', [
					settingsSrTitle('iv-codes-title', tr('SKU and labels')),
					settingsSwitchField(autoSku, tr('Generate an SKU automatically when creating an item')),
					field(tr('SKU prefix'), skuPrefix),
					labelElements,
					settingsFormActions(btn(tr('Save code and label settings'), {primary: true, onclick: function (ev) {
						return save(ctx.urls.api.configCodes, {autoSku: autoSku.checked, skuPrefix: skuPrefix.value.trim(), labelElements: Object.keys(elementInputs).filter(function (key) {return elementInputs[key].checked;})}, ev.currentTarget);
					}})),
				]));
			}
			if (section === 'stock') {
				var neg = el('input', {type: 'checkbox', className: 'iv-switch__input', id: 'iv-allow-negative', checked: cfg.allowNegativeStock ? '' : null});
				mount.appendChild(settingsPageSection('stock', 'iv-stock-title', [
					settingsSrTitle('iv-stock-title', tr('Stock policy')),
					settingsSwitchField(neg, tr('Allow negative stock')),
					settingsFormActions(btn(tr('Save stock policy'), {primary: true, onclick: function (ev) {
						return save(ctx.urls.api.configStock, {allowNegativeStock: neg.checked}, ev.currentTarget);
					}})),
				]));
			}
			if (section === 'notifications') {
				var picker = createLocalOptionPicker({options: results[4].data.filter(function (m) {return m.status === 'accepted';}).map(function (m) {return {id: m.userId, label: m.displayName};}), initialIds: cfg.lowStockNotifyUsers || [], ariaLabel: tr('Notify members'), placeholder: tr('Search workspace members…')});
				picker.reset(cfg.lowStockNotifyUsers || []);
				var hint = el('input', {type: 'checkbox', className: 'iv-switch__input', id: 'iv-reorder-hint', checked: cfg.locationReorderHintEnabled ? '' : null});
				mount.appendChild(settingsPageSection('notifications', 'iv-notify-title', [
					settingsSrTitle('iv-notify-title', tr('Low stock notifications')),
					pickerField(tr('Notify members'), picker),
					el('p', {className: 'iv-field__hint', text: tr('Leave empty to notify the workspace owner and admins.')}),
					settingsSwitchField(hint, tr('Also show low-stock hints per location, not just the total across all locations')),
					settingsFormActions(btn(tr('Save notification settings'), {primary: true, onclick: function (ev) {
						return save(ctx.urls.api.configNotify, {lowStockNotifyUsers: picker.getIds(), locationReorderHintEnabled: hint.checked}, ev.currentTarget);
					}})),
				]));
			}
			var fracEnabled = Number(cfg.qtyScale) === 3;
			var fracKids = [
				settingsSrTitle('iv-frac-title', tr('Fractional quantities')),
			];
			if (fracEnabled) {
				fracKids.push(el('p', {
					className: 'iv-callout iv-callout--success',
					role: 'status',
					text: tr('Fractional quantities are on (up to 3 decimal places). This cannot be turned off.'),
				}));
			} else {
				fracKids.push(el('p', {
					className: 'iv-field__hint',
					text: tr('Turns every quantity into milli-units (×1000). You cannot turn it off later.'),
				}));
				fracKids.push(settingsFormActions(btn(tr('Enable fractional quantities (up to 3 decimals)'), {
					primary: true,
					className: 'iv-btn--touch',
					onclick: function (ev) {
						if (!ctx.urls.api.configFractional) return;
						var trigger = ev && ev.currentTarget ? ev.currentTarget : null;
						dialog(tr('Enable fractional quantities (up to 3 decimals)'), [
							el('p', { className: 'iv-muted', text: tr('This permanently switches every quantity to milli-units (×1000). You cannot turn it off later. Continue?') }),
						], function () {
							clearFieldErrors(mount);
							return api('POST', ctx.urls.api.configFractional, {}).then(function (res) {
								toast(tr('Fractional quantities enabled. Reloading…'));
								if (res && res.qtyScale != null) {
									ctx.qtyScale = Number(res.qtyScale) || 3;
								}
								window.setTimeout(function () {
									window.location.reload();
								}, 400);
							});
						}, tr('Apply'), { restoreFocusTo: trigger });
					},
				})));
			}
			if (section === 'quantities') {
				mount.appendChild(settingsPageSection('quantities', 'iv-frac-title', withSettingsHowTo(ctx, 'quantities', fracKids)));
			}


			if (section === 'location-access') {
			var aclEnabled = el('input', {
				type: 'checkbox',
				className: 'iv-switch__input',
				id: 'iv-loc-acl',
				checked: cfg.locationAclEnabled ? '' : null,
			});
			var aclDevicesStrict = el('input', {
				type: 'checkbox',
				className: 'iv-switch__input',
				id: 'iv-loc-acl-devices-strict',
				checked: null,
			});
			var aclSubjectType = el('select', { className: 'iv-input', id: 'iv-acl-subject-type' }, [
				el('option', { value: 'user', text: tr('User') }),
				el('option', { value: 'group', text: tr('Group') }),
				el('option', { value: 'device', text: tr('Scanner device') }),
			]);
			var aclSubjectPicker = createIdPicker({
				multi: false,
				kindFn: function () { return aclSubjectType.value; },
				searchUrls: directoryUrls,
			});
			var aclDevicePicker = createLocalOptionPicker({
				multi: false,
				rootId: 'iv-acl-device-id',
				searchId: 'iv-acl-device-search',
				ariaLabel: tr('Scanner device to grant'),
				placeholder: tr('Search scanner devices…'),
				emptyLabel: tr('No scanner selected'),
				options: scanners.map(function (d) {
					var label = (d.label || tr('Scanner')) + ' (#' + d.id + ')';
					return { id: d.id, label: label };
				}),
			});
			var aclUserGroupField = pickerField(tr('Search and pick a colleague or group'), aclSubjectPicker, { name: 'subjectId' });
			var aclDeviceField = pickerField(tr('Pick a scanner device'), aclDevicePicker, { name: 'subjectId' });
			aclDeviceField.hidden = true;
			function syncAclSubjectPickers() {
				var isDevice = aclSubjectType.value === 'device';
				aclUserGroupField.hidden = !!isDevice;
				aclDeviceField.hidden = !isDevice;
				aclSubjectPicker.reset([]);
				aclDevicePicker.reset();
			}
			aclSubjectType.addEventListener('change', syncAclSubjectPickers);
			var aclLocationPicker = createLocalOptionPicker({
				rootId: 'iv-acl-loc-ids',
				searchId: 'iv-acl-loc-search',
				ariaLabel: tr('Locations to grant'),
				placeholder: tr('Search locations…'),
				options: flangeLocations.map(function (loc) {
					return { id: loc.id, label: loc.name + ' — ' + loc.code };
				}),
			});
			var aclListHost = el('div', { className: 'iv-stack', id: 'iv-acl-list', role: 'status' });
			var locLabelById = {};
			flangeLocations.forEach(function (loc) {
				locLabelById[String(loc.id)] = loc.name + ' — ' + loc.code;
			});
			var deviceLabelById = {};
			scanners.forEach(function (d) {
				deviceLabelById[String(d.id)] = (d.label || tr('Scanner')) + ' (#' + d.id + ')';
			});

			function renderAclAssignments(payload) {
				if (payload && typeof payload.devicesStrict === 'boolean') {
					aclDevicesStrict.checked = !!payload.devicesStrict;
				}
				clear(aclListHost);
				var rows = (payload && payload.assignments) || [];
				if (!rows.length) {
					aclListHost.appendChild(el('p', {
						className: 'iv-muted',
						text: aclDevicesStrict.checked
							? tr('No location grants yet. Field users see nothing while this is on. With strict scanner binding, unbound scanners also see nothing until you grant locations.')
							: tr('No location grants yet. Field users see nothing while this is on. Unbound scanners stay unrestricted until you grant locations.'),
					}));
					return;
				}
				var groups = {};
				rows.forEach(function (r) {
					var key = String(r.subjectType) + '\0' + String(r.subjectId);
					if (!groups[key]) {
						groups[key] = {
							subjectType: r.subjectType,
							subjectId: String(r.subjectId),
							locationIds: [],
						};
					}
					groups[key].locationIds.push(r.locationId);
				});
				Object.keys(groups).forEach(function (key) {
					var g = groups[key];
					var locLabels = g.locationIds.map(function (id) {
						return locLabelById[String(id)] || ('#' + id);
					}).join(', ');
					var subjectLabel = g.subjectType === 'device'
						? (deviceLabelById[String(g.subjectId)] || ('device:' + g.subjectId))
						: (g.subjectType + ':' + g.subjectId);
					var clearBtn = el('button', {
						type: 'button',
						className: 'iv-btn iv-btn--ghost',
						text: tr('Clear grants'),
						'aria-label': tr('Clear location grants for {name}', { name: subjectLabel }),
						'data-iv-acl-clear': g.subjectType + ':' + g.subjectId,
					});
					clearBtn.addEventListener('click', function () {
						if (!ctx.urls.api.configLocationAcl) return;
						api('PUT', ctx.urls.api.configLocationAcl, {
							enabled: aclEnabled.checked,
							devicesStrict: aclDevicesStrict.checked,
							subjectType: g.subjectType,
							subjectId: g.subjectId,
							locationIds: [],
						}).then(function (payloadRes) {
							toast(tr('Location grants cleared.'));
							renderAclAssignments(payloadRes);
						}).catch(function (err) {
							toast(err.message, true);
						});
					});
					aclListHost.appendChild(el('div', {
						className: 'iv-acl-grant-row',
					}, [
						el('span', { className: 'iv-acl-grant-row__label', text: subjectLabel + ' → ' + locLabels }),
						clearBtn,
					]));
				});
			}

			function loadAcl() {
				if (!ctx.urls.api.configLocationAcl) return Promise.resolve();
				return api('GET', ctx.urls.api.configLocationAcl).then(renderAclAssignments).catch(function (err) {
					toast(err.message, true);
				});
			}
			if (section === 'location-access') {
				loadAcl();
				mount.appendChild(settingsPageSection('location-access', 'iv-loc-acl-title', withSettingsHowTo(ctx, 'location-access', [
					settingsSrTitle('iv-loc-acl-title', tr('Location access')),
					settingsSwitchField(
						aclEnabled,
						tr('Limit field users to granted locations')
					),
					settingsSwitchField(
						aclDevicesStrict,
						tr('Require location grants for scanners (strict)'),
						tr('Strict mode: scanners without grants cannot book. Leave off for legacy unbound scanners.')
					),
					field(tr('Grant to'), aclSubjectType, { name: 'subjectType' }),
					aclUserGroupField,
					aclDeviceField,
					pickerField(tr('Locations to grant'), aclLocationPicker, { name: 'locationIds' }),
					el('p', {
						className: 'iv-field__hint',
						text: tr('Add locations as chips. Use Clear grants below to revoke a subject in one tap.'),
					}),
					settingsFormActions(btn(tr('Save location access'), {
						primary: true,
						className: 'iv-btn--touch',
						onclick: function () {
							if (!ctx.urls.api.configLocationAcl) return;
							clearFieldErrors(mount);
							var ids = aclLocationPicker.getIds().map(function (id) {
								return Number(id);
							}).filter(function (n) { return n > 0; });
							var payload = {
								enabled: aclEnabled.checked,
								devicesStrict: aclDevicesStrict.checked,
							};
							var subjectId = aclSubjectType.value === 'device'
								? String(aclDevicePicker.getId() || '').trim()
								: String(aclSubjectPicker.getId() || '').trim();
							// Only write a grant when a subject is picked — enabled-only saves
							// must not trip setForSubject validation after flipping enabled.
							if (subjectId !== '') {
								payload.subjectType = aclSubjectType.value;
								payload.subjectId = subjectId;
								payload.locationIds = ids;
							}
							return api('PUT', ctx.urls.api.configLocationAcl, payload).then(function (payloadRes) {
								toast(tr('Location access saved.'));
								aclSubjectPicker.reset([]);
								aclDevicePicker.reset();
								aclLocationPicker.reset();
								renderAclAssignments(payloadRes);
							}).catch(function (err) {
								applyFieldErrors(mount, err && err.details, err && err.message);
								toast(err.message, true);
							});
						},
					})),
					el('h3', { className: 'iv-section__subtitle', text: tr('Current grants') }),
					aclListHost,
				])));
			}


			}

			if (section === 'connections') {
			var maintToggle = el('input', {
				type: 'checkbox',
				className: 'iv-switch__input',
				id: 'iv-flange-maint',
				checked: flange.maintFlangeEnabled ? '' : null,
			});
			var projectToggle = el('input', {
				type: 'checkbox',
				className: 'iv-switch__input',
				id: 'iv-flange-project',
				checked: flange.projectFlangeEnabled ? '' : null,
			});
			var invoiceToggle = el('input', {
				type: 'checkbox',
				className: 'iv-switch__input',
				id: 'iv-flange-invoice',
				checked: flange.invoiceFlangeEnabled ? '' : null,
			});
			var invoicePending = (flange.invoicePushPending || 0);
			var invoiceRetryBtn = invoicePending > 0 ? btn(tr('Retry now'), {
				className: 'iv-btn--touch',
				onclick: function () {
					invoiceRetryBtn.disabled = true;
					return api('POST', ctx.urls.api.flangeInvoicePushRetry, {}).then(function (res) {
						toast(res && res.pending > 0
							? tr('Invoice push retried — some still pending.')
							: tr('Invoice pushes delivered.'));
						renderSettings(ctx);
					}).catch(function (err) {
						invoiceRetryBtn.disabled = false;
						toast(err.message, true);
					});
				},
			}) : null;
			var defaultLocSelect = el('select', { className: 'iv-input', id: 'iv-flange-default-loc' });
			fillSelect(defaultLocSelect, flangeLocations, 'id', function (r) {
				return r.name + ' — ' + r.code;
			}, tr('No default location'));
			if (flange.defaultIssueLocationId) defaultLocSelect.value = String(flange.defaultIssueLocationId);
			if (section === 'connections') {
				mount.appendChild(settingsPageSection('connections', 'iv-connections-title', withSettingsHowTo(ctx, 'connections', [
					settingsSrTitle('iv-connections-title', tr('Connections to other Check apps')),
					settingsSwitchField(
						maintToggle,
						flange.maintenanceCheckEnabled
							? tr('Let MaintenanceCheck issue stock for work orders')
							: tr('Let MaintenanceCheck issue stock for work orders (MaintenanceCheck is not installed yet)')
					),
					settingsSwitchField(
						projectToggle,
						flange.projectCheckEnabled
							? tr('Let ProjectCheck issue stock for projects')
							: tr('Let ProjectCheck issue stock for projects (ProjectCheck is not installed yet)')
					),
					settingsSwitchField(
						invoiceToggle,
						flange.invoiceCheckEnabled
							? tr('Send issued materials to the customer\'s draft invoice')
							: tr('Send issued materials to the customer\'s draft invoice (InvoiceCheck is not installed yet)')
					),
					invoicePending > 0 ? field(tr('Pending invoice pushes') + ' (' + invoicePending + ')', invoiceRetryBtn, { name: 'invoicePushRetry' }) : null,
					field(tr('Default issue location'), defaultLocSelect, { name: 'defaultIssueLocationId' }),
					settingsFormActions(btn(tr('Save connection settings'), {
						primary: true,
						className: 'iv-btn--touch',
						onclick: function () {
							clearFieldErrors(mount);
							return api('POST', ctx.urls.api.flangeSettings, {
								maintFlangeEnabled: maintToggle.checked,
								projectFlangeEnabled: projectToggle.checked,
								invoiceFlangeEnabled: invoiceToggle.checked,
								defaultIssueLocationId: defaultLocSelect.value || null,
							}).then(function () {
								toast(tr('Connection settings saved.'));
							}).catch(function (err) {
								applyFieldErrors(mount, err && err.details, err && err.message);
								toast(err.message, true);
							});
						},
					})),
				])));
			}


			}
			var requireAdjustReason = el('input', {
				type: 'checkbox',
				className: 'iv-switch__input',
				id: 'iv-require-adjust-reason',
				checked: cfg.requireAdjustReason ? '' : null,
			});
			var requireLocationScan = el('input', {
				type: 'checkbox',
				className: 'iv-switch__input',
				id: 'iv-require-location-scan',
				checked: cfg.requireLocationScan ? '' : null,
			});
			if (section === 'policies') {
				mount.appendChild(settingsPageSection('policies', 'iv-policies-title', withSettingsHowTo(ctx, 'policies', [
					settingsSrTitle('iv-policies-title', tr('Scan & adjust policies')),
					settingsSwitchField(
						requireAdjustReason,
						tr('Require a reason code on every stock adjust')
					),
					settingsSwitchField(
						requireLocationScan,
						tr('Require scanning the location code when booking from the scan app')
					),
					settingsFormActions(btn(tr('Save scan policies'), {
						primary: true,
						className: 'iv-btn--touch',
						onclick: function () {
							if (!ctx.urls.api.configWaveD) {
								toast(tr('Wave D settings are unavailable on this server.'), true);
								return;
							}
							clearFieldErrors(mount);
							api('POST', ctx.urls.api.configWaveD, {
								requireAdjustReason: requireAdjustReason.checked,
								requireLocationScan: requireLocationScan.checked,
							}).then(function () {
								toast(tr('Scan policies saved.'));
							}).catch(function (err) {
								applyFieldErrors(mount, err && err.details, err && err.message);
								toast(err.message, true);
							});
						},
					})),
				])));
			}


			if (section === 'scanners') renderScanners(ctx, scanners);
		}).catch(function (err) {
			setBusy(mount, false); clear(mount);
			mount.appendChild(loadErrorPanel(err.message, function () {renderSettings(ctx);}));
		});
	}

	function renderScanners(ctx, scanners) {
		var host = el('section', {className: 'iv-section iv-card'});
		var pairCode = el('p', {className: 'iv-pair-code-panel', role: 'status', hidden: ''});
		function showCode(result) {pairCode.hidden = false; pairCode.textContent = tr('Copy this code now. It is shown only once.') + ' ' + result.pairCode;}
		var label = el('input', {className: 'iv-input', maxlength: '255', required: '', placeholder: tr('Scanner label')});
		host.appendChild(field(tr('Scanner label'), label));
		host.appendChild(btn(tr('Create scanner'), {primary: true, onclick: function () {
			return api('POST', ctx.urls.api.scanners, {label: label.value}).then(function (result) {
				showCode(result); label.value = ''; list.appendChild(scannerRow(result.device));
			}).catch(function (err) {toast(err.message, true);});
		}}));
		host.appendChild(pairCode);
		var list = el('div', {className: 'iv-stack'});
		function scannerRow(scanner) {
			var row = el('div', {className: 'iv-row'}, [el('span', {text: scanner.label + ' · ' + tr(scanner.active ? scanner.state : 'Inactive')})]);
			if (scanner.active) {
				row.appendChild(btn(tr('New pairing code'), {onclick: function () {
					return api('POST', entityUrl(ctx.urls.api.scanners, scanner.id) + '/pair-code').then(showCode).catch(function (err) {toast(err.message, true);});
				}}));
				row.appendChild(btn(tr('Deactivate'), {onclick: function () {
					return api('DELETE', entityUrl(ctx.urls.api.scanners, scanner.id)).then(function () {row.remove();}).catch(function (err) {toast(err.message, true);});
				}}));
			}
			return row;
		}
		scanners.forEach(function (scanner) {list.appendChild(scannerRow(scanner));});
		host.appendChild(list); ctx.mount.appendChild(host);
	}

	function openAppSettings(ctx) {
		api('GET', ctx.urls.api.appConfig).then(function (cfg) {
			var enabled = el('input', {type: 'checkbox', className: 'iv-switch__input', id: 'iv-app-lookup-enabled', checked: cfg.enabled ? '' : null});
			var autofire = el('input', {type: 'checkbox', className: 'iv-switch__input', id: 'iv-app-lookup-autofire', checked: cfg.autofire ? '' : null});
			var providers = cfg.providers.map(function (provider) {
				return {id: provider.id, label: provider.label, input: el('input', {type: 'checkbox', id: 'iv-app-provider-' + provider.id, checked: cfg.enabledProviders.includes(provider.id) ? '' : null})};
			});
			var sources = el('fieldset', {className: 'iv-fieldset'}, [el('legend', {text: tr('Product databases to query')})]);
			providers.forEach(function (provider) {sources.appendChild(settingsSwitchField(provider.input, provider.label));});
			var nodes = [
				el('p', {text: tr('These settings apply to every workspace.')}),
				el('h3', {text: tr('Online product lookup')}),
				el('p', {className: 'iv-field__hint', text: tr('Only the barcode is sent to the selected databases. Inventory and account details stay on this server.')}),
				settingsSwitchField(enabled, tr('Look up unknown barcodes online')),
				settingsSwitchField(autofire, tr('Search automatically while typing')),
				sources,
			];
			if (cfg.stats && cfg.stats.totals) nodes.push(el('p', {className: 'iv-muted', text: tr('Lookups: {count}', {count: cfg.stats.totals.lookups || 0})}));
			dialog(tr('App settings'), nodes, function () {
				return api('POST', ctx.urls.api.appConfig, {enabled: enabled.checked, autofire: autofire.checked, providers: providers.filter(function (p) {return p.input.checked;}).map(function (p) {return p.id;})}).then(function () {
					ctx.productLookupEnabled = enabled.checked; ctx.productLookupAutofire = autofire.checked;
					ctx.root.setAttribute('data-iv-product-lookup', enabled.checked ? '1' : '0');
					ctx.root.setAttribute('data-iv-product-lookup-autofire', autofire.checked ? '1' : '0');
					toast(tr('Settings saved.'));
				});
			}, tr('Save'));
		}).catch(function (err) {toast(err.message, true);});
	}

	// ── Stocktake / cycle counts (Wave B1) ─────────────────────────────

	function cycleStatusMeta(status) {
		switch (status) {
			case 'open': return { label: tr('Open'), badge: 'iv-badge--neutral' };
			case 'counting': return { label: tr('Counting'), badge: 'iv-badge--scheduled' };
			case 'closed': return { label: tr('Closed'), badge: 'iv-badge--done' };
			default: return { label: String(status || ''), badge: '' };
		}
	}

	function stocktakeDetailUrl(ctx, id) {
		return urlWithId(ctx.urls.pages.stocktakeCampaign, id);
	}

	/** Create + auto-start a stocktake, then navigate to the count screen. */
	function createAndStartStocktake(ctx, locationId, campaignName) {
		if (stocktakeCreating) {
			return Promise.reject(new ApiError('busy', tr('Stocktake is already being created…')));
		}
		var locId = Number(locationId);
		if (!locId || locId < 1 || locId !== Math.floor(locId)) {
			return Promise.reject(new ApiError('validation_failed', tr('Choose a location.'), [
				{ field: 'locationId', code: 'validation_failed', message: tr('Choose a location.') },
			]));
		}
		var name = String(campaignName || '').trim();
		if (name === '') {
			return Promise.reject(new ApiError('validation_failed', tr('Please check this field.'), [
				{ field: 'name', code: 'validation_failed', message: tr('Please check this field.') },
			]));
		}
		stocktakeCreating = true;
		return api('POST', ctx.urls.api.cycleCounts, {
			locationId: locId,
			name: name,
		}).then(function (campaign) {
			var startUrl = ctx.urls.api.cycleCountStart
				? urlWithId(ctx.urls.api.cycleCountStart, campaign.id)
				: null;
			var go = function () {
				window.location.href = stocktakeDetailUrl(ctx, campaign.id);
			};
			if (!startUrl) {
				toast(tr('Stocktake created.'));
				go();
				return;
			}
			return api('POST', startUrl).then(function () {
				toast(tr('Stocktake ready to count.'));
				go();
			}).catch(function (err) {
				toast(err.message || tr('Stocktake created. Start counting on the next screen.'), true);
				go();
			});
		}).then(function (result) {
			return result;
		}, function (err) {
			stocktakeCreating = false;
			throw err;
		});
	}

	/**
	 * Bachus: searchable tap-to-start location list — never a native <select>
	 * of hundreds of "name — code" rows (unusable + focus-pad blow-up).
	 * Typing searches the locations API (not only the first 200 masters).
	 */
	function createLocationChooser(opts) {
		opts = opts || {};
		var ctx = opts.ctx || null;
		var seedLocations = (opts.locations || []).slice();
		var favIds = opts.favouriteIds || [];
		var maxVisible = opts.maxVisible || 40;
		var busy = false;
		var searchSeq = 0;
		var searchId = opts.searchId || nextPickerId('iv-loc-choose-q');
		var listId = opts.listId || nextPickerId('iv-loc-choose-list');
		var labelId = listId + '-label';
		var showSearch = seedLocations.length > 8 || !!(ctx && ctx.urls && ctx.urls.api && ctx.urls.api.locations);
		var search = el('input', {
			type: 'search',
			className: 'iv-input iv-loc-chooser__search',
			id: searchId,
			autocomplete: 'off',
			spellcheck: 'false',
			placeholder: tr('Search locations…'),
			'aria-label': tr('Search locations'),
			'aria-controls': listId,
			hidden: showSearch ? undefined : '',
			'aria-hidden': showSearch ? undefined : 'true',
		});
		var list = el('div', {
			className: 'iv-loc-chooser__list',
			id: listId,
			role: 'listbox',
			'aria-labelledby': labelId,
		});
		var status = el('p', {
			className: 'iv-loc-chooser__status iv-muted',
			role: 'status',
			hidden: '',
		});
		var err = el('p', {
			className: 'iv-field__error',
			id: searchId + '-err',
			hidden: '',
			role: 'alert',
		});

		function isFav(id) {
			return favIds.indexOf(Number(id)) >= 0;
		}

		function sortLocs(rows) {
			return (rows || []).slice().sort(function (a, b) {
				var af = isFav(a.id) ? 0 : 1;
				var bf = isFav(b.id) ? 0 : 1;
				if (af !== bf) return af - bf;
				return String(a.name || '').localeCompare(String(b.name || ''));
			});
		}

		function matchesLocal(loc, needle) {
			if (!needle) {
				return true;
			}
			var hay = (String(loc.name || '') + ' ' + String(loc.code || '')).toLowerCase();
			return hay.indexOf(needle) >= 0;
		}

		function setBusy(on) {
			busy = !!on;
			wrap.setAttribute('aria-busy', on ? 'true' : 'false');
			search.disabled = on;
			Array.prototype.forEach.call(list.querySelectorAll('button'), function (b) {
				b.disabled = on;
			});
		}

		function paint(rows, meta) {
			meta = meta || {};
			clear(list);
			var shown = (rows || []).slice(0, maxVisible);
			if (!shown.length) {
				list.appendChild(el('p', {
					className: 'iv-loc-chooser__empty iv-muted',
					text: tr('No locations match.'),
				}));
				status.hidden = true;
				status.textContent = '';
				return;
			}
			shown.forEach(function (loc) {
				var fav = isFav(loc.id);
				var labelName = loc.name || loc.code || ('#' + loc.id);
				list.appendChild(el('button', {
					type: 'button',
					className: 'iv-loc-chooser__item' + (fav ? ' iv-loc-chooser__item--fav' : ''),
					role: 'option',
					disabled: busy ? '' : undefined,
					'aria-label': tr('Start stocktake at {name}', { name: labelName }),
					onclick: function () {
						if (busy || typeof opts.onPick !== 'function') {
							return;
						}
						// Arm busy before the async create path to kill double-tap races.
						setBusy(true);
						opts.onPick(loc);
					},
				}, [
					fav ? el('span', {
						className: 'iv-loc-chooser__star',
						'aria-hidden': 'true',
						text: '\u2605',
					}) : null,
					el('span', { className: 'iv-loc-chooser__text' }, [
						el('span', { className: 'iv-loc-chooser__name', text: labelName }),
						loc.code && loc.name
							? el('span', { className: 'iv-loc-chooser__code', text: String(loc.code) })
							: null,
					]),
				]));
			});
			var total = meta.total != null ? Number(meta.total) : (rows || []).length;
			if (total > shown.length) {
				status.hidden = false;
				status.textContent = tr('Showing {shown} of {total}. Type to find more.', {
					shown: String(shown.length),
					total: String(total),
				});
			} else {
				status.hidden = true;
				status.textContent = '';
			}
		}

		function renderLocal() {
			var needle = search.value.trim().toLowerCase();
			var filtered = sortLocs(seedLocations.filter(function (loc) {
				return matchesLocal(loc, needle);
			}));
			paint(filtered, { total: filtered.length });
		}

		function renderFromApi() {
			if (!ctx || !ctx.urls || !ctx.urls.api || !ctx.urls.api.locations) {
				renderLocal();
				return;
			}
			var term = search.value.trim();
			if (!term) {
				renderLocal();
				return;
			}
			var seq = ++searchSeq;
			status.hidden = false;
			status.textContent = tr('Searching…');
			api(
				'GET',
				ctx.urls.api.locations + '?limit=' + maxVisible + '&offset=0&active=1&q=' + encodeURIComponent(term)
			).then(function (res) {
				if (seq !== searchSeq) {
					return;
				}
				paint(sortLocs(res.data || []), { total: res.total != null ? res.total : (res.data || []).length });
			}).catch(function () {
				if (seq !== searchSeq) {
					return;
				}
				renderLocal();
			});
		}

		if (showSearch && ctx && ctx.urls && ctx.urls.api && ctx.urls.api.locations) {
			bindLiveSearch(search, renderFromApi, 220);
		} else {
			search.addEventListener('input', renderLocal);
		}

		var wrap = el('div', {
			className: 'iv-field iv-loc-chooser',
			'data-iv-field': 'locationId',
			'data-iv-loc-chooser': '1',
		}, [
			el('p', {
				className: 'iv-field__label' + (opts.labelSrOnly ? ' iv-sr-only' : ''),
				id: labelId,
				text: opts.labelText || tr('Where are you counting?'),
			}),
			showSearch ? search : null,
			list,
			status,
			err,
		]);
		wrap._ivInput = search;
		wrap._ivError = err;
		wrap._ivSetBusy = setBusy;
		renderLocal();
		return {
			root: wrap,
			searchInput: search,
			setBusy: setBusy,
			focus: function () {
				if (showSearch && !search.hidden) {
					search.focus();
					return;
				}
				var first = list.querySelector('button');
				if (first) {
					first.focus();
				}
			},
		};
	}

	/**
	 * Bachus: multi-location inventur starts on a dedicated page — never a
	 * giant modal that soft-keyboard helpers inflate with padding-bottom.
	 * Zero locations → toast. One location → 1-click create+start.
	 */
	function openNewCampaignDialog(ctx) {
		if (stocktakeCreating || stocktakeOpenBusy) {
			toast(tr('Stocktake is already being created…'), true);
			return;
		}
		stocktakeOpenBusy = true;
		var campaignTrigger = document.activeElement;
		var campaignReady = loadMasters(ctx).then(function (masters) {
			var locs = (masters.locations || []).filter(function (r) { return r.active !== false; });
			var today = new Date();
			// Local calendar day — toISOString() is UTC (off-by-one in negative offsets).
			var defaultName = tr('Stocktake {date}', { date: today.getFullYear() + '-' + String(today.getMonth() + 1).padStart(2, '0') + '-' + String(today.getDate()).padStart(2, '0') });
			// Bachus: one location → skip the chooser entirely (1-click inventur).
			if (locs.length === 1) {
				stocktakeOpenBusy = false;
				createAndStartStocktake(ctx, Number(locs[0].id), defaultName).catch(function (err) {
					toast(err.message || tr('Could not start stocktake.'), true);
				});
				return;
			}
			if (locs.length === 0) {
				stocktakeOpenBusy = false;
				toast(tr('Add a location first, then start a stocktake.'), true);
				return;
			}
			stocktakeOpenBusy = false;
			var newUrl = ctx.urls && ctx.urls.pages && ctx.urls.pages.stocktakeNew;
			if (ctx.page === 'stocktake-new') {
				renderStocktakeNewPage(ctx, masters, locs, defaultName);
				return;
			}
			if (newUrl) {
				window.location.href = newUrl;
				return;
			}
			// Fallback if URL map is stale: still render chooser on current mount.
			renderStocktakeNewPage(ctx, masters, locs, defaultName);
		}).catch(function (err) {
			stocktakeOpenBusy = false;
			toast(err.message || tr('Could not load locations.'), true);
		});
		busyTriggerUntil(campaignTrigger, campaignReady);
	}

	function renderStocktakeNewPage(ctx, mastersOpt, locsOpt, defaultNameOpt) {
		var mount = ctx.mount;
		var name = el('input', { type: 'text', className: 'iv-input', maxlength: '255' });
		var today = new Date();
		var defaultName = defaultNameOpt || tr('Stocktake {date}', { date: today.getFullYear() + '-' + String(today.getMonth() + 1).padStart(2, '0') + '-' + String(today.getDate()).padStart(2, '0') });
		name.value = defaultName;

		function denyNonOffice() {
			clear(mount);
			setBusy(mount, false);
			clear(ctx.actions);
			if (ctx.urls && ctx.urls.pages && ctx.urls.pages.stocktake) {
				ctx.actions.appendChild(el('a', {
					href: ctx.urls.pages.stocktake,
					className: 'button',
					text: tr('Back to stocktakes'),
				}));
			}
			mount.appendChild(emptyState(
				tr('Office access required'),
				tr('Only office users and app admins can start a stocktake.'),
				ctx.urls && ctx.urls.pages && ctx.urls.pages.stocktake
					? el('a', {
						href: ctx.urls.pages.stocktake,
						className: 'button primary',
						text: tr('Back to stocktakes'),
					})
					: null
			));
		}

		if (ctx.workspaceRole === 'viewer' || !ctx.canWrite) {
			denyNonOffice();
			return;
		}

		function paint(masters, locs) {
			clear(mount);
			setBusy(mount, false);
			clear(ctx.actions);
			if (ctx.urls && ctx.urls.pages && ctx.urls.pages.stocktake) {
				ctx.actions.appendChild(el('a', {
					href: ctx.urls.pages.stocktake,
					className: 'button',
					text: tr('Back to stocktakes'),
				}));
			}
			var formHost = el('div', {
				className: 'iv-card iv-stocktake-new',
				role: 'region',
				'aria-labelledby': 'iv-stocktake-new-title',
			});
			var bodyHost = el('div', { className: 'iv-card__body iv-stocktake-new__body' });
			var chooser = createLocationChooser({
				ctx: ctx,
				locations: locs,
				favouriteIds: (masters && masters.favouriteIds) || [],
				maxVisible: 60,
				labelSrOnly: true,
				onPick: function (loc) {
					if (stocktakeCreating) {
						chooser.setBusy(false);
						toast(tr('Stocktake is already being created…'), true);
						return;
					}
					var locId = Number(loc && loc.id);
					if (!locId) {
						chooser.setBusy(false);
						toast(tr('Choose a location.'), true);
						return;
					}
					clearFieldErrors(bodyHost);
					var campaignName = (name.value || '').trim() || defaultName;
					createAndStartStocktake(ctx, locId, campaignName).catch(function (err) {
						chooser.setBusy(false);
						var inline = applyFieldErrors(
							bodyHost,
							err && err.details,
							err && err.message
						);
						toast(err.message || tr('Could not start stocktake.'), true);
						if (inline) {
							var bad = bodyHost.querySelector('[aria-invalid="true"]');
							if (bad && typeof bad.focus === 'function') {
								bad.focus();
							} else {
								chooser.focus();
							}
						} else {
							chooser.focus();
						}
					});
				},
			});
			formHost.appendChild(el('header', { className: 'iv-card__header' }, [
				el('h2', {
					id: 'iv-stocktake-new-title',
					className: 'iv-card__title',
					text: tr('Where are you counting?'),
				}),
			]));
			// Page lead already says “tap a location” — avoid repeating it in the card.
			bodyHost.appendChild(chooser.root);
			bodyHost.appendChild(disclosureSection(tr('More stocktake options'), [
				field(tr('Name'), name, { name: 'name' }),
			], { open: false, className: 'iv-stocktake-more' }));
			bodyHost.appendChild(el('p', {
				className: 'iv-muted iv-stocktake-snapshot-hint',
				text: tr('We snapshot every active item at that location.'),
			}));
			formHost.appendChild(bodyHost);
			mount.appendChild(stocktakeHowToCard(ctx, {
				variant: 'create',
				id: 'iv-stocktake-howto-create',
				hintKey: 'stocktake_create_howto_v1',
			}));
			mount.appendChild(formHost);
			chooser.focus();
		}

		if (mastersOpt && locsOpt) {
			paint(mastersOpt, locsOpt);
			return;
		}

		setBusy(mount, true);
		clear(mount);
		clear(ctx.actions);
		loadMasters(ctx).then(function (masters) {
			var locs = (masters.locations || []).filter(function (r) { return r.active !== false; });
			if (locs.length === 1) {
				createAndStartStocktake(ctx, Number(locs[0].id), defaultName).catch(function (err) {
					setBusy(mount, false);
					toast(err.message || tr('Could not start stocktake.'), true);
					paint(masters, locs);
				});
				return;
			}
			if (locs.length === 0) {
				setBusy(mount, false);
				clear(mount);
				mount.appendChild(emptyState(
					tr('Add a location first, then start a stocktake.'),
					tr('Add a warehouse or van to hold stock.'),
					(ctx.workspaceRole !== 'viewer' && ctx.canWrite) && ctx.urls && ctx.urls.pages && ctx.urls.pages.locations
						? el('a', {
							href: ctx.urls.pages.locations,
							className: 'button primary',
							text: tr('Go to locations'),
						})
						: null
				));
				return;
			}
			paint(masters, locs);
		}).catch(function (err) {
			setBusy(mount, false);
			clear(mount);
			mount.appendChild(loadErrorPanel(err.message, function () { renderStocktakeNewPage(ctx); }));
			toast(err.message || tr('Could not load locations.'), true);
		});
	}

	function renderStocktakeList(ctx) {
		var mount = ctx.mount;
		setBusy(mount, true);
		clear(mount);
		clear(ctx.actions);
		if (ctx.workspaceRole !== 'viewer' && ctx.canWrite) {
			ctx.actions.appendChild(btn(tr('New stocktake'), {
							'data-iv-action': 'stocktake-new',
				primary: true,
				onclick: function () { openNewCampaignDialog(ctx); },
			}));
		}
		Promise.all([
			api('GET', ctx.urls.api.cycleCounts + '?limit=50&offset=0'),
			api('GET', ctx.urls.api.locations + '?limit=200&offset=0'),
		]).then(function (results) {
			var res = results[0];
			var locMap = indexById(results[1].data);
			clear(mount);
			setBusy(mount, false);
			var canStart = ctx.workspaceRole !== 'viewer' && ctx.canWrite;
			mount.appendChild(stocktakeHowToCard(ctx, {
				variant: canStart ? 'list' : 'count',
				id: canStart ? 'iv-stocktake-howto-list' : 'iv-stocktake-howto-count',
				hintKey: canStart ? 'stocktake_list_howto_v1' : 'stocktake_count_howto_v1',
			}));
			if (!res.data.length) {
				mount.appendChild(emptyState(
					tr('No stocktakes yet'),
					tr('Start a cycle count to compare system stock with what is actually on the shelf.'),
					canStart
						? btn(tr('New stocktake'), {
							'data-iv-action': 'stocktake-new', primary: true, onclick: function () { openNewCampaignDialog(ctx); } })
						: null
				));
				return;
			}
			mount.appendChild(tableOrCards([
				{ label: tr('Name'), render: function (r) {
					return el('a', { href: stocktakeDetailUrl(ctx, r.id), text: r.name });
				} },
				{ label: tr('Location'), render: function (r) {
					return labelFromMap(locMap, r.locationId, 'code');
				} },
				{ label: tr('Status'), render: function (r) {
					var m = cycleStatusMeta(r.status);
					return el('span', { className: 'iv-badge ' + m.badge, text: m.label });
				} },
			], res.data));
		}).catch(function (err) {
			setBusy(mount, false);
			clear(mount);
			mount.appendChild(loadErrorPanel(err.message, function () { renderStocktakeList(ctx); }));
			toast(err.message, true);
		});
	}

	// Module-level: prevent duplicate auto-start POSTs while a detail re-render is in flight.
	var stocktakeAutoStarting = {};
	var stocktakeAutoStartFailed = {};

	function renderStocktakeDetail(ctx, pageOffset) {
		var mount = ctx.mount;
		var id = Number(ctx.entityId);
		var offset = Math.max(0, Number(pageOffset) || 0);
		var pageLimit = 200;
		setBusy(mount, true);
		clear(mount);
		Promise.all([
			api('GET', entityUrl(ctx.urls.api.cycleCounts, id) + '?limit=' + pageLimit + '&offset=' + offset),
			api('GET', ctx.urls.api.locations + '?limit=200&offset=0'),
			api('GET', ctx.urls.api.items + '?limit=200&offset=0'),
		]).then(function (results) {
			var campaign = results[0];
			var locMap = indexById(results[1].data);
			var itemMap = indexById(results[2].data);
			clear(mount);
			setBusy(mount, false);
			clear(ctx.actions);
			var canOperate = ctx.workspaceRole !== 'viewer' && ctx.canWrite;

			function startCountingManual() {
				api('POST', urlWithId(ctx.urls.api.cycleCountStart, id)).then(function () {
					delete stocktakeAutoStartFailed[id];
					toast(tr('Counting started.'));
					renderStocktakeDetail(ctx, offset);
				}).catch(function (startErr) { toast(startErr.message, true); });
			}

			// Bachus: open campaigns auto-start — list/create never need a second "Start counting" click.
			if (canOperate && campaign.status === 'open') {
				if (stocktakeAutoStartFailed[id]) {
					ctx.actions.appendChild(btn(tr('Start counting'), {
						primary: true,
						onclick: startCountingManual,
					}));
				} else if (!stocktakeAutoStarting[id]) {
					stocktakeAutoStarting[id] = true;
					api('POST', urlWithId(ctx.urls.api.cycleCountStart, id)).then(function () {
						delete stocktakeAutoStarting[id];
						delete stocktakeAutoStartFailed[id];
						toast(tr('Counting started.'));
						renderStocktakeDetail(ctx, offset);
					}).catch(function (err) {
						delete stocktakeAutoStarting[id];
						stocktakeAutoStartFailed[id] = true;
						toast(err.message, true);
						renderStocktakeDetail(ctx, offset);
					});
				}
				if (!stocktakeAutoStartFailed[id]) {
					mount.appendChild(el('p', {
						className: 'iv-muted',
						role: 'status',
						text: tr('Starting count…'),
					}));
				}
			}
			if (canOperate && campaign.status === 'counting') {
				delete stocktakeAutoStartFailed[id];
				ctx.actions.appendChild(btn(tr('Close stocktake'), {
					primary: true,
					className: 'iv-btn--touch',
					onclick: function () { closeStocktake(ctx, campaign); },
				}));
			}

			var meta = cycleStatusMeta(campaign.status);
			mount.appendChild(el('section', { className: 'iv-section' }, [
				el('h2', { className: 'iv-section__title', text: campaign.name }),
				el('p', { className: 'iv-muted' }, [
					labelFromMap(locMap, campaign.locationId, 'code') + ' · ',
					el('span', { className: 'iv-badge ' + meta.badge, text: meta.label }),
				]),
			]));

			if (campaign.status === 'counting' || campaign.status === 'open') {
				var detailHowTo = stocktakeHowToCard(ctx, {
					variant: 'detail',
					id: 'iv-stocktake-howto-detail',
					hintKey: 'stocktake_detail_howto_v1',
				});
				if (detailHowTo) {
					mount.appendChild(detailHowTo);
				}
			}

			if (campaign.status === 'open') {
				return;
			}

			var linesTotal = Number(campaign.linesTotal != null ? campaign.linesTotal : (campaign.lines || []).length);
			if (!campaign.lines || !campaign.lines.length) {
				mount.appendChild(emptyState(
					tr('No active items to count.'),
					tr('Add active items first, then start a new stocktake for this location.'),
					btn(tr('Go to items'), {
						primary: true,
						className: 'iv-btn--touch',
						onclick: function () {
							window.location.href = ctx.urls.pages.items;
						},
					})
				));
				return;
			}

			var countedSoFar = Number(campaign.linesCounted != null ? campaign.linesCounted : campaign.lines.filter(function (l) { return l.qtyCounted != null; }).length);
			var conflictCount = Number(campaign.conflictCount != null ? campaign.conflictCount : campaign.lines.filter(function (l) { return l.conflict; }).length);
			var progressLabel = tr('Counted {done} of {total}.', { done: String(countedSoFar), total: String(linesTotal) });
			var progress = el('p', {
				className: 'iv-muted',
				role: 'progressbar',
				'aria-label': tr('Count progress'),
				'aria-valuemin': '0',
				'aria-valuemax': String(linesTotal),
				'aria-valuenow': String(countedSoFar),
				'aria-valuetext': progressLabel,
				'aria-live': 'polite',
				id: 'iv-stocktake-progress',
				text: progressLabel,
			});
			mount.appendChild(progress);
			if (conflictCount > 0) {
				mount.appendChild(el('p', {
					className: 'iv-section__hint',
					role: 'status',
					text: tr('{count} line(s) changed after this stocktake started. Live quantity differs from the snapshot — resolve before closing, or confirm you accept the counted quantities.', { count: String(conflictCount) }),
				}));
			}

			// Bachus: blind mode is expert chrome — keep the count table as the hero.
			var blindToggle = el('input', {
				type: 'checkbox',
				className: 'iv-switch__input',
				id: 'iv-stocktake-blind',
			});
			var tableHost = el('div', { className: 'iv-stocktake-table-host' });
			mount.appendChild(disclosureSection(tr('Counting options'), [
				el('label', { className: 'iv-switch', for: 'iv-stocktake-blind' }, [
					blindToggle,
					el('span', { className: 'iv-switch__label', text: tr('Blind count — hide system quantities') }),
				]),
			], { open: false, className: 'iv-stocktake-options' }));
			mount.appendChild(tableHost);

			if (linesTotal > pageLimit) {
				var from = offset + 1;
				var to = Math.min(offset + campaign.lines.length, linesTotal);
				var pager = el('div', {
					className: 'iv-stocktake-pager',
					role: 'navigation',
					'aria-label': tr('Stocktake pages'),
				});
				pager.appendChild(el('span', {
					className: 'iv-muted',
					text: tr('Showing {from}–{to} of {total}', { from: String(from), to: String(to), total: String(linesTotal) }),
				}));
				if (offset > 0) {
					pager.appendChild(btn(tr('Previous'), {
						onclick: function () { renderStocktakeDetail(ctx, Math.max(0, offset - pageLimit)); },
					}));
				}
				if (offset + campaign.lines.length < linesTotal) {
					pager.appendChild(btn(tr('Next'), {
						primary: true,
						onclick: function () { renderStocktakeDetail(ctx, offset + pageLimit); },
					}));
				}
				mount.appendChild(pager);
			}

			function updateStocktakeProgress() {
				var done = String(campaign.linesCounted != null ? campaign.linesCounted : campaign.lines.filter(function (l) { return l.qtyCounted != null; }).length);
				var label = tr('Counted {done} of {total}.', {
					done: done,
					total: String(linesTotal),
				});
				progress.textContent = label;
				progress.setAttribute('aria-valuenow', done);
				progress.setAttribute('aria-valuetext', label);
			}

			function countRemainingAsSystem() {
				if (Number(campaign._ivPendingSaves || 0) > 0) {
					toast(tr('Still saving counts… Try again in a moment.'), true);
					return;
				}
				var pending = (campaign.lines || []).filter(function (l) { return l.qtyCounted == null; });
				if (!pending.length) {
					toast(tr('Every line on this page is already counted.'));
					return;
				}
				campaign._ivPendingSaves = Number(campaign._ivPendingSaves || 0) + 1;
				Promise.all(pending.map(function (r) {
					var v = formatQty(r.systemQty != null ? r.systemQty : 0);
					return api('PUT', urlWithId(ctx.urls.api.cycleCountSetCount, r.id), { qtyCounted: v }).then(function () {
						r.qtyCounted = v;
						campaign.linesCounted = Number(campaign.linesCounted != null ? campaign.linesCounted : 0) + 1;
						if (campaign.linesUncounted != null) {
							campaign.linesUncounted = Math.max(0, Number(campaign.linesUncounted) - 1);
						}
					});
				})).then(function () {
					updateStocktakeProgress();
					renderCountTable();
					toast(tr('Counted {count} line(s) as system quantity.', { count: String(pending.length) }));
				}).catch(function (err) {
					toast(err.message, true);
					renderCountTable();
				}).then(function () {
					campaign._ivPendingSaves = Math.max(0, Number(campaign._ivPendingSaves || 0) - 1);
				});
			}

			if (canOperate && campaign.status === 'counting') {
				var asSystemBtn = btn(tr('Count this page as system'), {
					className: 'iv-btn--touch',
					onclick: function () { countRemainingAsSystem(); },
				});
				asSystemBtn.setAttribute('data-iv-count-as-system', '1');
				// Prefer count-as-system before Close in the action bar.
				if (ctx.actions.firstChild) {
					ctx.actions.insertBefore(asSystemBtn, ctx.actions.firstChild);
				} else {
					ctx.actions.appendChild(asSystemBtn);
				}
			}

			function renderCountTable() {
				var blind = !!blindToggle.checked;
				var columns = [
					{ label: tr('Item'), render: function (r) {
						if (r.itemName || r.sku) {
							return (r.itemName || ('#' + r.itemId)) + (r.sku ? ' (' + r.sku + ')' : '');
						}
						return labelFromMap(itemMap, r.itemId, 'sku');
					} },
				];
				if (!blind) {
					columns.push({ label: tr('System qty'), key: 'systemQty' });
					columns.push({ label: tr('Live qty'), render: function (r) {
						return document.createTextNode(formatQty(r.currentQty != null ? r.currentQty : r.systemQty));
					} });
				}
				if (conflictCount > 0) {
					columns.push({ label: tr('Status'), render: function (r) {
						if (!r.conflict) {
							return el('span', { className: 'iv-sr-only', text: tr('OK') });
						}
						return el('span', {
							className: 'iv-badge iv-badge--warning',
							text: tr('Changed since snapshot'),
						});
					} });
				}
				columns.push({ label: tr('Counted qty'), render: function (r) {
					if (campaign.status !== 'counting' || !canOperate) {
						return r.qtyCounted == null
							? el('span', { className: 'iv-muted', text: tr('Not counted') })
							: document.createTextNode(formatQty(r.qtyCounted));
					}
					var input = el('input', {
						type: 'number',
						min: '0',
						max: '1000000',
						step: qtyStep(ctx),
						className: 'iv-input iv-stocktake-count-input',
						style: 'max-width:8rem;min-height:44px',
						value: r.qtyCounted != null ? String(r.qtyCounted) : '',
						'aria-label': tr('Counted quantity'),
						'data-iv-autosave': '1',
					});
					var status = el('span', {
						className: 'iv-muted iv-stocktake-save-status',
						'aria-live': 'polite',
						text: '',
					});
					function autosave() {
						var v = input.value.trim();
						if (v === '') {
							return;
						}
						if (r.qtyCounted != null && String(r.qtyCounted) === v) {
							return;
						}
						status.textContent = tr('Saving…');
						campaign._ivPendingSaves = Number(campaign._ivPendingSaves || 0) + 1;
						api('PUT', urlWithId(ctx.urls.api.cycleCountSetCount, r.id), { qtyCounted: v }).then(function () {
							var wasUncounted = r.qtyCounted == null;
							r.qtyCounted = v;
							status.textContent = tr('Saved');
							if (wasUncounted) {
								campaign.linesCounted = Number(campaign.linesCounted != null ? campaign.linesCounted : 0) + 1;
								if (campaign.linesUncounted != null) {
									campaign.linesUncounted = Math.max(0, Number(campaign.linesUncounted) - 1);
								}
							}
							updateStocktakeProgress();
						}).catch(function (err) {
							status.textContent = '';
							toast(err.message, true);
						}).then(function () {
							campaign._ivPendingSaves = Math.max(0, Number(campaign._ivPendingSaves || 0) - 1);
						});
					}
					// Bachus: leave the field = save; Enter jumps to the next line.
					input.addEventListener('change', autosave);
					input.addEventListener('blur', autosave);
					input.addEventListener('keydown', function (ev) {
						if (ev.key !== 'Enter') {
							return;
						}
						ev.preventDefault();
						autosave();
						var inputs = Array.prototype.slice.call(
							tableHost.querySelectorAll('.iv-stocktake-count-input')
						);
						var idx = inputs.indexOf(input);
						if (idx >= 0 && idx < inputs.length - 1) {
							inputs[idx + 1].focus();
							if (typeof inputs[idx + 1].select === 'function') {
								inputs[idx + 1].select();
							}
						}
					});
					return el('div', { className: 'iv-row__actions' }, [input, status]);
				} });
				clear(tableHost);
				tableHost.appendChild(tableOrCards(columns, campaign.lines, {
					caption: blind ? tr('Blind stocktake counts') : tr('Stocktake counts'),
				}));
			}
			blindToggle.addEventListener('change', renderCountTable);
			renderCountTable();
		}).catch(function (err) {
			setBusy(mount, false);
			clear(mount);
			mount.appendChild(loadErrorPanel(err.message, function () { renderStocktakeDetail(ctx, offset); }));
			toast(err.message, true);
		});
	}

	function stocktakeCloseQuery(abandonUncounted, acknowledgeConflicts) {
		return '?abandonUncounted=' + (abandonUncounted ? '1' : '0')
			+ '&acknowledgeConflicts=' + (acknowledgeConflicts ? '1' : '0');
	}

	function postStocktakeClose(ctx, campaignId, abandonUncounted, acknowledgeConflicts) {
		return api('POST', urlWithId(ctx.urls.api.cycleCountClose, campaignId)
			+ stocktakeCloseQuery(abandonUncounted, acknowledgeConflicts), {}).then(function () {
			toast(tr('Stocktake closed.'));
			renderStocktakeDetail(ctx);
		});
	}

	/**
	 * Campaign-wide uncounted/conflicts — never infer from the current page of lines
	 * (paginated inventur would under-count and over-abandon).
	 * linesUncounted is kept fresh by autosave when present.
	 */
	function stocktakeUncounted(campaign) {
		if (campaign.linesUncounted != null) {
			return Math.max(0, Number(campaign.linesUncounted));
		}
		var linesTotal = Number(campaign.linesTotal != null ? campaign.linesTotal : 0);
		var linesCounted = Number(campaign.linesCounted != null ? campaign.linesCounted : 0);
		if (linesTotal > 0) {
			return Math.max(0, linesTotal - linesCounted);
		}
		return (campaign.lines || []).filter(function (l) { return l.qtyCounted == null; }).length;
	}

	function stocktakeConflicts(campaign) {
		if (campaign.conflictCount != null) {
			return Math.max(0, Number(campaign.conflictCount));
		}
		return (campaign.lines || []).filter(function (l) { return l.conflict; }).length;
	}

	/** Bachus: clean campaigns close in one click; dirty ones keep the confirm dialog. */
	function closeStocktake(ctx, campaign) {
		if (Number(campaign._ivPendingSaves || 0) > 0) {
			toast(tr('Still saving counts… Try again in a moment.'), true);
			return;
		}
		var uncounted = stocktakeUncounted(campaign);
		var conflicts = stocktakeConflicts(campaign);
		if (uncounted === 0 && conflicts === 0) {
			return postStocktakeClose(ctx, campaign.id, false, false).catch(function (err) {
				toast(err.message, true);
			});
		}
		openCloseCampaignDialog(ctx, campaign, uncounted, conflicts);
	}

	function openCloseCampaignDialog(ctx, campaign, uncountedOpt, conflictsOpt) {
		var uncounted = uncountedOpt != null ? Number(uncountedOpt) : stocktakeUncounted(campaign);
		var conflicts = conflictsOpt != null ? Number(conflictsOpt) : stocktakeConflicts(campaign);
		var body = [
			el('p', { text: tr('Closing posts one stock adjustment per counted line so on-hand quantities match what was counted.') }),
		];
		var confirmLabel = tr('Close stocktake');
		if (conflicts > 0) {
			body.push(el('p', {
				className: 'iv-section__hint',
				role: 'status',
				text: tr('{count} line(s) changed after this stocktake started. Closing with the counted quantities will overwrite those later movements.', { count: String(conflicts) }),
			}));
			body.push(el('p', {
				className: 'iv-muted',
				text: tr('Confirming means you reviewed the conflicts and accept the counted quantities.'),
			}));
			confirmLabel = tr('Accept conflicts and close');
		}
		if (uncounted > 0) {
			body.push(el('p', {
				className: 'iv-muted',
				text: tr('{count} item(s) were never counted. Confirming leaves those items unchanged.', { count: String(uncounted) }),
			}));
			confirmLabel = conflicts > 0
				? tr('Close anyway')
				: tr('Close and leave uncounted');
		}
		// Bachus: confirm button IS consent — no checkbox trap before Close.
		dialog(tr('Close stocktake'), body, function () {
			return postStocktakeClose(ctx, campaign.id, uncounted > 0, conflicts > 0);
		}, confirmLabel);
	}

	function renderStocktake(ctx) {
		if (ctx.entityId) {
			renderStocktakeDetail(ctx);
		} else {
			renderStocktakeList(ctx);
		}
	}

	function workspaceRoleLabel(role) {
		return ({owner: tr('Owner'), admin: tr('Admin'), editor: tr('Write'), viewer: tr('Read')})[role] || role;
	}

	// Only recipient-bound signed links may trigger an immediate CSRF-protected POST.
	function workspaceInvitationIntent(rows, search) {
		var params = new URLSearchParams(search);
		var decision = params.get('decision');
		var token = params.get('invitationToken');
		if (['view', 'accept', 'decline'].indexOf(decision) === -1 || !token) return null;
		var match = rows.find(function (row) {
			return row.status === 'pending' && String(row.id) === params.get('invitationWorkspace')
				&& row.invitationId != null && String(row.invitationId) === params.get('invitation');
		});
		return match ? { workspace: match, decision: decision, token: token } : null;
	}

	function finishWorkspaceInvitation(ctx) {
		// Refresh Nextcloud's header and the workspace list together after processing an invitation.
		window.location.replace(ctx.urls.pages.workspaces);
	}

	function respondToWorkspaceInvitation(ctx, workspace, accept) {
		return api('POST', entityUrl(ctx.urls.api.workspaces, workspace.id) + '/invitation', {
			accept: accept, invitationId: workspace.invitationId,
		}).then(function () { finishWorkspaceInvitation(ctx); });
	}

	function renderWorkspaces(ctx) {
		var mount = ctx.mount;
		setBusy(mount, true);
		api('GET', ctx.urls.api.workspaces).then(function (response) {
			clear(mount);
			clear(ctx.actions);
			ctx.actions.appendChild(btn(tr('New workspace'), { primary: true, onclick: function () {
				var name = el('input', { className: 'iv-input', required: '', maxlength: '255' });
				dialog(tr('New workspace'), [field(tr('Workspace name'), name)], function () {
					return api('POST', ctx.urls.api.workspaces, { name: name.value }).then(function () { renderWorkspaces(ctx); });
				}, tr('Create workspace'));
			} }));
			var rows = response.data || [];
			if (!rows.length) {
				mount.appendChild(emptyState(tr('No workspaces yet'), tr('Create a workspace to start your inventory.'), null));
			}
			rows.forEach(function (workspace) {
				var base = entityUrl(ctx.urls.api.workspaces, workspace.id);
				var card = el('section', { className: 'iv-card iv-workspace-card', 'data-workspace-id': workspace.id });
				card.appendChild(el('h2', { text: workspace.name }));
				card.appendChild(el('p', { className: 'iv-muted', text: workspaceRoleLabel(workspace.role) }));
				var actions = el('div', { className: 'iv-workspace-actions' });
				card.appendChild(actions);
				if (workspace.status === 'pending') {
					card.appendChild(el('p', { text: tr('You have been invited to this workspace.') }));
					[true, false].forEach(function (accept) {
						actions.appendChild(btn(accept ? tr('Accept invitation') : tr('Decline invitation'), { primary: accept, onclick: function () {
							respondToWorkspaceInvitation(ctx, workspace, accept).catch(function (err) { toast(err.message, true); });
						} }));
					});
				} else {
					actions.appendChild(el('a', { className: 'button primary', href: workspaceResourceUrl({ workspaceId: workspace.id }, ctx.urls.pages.dashboard), text: tr('Open workspace') }));
					if (workspace.role === 'owner' || workspace.role === 'admin') {
						actions.appendChild(el('a', {className: 'button', href: workspaceResourceUrl({workspaceId: workspace.id}, ctx.urls.settingsSections.members), text: tr('Workspace settings')}));
					}
					if (workspace.role === 'owner') {

						actions.appendChild(btn(tr('Rename'), { onclick: function () {
							var name = el('input', { className: 'iv-input', required: '', maxlength: '255', value: workspace.name });
							dialog(tr('Rename workspace'), [field(tr('Workspace name'), name)], function () {
								return api('PUT', base, { name: name.value }).then(function () { renderWorkspaces(ctx); });
							}, tr('Save'));
						} }));
						actions.appendChild(btn(tr('Delete workspace'), { onclick: function () {
							dialog(tr('Delete workspace'), [el('p', { text: tr('Permanently delete this workspace and all its items, locations, stock and history? This cannot be undone.') })], function () {
								return api('DELETE', base).then(function () { renderWorkspaces(ctx); });
							}, tr('Permanently delete'));
						} }));
					} else {
						actions.appendChild(btn(tr('Leave workspace'), { onclick: function () {
							dialog(tr('Leave workspace'), [el('p', { text: tr('You will need a new invitation to join again.') })], function () {
								return api('DELETE', base + '/members', { userId: ctx.userId }).then(function () { renderWorkspaces(ctx); });
							}, tr('Leave workspace'));
						} }));
					}
				}
				mount.appendChild(card);
			});
			setBusy(mount, false);
			var params = new URLSearchParams(window.location.search);
			if (params.has('invitation')) {
				var intent = workspaceInvitationIntent(rows, window.location.search);
				['invitation', 'invitationWorkspace', 'decision', 'invitationToken'].forEach(function (key) { params.delete(key); });
				window.history.replaceState(null, '', window.location.pathname + (params.toString() ? '?' + params.toString() : '') + window.location.hash);
				if (intent) {
					setBusy(mount, true);
					api('POST', entityUrl(ctx.urls.api.workspaces, intent.workspace.id) + '/invitation/notification', {
						invitationId: intent.workspace.invitationId, decision: intent.decision, token: intent.token,
					}).then(function () {
						finishWorkspaceInvitation(ctx);
					}).catch(function (err) {
						setBusy(mount, false);
						toast(err.message, true);
					});
				} else {
					mount.prepend(el('p', { role: 'status', text: tr('This invitation is no longer available for this account. Check that you are signed in with the invited account.') }));
				}
			}
		}).catch(function (err) {
			clear(mount);
			setBusy(mount, false);
			mount.appendChild(loadErrorPanel(err.message, function () { renderWorkspaces(ctx); }));
		});
	}

	function renderWorkspaceMembers(ctx, workspace, card) {
		var base = entityUrl(ctx.urls.api.workspaces, workspace.id);
		api('GET', base + '/members').then(function (response) {
			var previous = card.querySelector('.iv-workspace-members');
			if (previous) previous.remove();
			var members = el('div', { className: 'iv-workspace-members' });
			members.appendChild(el('h3', { text: tr('Members and invitations') }));
			(response.data || []).forEach(function (member) {
				var row = el('div', { className: 'iv-workspace-member' }, [
					el('span', { text: member.displayName + ' · ' + workspaceRoleLabel(member.role) + (member.status === 'pending' ? ' · ' + tr('Invitation pending') : '') }),
				]);
				if (member.role !== 'owner') {
					if (member.status === 'accepted') {
						var role = el('select', {className: 'iv-input', 'aria-label': tr('Role for {name}', {name: member.displayName})}, ['viewer', 'editor', 'admin'].map(function (value) {return el('option', {value: value, text: workspaceRoleLabel(value)});}));
						role.value = member.role;
						role.addEventListener('change', function () {
							role.disabled = true;
							api('PUT', base + '/members', {userId: member.userId, role: role.value}).then(function () {window.location.reload();}).catch(function (err) {role.value = member.role; role.disabled = false; toast(err.message, true);});
						});
						row.appendChild(role);
					}

					row.appendChild(btn(member.status === 'pending' ? tr('Cancel invitation') : tr('Remove member'), { onclick: function () {
						return api('DELETE', base + '/members', { userId: member.userId }).then(function () { renderWorkspaceMembers(ctx, workspace, card); }).catch(function (err) { toast(err.message, true); });
					} }));
				}
				members.appendChild(row);
			});
			members.appendChild(btn(tr('Invite user'), { onclick: function () {
				var picker = createIdPicker({ multi: false, searchUrls: { user: base + '/users' } });
				var role = el('select', { className: 'iv-input' }, [el('option', { value: 'editor', text: tr('Write') }), el('option', { value: 'viewer', text: tr('Read') }), el('option', {value: 'admin', text: tr('Admin')})]);
				dialog(tr('Invite user'), [pickerField(tr('User'), picker), field(tr('Role'), role),
					el('p', { className: 'iv-muted', text: tr('Read members can browse. Write members can edit inventory. Admins also manage workspace settings and invitations.') }),
					el('p', { className: 'iv-muted', text: tr('Invitations appear in Nextcloud notifications. Email delivery follows the recipient’s notification settings.') })], function () {
					return api('POST', base + '/members', { userId: picker.getId(), role: role.value }).then(function () { renderWorkspaceMembers(ctx, workspace, card); });
				}, tr('Send invitation'));
			} }));
			card.appendChild(members);
		}).catch(function (err) { toast(err.message, true); });
	}

	function boot() {
		var ctx = readShell();
		if (!ctx || !ctx.mount) return;
		var appSettings = document.querySelector('[data-iv-app-settings]');
		if (appSettings) appSettings.addEventListener('click', function () {openAppSettings(ctx);});
		switch (ctx.page) {
			case 'workspaces': renderWorkspaces(ctx); break;
			case 'dashboard': renderDashboard(ctx); break;
			case 'items': renderItems(ctx); break;
			case 'item-detail': renderItemDetail(ctx); break;
			case 'locations': renderLocations(ctx); break;
			case 'location-detail': renderLocationDetail(ctx); break;
			case 'movements': renderMovements(ctx); break;
			case 'stocktake': renderStocktake(ctx); break;
			case 'stocktake-new': renderStocktakeNewPage(ctx); break;
			case 'settings': renderSettings(ctx); break;
			default:
				clear(ctx.mount);
				ctx.mount.appendChild(emptyState(tr('Unknown page'), '', null));
		}
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', boot);
	} else {
		boot();
	}
})();
