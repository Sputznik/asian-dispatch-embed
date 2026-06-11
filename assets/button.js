/**
 * Asian Dispatch Embed — copy-button UI.
 *
 * Runs on OUR OWN site only, and only for logged-in users who can see the
 * button (the PHP side enqueues this file conditionally — see
 * AD_Embed_Button::enqueue()).
 *
 * Drives the markup printed by AD_Embed_Button::render():
 *
 *   .ad-embed-copy              wrapper (one per button placement)
 *     .ad-embed-copy__toggle    "Embed this post" — opens/closes popover
 *     .ad-embed-copy__popover   the panel ([hidden] when closed)
 *       .ad-embed-copy__code    readonly <textarea> holding the snippet
 *       .ad-embed-copy__btn     "Copy" — puts the snippet on the clipboard
 *
 * Implementation notes:
 *   - ONE delegated click listener on document handles every button on
 *     the page (a post has two placements: header + footer), including
 *     the "click anywhere else closes the popover" behavior.
 *   - Clipboard: navigator.clipboard.writeText is the modern API but is
 *     only available in secure contexts and can be denied; we fall back
 *     to the legacy select() + execCommand('copy') approach, and if even
 *     that throws, the text stays selected so the user can press Ctrl+C.
 */
(function () {
	'use strict';

	/** Element.closest with a null-safe guard (very old browsers). */
	function closest(el, selector) {
		return el && el.closest ? el.closest(selector) : null;
	}

	/**
	 * Close every open popover except the given wrapper.
	 * Pass null to close all (Escape key / outside click).
	 */
	function closeAll(except) {
		var open = document.querySelectorAll('.ad-embed-copy.is-open');
		for (var i = 0; i < open.length; i++) {
			if (open[i] === except) {
				continue;
			}
			open[i].classList.remove('is-open');
			var pop = open[i].querySelector('.ad-embed-copy__popover');
			var toggle = open[i].querySelector('.ad-embed-copy__toggle');
			if (pop) {
				pop.hidden = true;
			}
			if (toggle) {
				// Keep the ARIA state truthful for screen readers.
				toggle.setAttribute('aria-expanded', 'false');
			}
		}
	}

	/**
	 * Flash the Copy button into a "Copied ✓" confirmation for 2 seconds.
	 * The original label is cached in a data attribute the first time, so
	 * rapid double-clicks can't make "Copied ✓" the permanent label.
	 */
	function markCopied(button) {
		var original = button.getAttribute('data-label') || button.textContent;
		button.setAttribute('data-label', original);
		button.textContent = 'Copied ✓';
		button.classList.add('is-copied');
		setTimeout(function () {
			button.textContent = original;
			button.classList.remove('is-copied');
		}, 2000);
	}

	/**
	 * Copy the snippet textarea inside `wrap` to the clipboard.
	 */
	function copyFrom(wrap, button) {
		var textarea = wrap.querySelector('.ad-embed-copy__code');
		if (!textarea) {
			return;
		}
		var fallback = function () {
			textarea.focus();
			textarea.select();
			try {
				document.execCommand('copy');
				markCopied(button);
			} catch (err) { /* selection stays — user can copy manually */ }
		};
		if (navigator.clipboard && navigator.clipboard.writeText) {
			// Modern path; second arg = rejection handler (permission
			// denied, insecure context) drops to the legacy path.
			navigator.clipboard.writeText(textarea.value).then(function () {
				markCopied(button);
			}, fallback);
		} else {
			fallback();
		}
	}

	// One delegated listener for all click interactions (see file header).
	document.addEventListener('click', function (event) {
		// Case 1: a toggle button → open its popover (closing siblings).
		var toggle = closest(event.target, '.ad-embed-copy__toggle');
		if (toggle) {
			var wrap = closest(toggle, '.ad-embed-copy');
			var pop = wrap.querySelector('.ad-embed-copy__popover');
			var willOpen = pop.hidden;
			closeAll(wrap);
			pop.hidden = !willOpen;
			wrap.classList.toggle('is-open', willOpen);
			toggle.setAttribute('aria-expanded', willOpen ? 'true' : 'false');
			return;
		}

		// Case 2: the Copy button inside a popover.
		var copyButton = closest(event.target, '.ad-embed-copy__btn');
		if (copyButton) {
			copyFrom(closest(copyButton, '.ad-embed-copy'), copyButton);
			return;
		}

		// Case 3: click anywhere outside a widget → close all popovers.
		// (Clicks INSIDE the popover — e.g. selecting snippet text — fall
		// through neither case above nor this one, and keep it open.)
		if (!closest(event.target, '.ad-embed-copy')) {
			closeAll(null);
		}
	});

	// Escape closes any open popover, standard popover etiquette.
	document.addEventListener('keydown', function (event) {
		if (event.key === 'Escape') {
			closeAll(null);
		}
	});
})();
