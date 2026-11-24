/*
 * Issue #1260: every Copy button on the Connection screen puts the real,
 * working text on the clipboard, whether the password is hidden on screen
 * (the default after Create connection) or shown, and says what it copied.
 *
 * The real src/Admin/Connection/assets/connection.js runs in jsdom against
 * the real screen. tests/free/Admin/Connection/ConnectionCopyTest.php
 * renders that screen with WordPress for each app, writes one JSON fixture
 * per case into the folder WPMCP_JS_FIXTURES names and runs this file with
 * node --test. A fixture holds the page, the answer of the AJAX create
 * action, the inline settings the page loads with, and, for every Copy
 * button, the text it must copy and the sentence it must announce.
 *
 * Setup, once per checkout: npm ci --prefix tests/js
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync, readdirSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import jsdom from 'jsdom';

const { JSDOM } = jsdom;
const here = dirname(fileURLToPath(import.meta.url));
const script = readFileSync(join(here, '../../src/Admin/Connection/assets/connection.js'), 'utf8');
const folder = process.env.WPMCP_JS_FIXTURES || '';

const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

async function until(found, what) {
	for (let i = 0; i < 100; i++) {
		if (found()) {
			return;
		}
		await sleep(10);
	}
	assert.fail('timed out waiting for ' + what);
}

/*
 * The screen as a browser shows it after the script ran: a clipboard that
 * records what is written to it, the execCommand fallback the script uses
 * outside a secure context, and admin-ajax answered from the fixture.
 */
async function open(fixture) {
	const dom = new JSDOM(fixture.page, { url: fixture.url, runScripts: 'outside-only', pretendToBeVisual: true });
	const { window } = dom;
	const { document } = window;
	const screen = { window, document, clipboard: [], secure: true };

	Object.defineProperty(window, 'isSecureContext', { configurable: true, get: () => screen.secure });
	Object.defineProperty(window.navigator, 'clipboard', {
		configurable: true,
		value: {
			writeText(text) {
				screen.clipboard.push(text);
				return Promise.resolve();
			},
		},
	});
	document.execCommand = (command) => {
		const area = document.querySelector('body > textarea[readonly]');
		if ('copy' !== command || !area) {
			return false;
		}
		screen.clipboard.push(area.value.slice(area.selectionStart, area.selectionEnd));
		return true;
	};
	window.HTMLElement.prototype.scrollIntoView = function () {};
	window.fetch = (url, init) => {
		const answer = 'wpmcp_connection_create' === init.body.get('action')
			? fixture.create
			: { success: true, data: { verdict: 'not_seen', done: false, html: '' } };
		return Promise.resolve({ json: () => Promise.resolve(answer) });
	};

	window.eval(fixture.settings);
	window.eval(script);

	if ('create' === fixture.mode) {
		const form = document.querySelector('[data-wpmcp-create]');
		assert.ok(form, 'the page offers Create connection');
		form.dispatchEvent(new window.Event('submit', { bubbles: true, cancelable: true }));
		await until(() => document.getElementById('wpmcp-key'), 'the steps drawn with the new password');
	}
	// Each button's label before anything was copied.
	screen.labels = new Map(Array.from(document.querySelectorAll('.wpmcp-copy'), (el) => [el, el.querySelector('.wpmcp-copy__label').textContent]));

	return screen;
}

function announcer(screen) {
	return screen.document.querySelector('.wpmcp-connection > .screen-reader-text[aria-live="polite"]');
}

/* What one click on the button puts on the clipboard, and what the live region says after it. */
async function copy(screen, button) {
	const before = screen.clipboard.length;
	button.click();
	await sleep(100);
	assert.equal(screen.clipboard.length, before + 1, 'one click copies one text');

	return { text: screen.clipboard[screen.clipboard.length - 1], said: announcer(screen).textContent };
}

/* Makes the button reachable the way a reader does: its variant chosen and its "Other ways" open. */
function reach(screen, button) {
	const panel = button.closest('[data-variant-panel]');
	if (panel) {
		screen.document.querySelector('[data-wpmcp-variants] a[data-variant="' + panel.dataset.variantPanel + '"]').click();
		assert.ok(!panel.classList.contains('is-inactive'), 'its variant is the one shown');
	}
	const details = button.closest('details');
	if (details) {
		details.open = true;
	}
}

/* The block shows the password hidden: masked, kept from screen readers, and labelled "password hidden" for them. */
function assertHidden(block, hidden) {
	assert.equal(block.classList.contains('is-masked'), true, 'the password is hidden on screen');
	for (const secret of block.querySelectorAll('.wpmcp-secret')) {
		assert.equal(secret.getAttribute('aria-hidden'), 'true', 'screen readers skip the hidden password');
		const label = secret.nextElementSibling;
		assert.ok(label && label.classList.contains('wpmcp-secret-label'), 'a label stands in for it');
		assert.equal(label.textContent, hidden, 'screen readers hear that the password is hidden');
	}
	assert.equal(block.querySelector('[data-wpmcp-reveal]').getAttribute('aria-pressed'), 'false');
}

function assertShown(block) {
	assert.equal(block.classList.contains('is-masked'), false, 'the password is shown');
	assert.equal(block.querySelector('.wpmcp-secret-label'), null, 'no hidden label while it shows');
	for (const secret of block.querySelectorAll('.wpmcp-secret')) {
		assert.equal(secret.getAttribute('aria-hidden'), 'false');
	}
	assert.equal(block.querySelector('[data-wpmcp-reveal]').getAttribute('aria-pressed'), 'true');
}

/* A copy event as the browser fires it for Ctrl+C or Cmd+C, with the clipboard it fills. */
function copyByHand(screen, range) {
	const selection = screen.window.getSelection();
	selection.removeAllRanges();
	selection.addRange(range);
	const data = {};
	const event = new screen.window.Event('copy', { bubbles: true, cancelable: true });
	event.clipboardData = { setData: (type, value) => { data[type] = value; } };
	range.startContainer.dispatchEvent(event);
	selection.removeAllRanges();

	return { prevented: event.defaultPrevented, text: data['text/plain'] };
}

const fixtures = '' === folder ? [] : readdirSync(folder).filter((name) => name.endsWith('.json')).sort().map((name) => JSON.parse(readFileSync(join(folder, name), 'utf8')));

test('the fixtures are there', () => {
	assert.notEqual(folder, '', 'run through tests/free/Admin/Connection/ConnectionCopyTest.php, which renders the screen and sets WPMCP_JS_FIXTURES');
	assert.ok(fixtures.length > 0, 'no fixtures in ' + folder);
});

for (const fixture of fixtures) {
	test(fixture.name, async (t) => {
		const screen = await open(fixture);
		const { document, window } = screen;
		const hidden = window.wpmcpConnection.i18n.hidden;
		const button = (target) => document.querySelector('.wpmcp-copy[data-copy-target="' + target + '"]');

		try {
			await t.test('every Copy button on the screen is one this test knows', () => {
				const onScreen = Array.from(document.querySelectorAll('.wpmcp-copy'), (el) => el.dataset.copyTarget).sort();
				assert.deepEqual(onScreen, fixture.copies.map((want) => want.target).sort());
				for (const el of document.querySelectorAll('.wpmcp-copy')) {
					assert.equal(el.hidden, false, el.dataset.copyTarget + ' is shown once the script runs');
				}
			});

			for (const want of fixture.copies) {
				await t.test(want.target + (want.secret ? ' copies the same text with the password hidden or shown' : ' copies its text'), async () => {
					const el = button(want.target);
					reach(screen, el);
					const block = el.closest('[data-wpmcp-code]');

					if (want.secret) {
						assertHidden(block, hidden);
					}
					const first = await copy(screen, el);
					assert.equal(first.text, want.text, want.secret ? 'copied while the password is hidden' : 'copied');
					assert.equal(first.said, want.copied, 'the confirmation says what was copied');

					if (!want.secret) {
						return;
					}
					block.querySelector('[data-wpmcp-reveal]').click();
					assertShown(block);
					const shown = await copy(screen, el);
					assert.equal(shown.text, want.text, 'copied while the password is shown');
					assert.equal(shown.said, want.copied);

					block.querySelector('[data-wpmcp-reveal]').click();
					assertHidden(block, hidden);
					screen.secure = false;
					const fallback = await copy(screen, el);
					screen.secure = true;
					assert.equal(fallback.text, want.text, 'copied through the fallback, outside a secure context');
				});
			}

			await t.test('copying a hidden block by hand leaves out the "password hidden" label', () => {
				for (const want of fixture.copies.filter((c) => c.secret)) {
					const pre = document.getElementById(want.target);
					const all = document.createRange();
					all.selectNodeContents(pre);
					const whole = copyByHand(screen, all);
					assert.equal(whole.prevented, true, want.target);
					assert.equal(whole.text, want.text, want.target + ': the whole block');

					// From the start of the block to just after the label.
					const label = pre.querySelector('.wpmcp-secret-label');
					const part = document.createRange();
					part.setStart(pre, 0);
					part.setEndAfter(label);
					const head = copyByHand(screen, part);
					const secret = label.previousElementSibling.textContent;
					assert.equal(head.text, want.text.slice(0, want.text.indexOf(secret) + secret.length), want.target + ': part of the block');

					// Inside the hidden password itself.
					const inner = document.createRange();
					inner.setStart(label.previousElementSibling.firstChild, 2);
					inner.setEnd(label.previousElementSibling.firstChild, 8);
					assert.equal(copyByHand(screen, inner).text, secret.slice(2, 8), want.target + ': part of the password');
				}

				// Text outside the code blocks is the browser's to copy.
				const heading = document.createRange();
				heading.selectNodeContents(document.querySelector('h1'));
				assert.equal(copyByHand(screen, heading).prevented, false, 'the page heading');
			});

			await t.test('copying by hand while the password shows is left to the browser', () => {
				for (const want of fixture.copies.filter((c) => c.secret)) {
					const pre = document.getElementById(want.target);
					const block = pre.closest('[data-wpmcp-code]');
					block.querySelector('[data-wpmcp-reveal]').click();
					const all = document.createRange();
					all.selectNodeContents(pre);
					const shown = copyByHand(screen, all);
					block.querySelector('[data-wpmcp-reveal]').click();
					assert.equal(shown.prevented, false, want.target);
					assert.equal(shown.text, undefined, want.target);
				}
			});

			await t.test('the password is only in its hidden span, the one-time box and the copy sources', () => {
				const needles = [fixture.token, fixture.password].filter((needle) => '' !== needle);
				for (const el of document.querySelectorAll('*')) {
					for (const attr of el.attributes) {
						if ('data-wpmcp-copy-text' === attr.name) {
							continue;
						}
						for (const needle of needles) {
							assert.ok(!attr.value.includes(needle), el.tagName + ' ' + attr.name + ' carries the password');
						}
					}
				}
				const walk = document.createTreeWalker(document.body, window.NodeFilter.SHOW_TEXT);
				for (let node = walk.nextNode(); node; node = walk.nextNode()) {
					if ('' !== fixture.token && node.data.includes(fixture.token)) {
						assert.ok(node.parentElement.closest('.wpmcp-secret'), 'the token shows outside a hidden span: ' + node.parentElement.outerHTML.slice(0, 80));
					}
					if ('' !== fixture.password && node.data.includes(fixture.password)) {
						assert.equal(node.parentElement.id, 'wpmcp-password', 'the password shows outside the one-time box');
					}
				}
			});

			if (fixture.labels) {
				await t.test('two quick copies leave the button label as it was', async () => {
					const el = button(fixture.copies[0].target);
					const label = el.querySelector('.wpmcp-copy__label');
					const original = screen.labels.get(el);
					await sleep(1900);
					assert.equal(label.textContent, original, 'the earlier copies are over');
					el.click();
					await sleep(200);
					el.click();
					await sleep(100);
					assert.equal(label.textContent, window.wpmcpConnection.i18n.copied);
					await sleep(1900);
					assert.equal(label.textContent, original);
					assert.equal(el.classList.contains('is-copied'), false);
				});
			}
		} finally {
			window.close();
		}
	});
}
