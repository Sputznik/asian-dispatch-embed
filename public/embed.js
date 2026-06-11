/**
 * Asian Dispatch Embed — loader (runs on PARTNER websites).
 *
 * This is the file partner sites include via the snippet contributors copy:
 *
 *   <div class="ad-embed" data-post="123" data-title="Post title"></div>
 *   <script async src="https://asiandispatch.net/wp-content/plugins/asian-dispatch-embed/public/embed.js"></script>
 *
 * WHAT IT DOES
 *   1. Finds every <div class="ad-embed" data-post="…"> on the page and
 *      replaces it with an <iframe> of our embed view
 *      (https://asiandispatch.net/?p={id}&ad_embed=1).
 *   2. Listens for height reports posted by embed-resize.js from inside
 *      each iframe, and sizes the iframe to its content — so the embed
 *      never shows an inner scrollbar and reflows responsively.
 *   3. Asks our REST route whether this page's domain is allowlisted, and
 *      if not, swaps the embed for a human-readable notice. This is UX
 *      only: the real enforcement is the CSP frame-ancestors header the
 *      embed view sends, which the browser enforces (a blocked iframe
 *      renders as an empty box — hence the friendly message).
 *
 * CONSTRAINTS / STYLE
 *   - Plain ES5 on purpose: we have zero control over partner sites, so
 *     no syntax that an old toolchain or browser could choke on parsing.
 *     (fetch/URL/ResizeObserver are feature-detected, not assumed.)
 *   - No globals except two namespaced ones (window.__adEmbedFrames /
 *     __adEmbedListener) used to coordinate multiple copies of this
 *     script on the same page.
 *   - Must be safe to include twice (partners paste two snippets → two
 *     script tags): initialization is idempotent.
 */
(function () {
	'use strict';

	// document.currentScript = the <script> element currently executing.
	// Its src tells us where "home" is, so the same file works unchanged
	// on staging and production (no hardcoded site URL anywhere).
	var current = document.currentScript;
	if (!current || !current.src) {
		return; // injected in some unsupported way — do nothing.
	}

	// e.g. "https://asiandispatch.net" — used to build iframe URLs and,
	// crucially, to verify the origin of incoming postMessages.
	var origin;
	try {
		origin = new URL(current.src).origin;
	} catch (err) {
		return; // ancient browser without URL() — embeds simply don't load.
	}

	// Iframe height before the first real measurement arrives. Prevents a
	// 0px-tall flash; the first postMessage replaces it within ~100ms.
	var MIN_HEIGHT = 300;

	// postId → [iframe, …] registry. Stored on window (not closure-local)
	// so when the script is included twice, BOTH copies see all frames and
	// either copy's message listener can resize any of them.
	var frames = (window.__adEmbedFrames = window.__adEmbedFrames || {});

	/**
	 * Turn one placeholder <div class="ad-embed"> into an iframe.
	 */
	function buildFrame(node) {
		var id = node.getAttribute('data-post');

		// data-ad-embed-ready guards idempotency: a second script copy (or
		// a second init pass) skips placeholders that are already built.
		if (!id || node.getAttribute('data-ad-embed-ready')) {
			return;
		}
		node.setAttribute('data-ad-embed-ready', '1');

		var iframe = document.createElement('iframe');

		// ?p={id} resolves by post ID — stable even if the slug is edited
		// after the partner pasted the snippet. ad_embed=1 switches our
		// site into the stripped-down embed view.
		iframe.src = origin + '/?p=' + encodeURIComponent(id) + '&ad_embed=1';

		// Accessible name for screen readers on the partner page.
		iframe.title = node.getAttribute('data-title') || 'Embedded article from Asian Dispatch';

		// Don't load the article until the reader scrolls near it.
		iframe.loading = 'lazy';

		// Belt-and-braces against scrollbars: the legacy scrolling="no"
		// attribute plus overflow:hidden, on top of the embed view's own
		// overflow:hidden. Height sync below makes scrolling unnecessary.
		iframe.setAttribute('scrolling', 'no');
		iframe.style.cssText = 'width:100%;border:0;display:block;overflow:hidden;min-height:' + MIN_HEIGHT + 'px;';

		// Replace the placeholder's content with the iframe.
		node.innerHTML = '';
		node.appendChild(iframe);

		// Register for height updates (several embeds of the SAME post on
		// one page share an entry and get resized together).
		(frames[String(id)] = frames[String(id)] || []).push(iframe);
	}

	/** Build every placeholder currently in the DOM. */
	function initAll() {
		var nodes = document.querySelectorAll('div.ad-embed[data-post]');
		for (var i = 0; i < nodes.length; i++) {
			buildFrame(nodes[i]);
		}
	}

	/**
	 * Replace all embeds with a notice explaining WHY nothing renders.
	 * Inline styles because partner-site CSS is unknown territory.
	 */
	function showNotAllowed() {
		var nodes = document.querySelectorAll('div.ad-embed[data-ad-embed-ready]');
		for (var i = 0; i < nodes.length; i++) {
			nodes[i].innerHTML =
				'<p style="padding:1em;border:1px solid #ddd;background:#fafafa;' +
				'font:14px/1.5 sans-serif;color:#444;">' +
				'This website is not authorized to embed Asian Dispatch content. ' +
				'Please contact Asian Dispatch to have your domain allowlisted.</p>';
		}
	}

	/**
	 * Ask our REST route whether this page's hostname is allowlisted.
	 * Failure modes all default to "leave the embeds alone": the CSP
	 * header still enforces the policy, we just lose the friendly text.
	 */
	function checkAllowlist() {
		if (!window.fetch) {
			return; // very old browser — skip the courtesy check.
		}
		fetch(origin + '/wp-json/ad-embed/v1/check?host=' + encodeURIComponent(window.location.hostname))
			.then(function (response) { return response.json(); })
			.then(function (data) {
				// Strict === false: a malformed/unexpected response must
				// not nuke working embeds.
				if (data && data.allowed === false) {
					showNotAllowed();
				}
			})
			.catch(function () { /* network hiccup — leave embeds alone */ });
	}

	// ── Height sync ─────────────────────────────────────────────────────
	// embed-resize.js (inside each iframe) posts:
	//     { type: 'ad-embed-height', height: <px>, post: '<id>' }
	// One listener per page is enough even with multiple script copies,
	// hence the window-level flag.
	if (!window.__adEmbedListener) {
		window.__adEmbedListener = true;
		window.addEventListener('message', function (event) {
			// SECURITY: only accept messages from our own site. Anything
			// else on the page (ads, other widgets) is ignored.
			if (event.origin !== origin) {
				return;
			}
			var data = event.data;
			if (!data || data.type !== 'ad-embed-height' || !data.height) {
				return;
			}
			var list = frames[String(data.post)];
			if (!list) {
				return;
			}
			for (var i = 0; i < list.length; i++) {
				// Math.ceil avoids sub-pixel gaps that some browsers
				// render as a 1px slice of the page behind the iframe.
				list[i].style.height = Math.ceil(data.height) + 'px';
				// Drop the bootstrap min-height: from now on the reported
				// height is authoritative (it may legitimately be smaller).
				list[i].style.minHeight = '0';
			}
		});
	}

	// ── Kick-off ────────────────────────────────────────────────────────
	// The snippet uses <script async>, so we may execute before OR after
	// the document finished parsing — handle both.
	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', function () {
			initAll();
			checkAllowlist();
		});
	} else {
		initAll();
		checkAllowlist();
	}
})();
