/**
 * Asian Dispatch Embed — height reporter (runs INSIDE the embed iframe).
 *
 * Injected by the endpoint class as the last element of the embed view
 * (?ad_embed=1). Its one job: keep the parent page informed about how
 * tall the document is, so the loader (public/embed.js, running on the
 * partner page) can size the iframe and the article never scrolls inside
 * the frame.
 *
 * MESSAGE CONTRACT (must match the listener in embed.js):
 *     { type: 'ad-embed-height', height: <px number>, post: '<post id>' }
 *
 * The post id comes from the data-ad-embed-post attribute the endpoint
 * stamps onto <body> — that's how a page containing several different
 * embeds knows which iframe each report belongs to.
 *
 * WHY targetOrigin '*' IS OK HERE
 * postMessage's targetOrigin would normally pin the recipient, but we
 * cannot know which partner domain is embedding us, and the payload is
 * just a height — nothing sensitive. The SECURITY check lives on the
 * other side: the loader verifies event.origin is our site before
 * trusting any message.
 */
(function () {
	'use strict';

	// Not inside an iframe (someone opened the ?ad_embed=1 URL directly
	// in a tab) — there is no parent to report to, do nothing.
	if (window.parent === window) {
		return;
	}

	// Last height we reported; used to skip no-op messages so we don't
	// spam the parent on every ResizeObserver tick.
	var lastHeight = 0;

	var post = '';

	/**
	 * The document's full content height. We take the max over both
	 * <body> and <html> metrics because browsers disagree about which one
	 * reflects the true content height (esp. with margins collapsing or
	 * absolutely-positioned tails).
	 */
	function measure() {
		var body = document.body;
		var root = document.documentElement;
		if (!body || !root) {
			return 0;
		}
		return Math.max(body.scrollHeight, body.offsetHeight, root.scrollHeight, root.offsetHeight);
	}

	/** Report the current height to the parent — only when it changed. */
	function send() {
		var height = measure();
		if (!height || height === lastHeight) {
			return;
		}
		lastHeight = height;
		window.parent.postMessage({ type: 'ad-embed-height', height: height, post: post }, '*');
	}

	function start() {
		post = document.body.getAttribute('data-ad-embed-post') || '';

		// Redirect every link click to a new tab. Without this, clicking a
		// link navigates the iframe itself, not the partner page — the reader
		// ends up with our site inside a frame within the partner's layout,
		// which is confusing and usually broken. Capture phase ensures we run
		// before any in-page JS that might call preventDefault.
		document.addEventListener('click', function (e) {
			var a = e.target;
			while (a && a.nodeName !== 'A') { a = a.parentNode; }
			if (!a || !a.href) { return; }
			a.target = '_blank';
			// noopener: prevents the new tab from accessing window.opener
			// (a security best practice whenever target="_blank" is set).
			a.rel = a.rel ? a.rel + ' noopener' : 'noopener';
		}, true);

		// First report immediately — this is what replaces the loader's
		// 300px bootstrap min-height with the real article height.
		send();

		if (window.ResizeObserver) {
			// ResizeObserver fires on ANY size change of the observed
			// elements: images finishing loading, web fonts swapping in,
			// the reader rotating their phone (width change → text
			// reflow → new height), interactive blocks expanding, etc.
			var observer = new ResizeObserver(send);
			observer.observe(document.documentElement);
			observer.observe(document.body);
		} else {
			// Ancient browser fallback: poll. send() de-duplicates, so
			// this only actually posts when the height changed.
			setInterval(send, 750);
		}

		// Two extra safety nets for layout shifts that can slip past the
		// observer setup moment (e.g. cached images sizing late).
		window.addEventListener('load', send);
		window.addEventListener('resize', send);
	}

	// This script is injected at the very end of <body>, so the DOM is
	// normally ready — but guard anyway in case of future reordering.
	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', start);
	} else {
		start();
	}
})();
