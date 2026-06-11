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
 *      replaces it with a shimmer skeleton + an <iframe> of our embed view
 *      (https://asiandispatch.net/?p={id}&ad_embed=1).
 *   2. Once the iframe content has rendered, fades it in and removes the
 *      skeleton — so there is no flash of blank white space.
 *   3. Listens for height reports posted by embed-resize.js from inside
 *      each iframe, and sizes the iframe to its content — so the embed
 *      never shows an inner scrollbar and reflows responsively.
 *   4. Asks our REST route whether this page's domain is allowlisted, and
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
 *   - Shimmer styles are injected once into <head> as a single <style>
 *     tag (id="ad-embed-styles") — re-including the script never doubles
 *     them up.
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

	// Iframe height before the first real measurement arrives. The shimmer
	// skeleton fills this height until the content reports its own height.
	var MIN_HEIGHT = 300;

	// postId → [{ iframe, loader }] registry. Stored on window so when the
	// script is included twice, both copies share the same registry and the
	// single message listener can reach all frames.
	var frames = (window.__adEmbedFrames = window.__adEmbedFrames || {});

	// ── Shimmer styles ───────────────────────────────────────────────────
	// Injected once into <head>. The animation name is prefixed "adEmbed"
	// to avoid colliding with any animation already on the partner page.

	function injectStyles() {
		if (document.getElementById('ad-embed-styles')) {
			return; // already injected (second script copy, or reinit).
		}
		var style = document.createElement('style');
		style.id = 'ad-embed-styles';
		style.textContent =
			'@keyframes adEmbedShimmer{' +
				'0%{background-position:200% 0}' +
				'100%{background-position:-200% 0}' +
			'}' +
			'.ad-embed-skeleton{' +
				'position:absolute;top:0;left:0;right:0;bottom:0;' +
				'background:linear-gradient(' +
					'90deg,' +
					'#f0f0f0 25%,' +
					'#e4e4e4 50%,' +
					'#f0f0f0 75%' +
				');' +
				'background-size:400% 100%;' +
				'animation:adEmbedShimmer 1.6s ease-in-out infinite;' +
				'border-radius:2px;' +
				'pointer-events:none;' +     /* skeleton never steals clicks */
			'}';
		(document.head || document.documentElement).appendChild(style);
	}

	/**
	 * Turn one placeholder <div class="ad-embed"> into a shimmer skeleton
	 * with the iframe loading behind it. The skeleton fades out on first
	 * height report from the iframe content.
	 */
	function buildFrame(node) {
		var id = node.getAttribute('data-post');

		// data-ad-embed-ready guards idempotency: a second script copy (or
		// a second init pass) skips placeholders that are already built.
		if (!id || node.getAttribute('data-ad-embed-ready')) {
			return;
		}
		node.setAttribute('data-ad-embed-ready', '1');

		// The wrapper needs position:relative so the absolutely-positioned
		// skeleton can sit on top of the iframe area.
		node.style.cssText += ';position:relative;min-height:' + MIN_HEIGHT + 'px;';

		// ── iframe ───────────────────────────────────────────────────────
		// Starts invisible (opacity:0). Fades in on first height message,
		// which signals that the content has rendered inside the frame.
		var iframe = document.createElement('iframe');
		iframe.src    = origin + '/?p=' + encodeURIComponent(id) + '&ad_embed=1';
		iframe.title  = node.getAttribute('data-title') || 'Embedded article from Asian Dispatch';
		iframe.loading = 'lazy';
		iframe.setAttribute('scrolling', 'no');
		iframe.style.cssText =
			'width:100%;border:0;display:block;overflow:hidden;' +
			'min-height:' + MIN_HEIGHT + 'px;' +
			'opacity:0;' +
			'transition:opacity 0.4s ease;';

		// ── skeleton overlay ─────────────────────────────────────────────
		// Absolutely covers the iframe area while it loads. Removed after
		// the iframe fade-in transition completes.
		var skeleton = document.createElement('div');
		skeleton.className = 'ad-embed-skeleton';

		node.innerHTML = '';
		node.appendChild(iframe);    // iframe first (behind the skeleton)
		node.appendChild(skeleton);  // skeleton on top

		// Register so the message listener can find this iframe by post id.
		(frames[String(id)] = frames[String(id)] || []).push({
			iframe:   iframe,
			skeleton: skeleton,
			revealed: false   // tracks whether we have already faded it in
		});
	}

	/** Build every placeholder currently in the DOM. */
	function initAll() {
		injectStyles();
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

	// ── Height sync + reveal ─────────────────────────────────────────────
	// embed-resize.js (inside each iframe) posts:
	//     { type: 'ad-embed-height', height: <px>, post: '<id>' }
	//
	// The FIRST message also triggers the reveal: the iframe fades in and
	// the skeleton is removed. Subsequent messages just update the height.
	//
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
				var entry = list[i];

				// Resize the iframe to match the reported content height.
				// Math.ceil avoids sub-pixel gaps that some browsers render
				// as a 1px slice of the page behind the iframe.
				entry.iframe.style.height    = Math.ceil(data.height) + 'px';
				entry.iframe.style.minHeight = '0';

				// First height message = content has rendered. Fade in the
				// iframe and schedule skeleton removal after the transition.
				if (!entry.revealed) {
					entry.revealed = true;
					entry.iframe.style.opacity = '1';
					// Remove the skeleton once the 0.4s fade finishes so it
					// no longer sits in the DOM on top of the live content.
					(function (sk) {
						setTimeout(function () {
							if (sk && sk.parentNode) {
								sk.parentNode.removeChild(sk);
							}
						}, 420); // slightly longer than the 0.4s transition
					}(entry.skeleton));
				}
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
