<?php
/**
 * The embed view endpoint: any single-post URL + ?ad_embed=1.
 *
 * ─────────────────────────────────────────────────────────────────────────
 * THE CORE IDEA
 * ─────────────────────────────────────────────────────────────────────────
 * We do NOT build a separate "embed template". Instead we let the theme
 * render the post through its NORMAL single-post template (whichever of
 * the variants applies), capture that finished HTML in an output buffer,
 * and rebuild it into a minimal document:
 *
 *     original <head>            ← kept verbatim: every stylesheet, font,
 *                                  inline style and head script arrives
 *                                  exactly as on the real page
 *     original <body> tag        ← classes kept (template-variant CSS
 *                                  hooks live here), ours appended
 *     only the <main> element    ← the post itself; site header, nav,
 *                                  sidebars and site footer are dropped
 *     body styles/scripts that   ← CRITICAL: SiteOrigin prints the post's
 *     lived OUTSIDE <main>         grid/layout CSS and ALL functional JS
 *                                  in the footer (see ASSET-ASSESSMENT.md)
 *
 * Because nothing is re-implemented, the embed is pixel-identical to the
 * live post for every template variant — current and future — as long as
 * the theme keeps using a single <main> element.
 *
 * WHY REGEX AND NOT DOMDocument?
 * PHP's DOMDocument predates HTML5 and mangles real-world WordPress
 * output (unknown tags, embedded SVG/JSON-LD, template strings in
 * scripts). Position-based extraction over the raw string is boring but
 * predictable — and if ANY landmark is missing we return the page
 * untouched, so the worst case is an iframe showing the full page
 * (degraded, never broken).
 *
 * SECURITY MODEL
 * The view itself is public (the posts are public); what the allowlist
 * controls is FRAMING. We send a Content-Security-Policy frame-ancestors
 * header built from the admin's allowlist — the partner's browser
 * enforces it, so editing the loader JS bypasses nothing.
 *
 * @package asian-dispatch-embed
 */

defined( 'ABSPATH' ) || exit;

class AD_Embed_Endpoint {

	/**
	 * The URL flag that switches a post URL into the embed view.
	 * Deliberately NOT WordPress's native /embed/ (oEmbed) endpoint —
	 * that one renders a stub card, and we must not conflict with it.
	 */
	const QUERY_VAR = 'ad_embed';

	/**
	 * ID of the post being rendered, captured at routing time so the
	 * buffer callback can stamp it onto the <body> tag (the resize script
	 * reads it back and includes it in its postMessage payloads, which is
	 * how the loader knows WHICH iframe to resize).
	 *
	 * @var int
	 */
	private $post_id = 0;

	/**
	 * All hook registration happens here; constructing the class is what
	 * "turns on" the endpoint.
	 */
	public function __construct() {
		add_filter( 'query_vars', array( $this, 'register_query_var' ) );
		add_filter( 'redirect_canonical', array( $this, 'maybe_skip_canonical' ) );
		// Priority 1: run before other template_redirect handlers so our
		// output buffer wraps everything the template prints.
		add_action( 'template_redirect', array( $this, 'maybe_render_embed' ), 1 );
		add_action( 'rest_api_init', array( $this, 'register_rest_route' ) );
	}

	/**
	 * Tell WordPress that ?ad_embed=… is a legitimate public query var.
	 * Without this, get_query_var( 'ad_embed' ) would always be empty.
	 *
	 * @param string[] $vars Registered public query vars.
	 * @return string[]
	 */
	public function register_query_var( $vars ) {
		$vars[] = self::QUERY_VAR;
		return $vars;
	}

	/**
	 * Is the current request an embed view?
	 *
	 * Static so other components can ask too — e.g. the button class uses
	 * it to make sure the copy button never renders INSIDE an embed.
	 *
	 * @return bool
	 */
	public static function is_embed_request() {
		return (bool) get_query_var( self::QUERY_VAR );
	}

	/**
	 * Disable WordPress's canonical redirect for embed requests.
	 *
	 * The loader uses the ?p={id}&ad_embed=1 URL form (IDs are stable;
	 * slugs can be edited). Normally WP would 301 that to the pretty
	 * permalink — harmless when it preserves our query arg, but we skip
	 * the redirect entirely so the iframe never depends on redirect
	 * behavior (or pays the extra round trip).
	 *
	 * @param string|false $redirect_url Canonical URL WP wants to redirect to.
	 * @return string|false False = don't redirect.
	 */
	public function maybe_skip_canonical( $redirect_url ) {
		// $_GET (not get_query_var) because this filter can fire before
		// query vars are fully populated.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only routing decision.
		if ( isset( $_GET[ self::QUERY_VAR ] ) ) {
			return false;
		}
		return $redirect_url;
	}

	/**
	 * Gate the embed view, send security headers, install the buffer.
	 *
	 * Runs on every frontend request (template_redirect) but returns
	 * immediately unless ?ad_embed=1 is present.
	 */
	public function maybe_render_embed() {
		if ( ! self::is_embed_request() ) {
			return;
		}

		// Only published, public, non-password posts may be embedded.
		// Anything else (pages, drafts, archives, ?ad_embed on the
		// homepage…) gets a plain 404 so nothing leaks.
		$embeddable = is_singular( AD_Embed_Plugin::post_types() )
			&& 'publish' === get_post_status()
			&& ! post_password_required();

		if ( ! $embeddable ) {
			status_header( 404 );
			nocache_headers();
			header( 'Content-Type: text/html; charset=utf-8' );
			echo '<!DOCTYPE html><html><head><meta name="robots" content="noindex"><title>Not available</title></head>'
				. '<body><p>This content is not available for embedding.</p></body></html>';
			exit;
		}

		$this->post_id = get_queried_object_id();

		// An editor logged in on our site previewing an embed should not
		// get the admin bar stuffed into the iframe.
		add_filter( 'show_admin_bar', '__return_false' );

		// THE enforcement: browsers refuse to frame this response on any
		// origin not listed. Second header() arg false = APPEND, not
		// replace — if a security plugin already sends a site-wide CSP,
		// both policies apply (browsers enforce the intersection), so we
		// can't accidentally weaken an existing policy.
		header( 'Content-Security-Policy: ' . AD_Embed_Domains::csp_header_value(), false );

		// Belt-and-braces with the robots <meta> injected later: keep the
		// embed variant of the URL out of search indexes.
		header( 'X-Robots-Tag: noindex' );

		// From this point WordPress renders the normal template; the whole
		// output lands in this buffer and transform() rewrites it once the
		// page is complete.
		ob_start( array( $this, 'transform' ) );
	}

	/**
	 * Rebuild the fully rendered page into the embed document.
	 *
	 * Receives the COMPLETE page HTML (theme header, post, footer — all of
	 * it) and returns the reduced document described in the file header.
	 *
	 * Every step that locates a landmark bails out by returning the input
	 * unchanged if the landmark is missing — fail open to "full page in
	 * iframe" rather than fail closed to a broken embed.
	 *
	 * @param string $html Fully rendered page HTML from the output buffer.
	 * @return string Rebuilt embed document (or $html untouched on surprise).
	 */
	public function transform( $html ) {
		if ( ! is_string( $html ) || '' === $html ) {
			return $html;
		}

		// ── 1. Locate <main> … </main> ─────────────────────────────────
		// First opening tag to LAST closing tag, so nested <main> (never
		// valid HTML, but themes happen) cannot truncate the content.
		if ( ! preg_match( '/<main\b[^>]*>/i', $html, $m, PREG_OFFSET_CAPTURE ) ) {
			return $html;
		}
		$main_start = $m[0][1];
		$main_end   = strripos( $html, '</main>' );
		if ( false === $main_end || $main_end <= $main_start ) {
			return $html;
		}
		$main_end  += strlen( '</main>' );
		$main_html  = substr( $html, $main_start, $main_end - $main_start );

		// ── 2. Capture the original <head> verbatim ────────────────────
		// This single step is what makes the embed pixel-identical: all 10
		// stylesheets, the 15 inline style blocks, Google-Fonts @imports
		// and head scripts (jQuery, GA) ride along unmodified.
		if ( ! preg_match( '/<head\b[^>]*>/i', $html, $m, PREG_OFFSET_CAPTURE ) ) {
			return $html;
		}
		$head_open      = $m[0][0];
		$head_inner_pos = $m[0][1] + strlen( $head_open );
		$head_close     = stripos( $html, '</head>', $head_inner_pos );
		if ( false === $head_close ) {
			return $html;
		}
		$head_inner = substr( $html, $head_inner_pos, $head_close - $head_inner_pos );

		// ── 3. Preserve doctype and the <html> tag ─────────────────────
		// The <html> tag carries lang="…" (accessibility, hyphenation).
		$doctype  = preg_match( '/<!doctype[^>]*>/i', $html, $m ) ? $m[0] : '<!DOCTYPE html>';
		$html_tag = preg_match( '/<html\b[^>]*>/i', $html, $m ) ? $m[0] : '<html>';

		// ── 4. Rebuild the <body> tag ──────────────────────────────────
		// The original class list MUST survive: it carries the template
		// variant (e.g. "post-template-single-post-one") that variant-
		// specific theme CSS keys off. We append:
		//   - class    "ad-embed-view"        → styling hook for embeds
		//   - attr     data-ad-embed-post     → read by embed-resize.js
		if ( ! preg_match( '/<body\b[^>]*>/i', $html, $m, PREG_OFFSET_CAPTURE ) ) {
			return $html;
		}
		$body_tag      = $m[0][0];
		$body_open_end = $m[0][1] + strlen( $body_tag );

		$body_new = $body_tag;
		if ( preg_match( '/class="([^"]*)"/i', $body_new, $cm ) ) {
			$body_new = str_replace( $cm[0], 'class="' . $cm[1] . ' ad-embed-view"', $body_new );
		} else {
			$body_new = preg_replace( '/<body\b/i', '<body class="ad-embed-view"', $body_new, 1 );
		}
		$body_new = preg_replace(
			'/<body\b/i',
			'<body data-ad-embed-post="' . (int) $this->post_id . '"',
			$body_new,
			1
		);

		// ── 5. Harvest body assets that live OUTSIDE <main> ────────────
		// The assessment of the live site showed everything functional
		// sits in the footer, after </main>:
		//   - <style id="siteorigin-panels-layouts-footer"> = the post's
		//     OWN grid/column layout CSS (without it, layout collapses)
		//   - all 11 external scripts (theme JS, SiteOrigin lightbox…)
		//   - inline configs those scripts read (e.g. panelsStyles)
		// We sweep BOTH segments (before and after <main>) so a future
		// theme change that moves an asset above <main> still works.
		$body_close = strripos( $html, '</body>' );
		if ( false === $body_close ) {
			$body_close = strlen( $html );
		}
		$before_main = substr( $html, $body_open_end, $main_start - $body_open_end );
		$after_main  = substr( $html, $main_end, $body_close - $main_end );
		$harvested   = trim( $this->harvest( $before_main ) . "\n" . $this->harvest( $after_main ) );

		// ── 6. Embed-specific additions to <head> ──────────────────────
		// overflow:hidden = never show a scrollbar inside the iframe, even
		// for the moment before the first height message lands.
		// The 'ad_embed_strip_selectors' filter lets us hide elements that
		// don't belong in embeds without touching the theme (e.g. the
		// social-share row): return [ '.asdp-social-share' ] and it turns
		// into a display:none rule here.
		$strip_selectors = apply_filters( 'ad_embed_strip_selectors', array() );
		$embed_css       = 'html,body{overflow:hidden;}';
		if ( is_array( $strip_selectors ) && $strip_selectors ) {
			$embed_css .= implode( ',', array_map( 'trim', $strip_selectors ) ) . '{display:none!important;}';
		}
		$head_extra = "\n<meta name=\"robots\" content=\"noindex\">"
			. "\n<style id=\"ad-embed-css\">" . $embed_css . '</style>';

		// The height reporter, appended last so it can measure the final
		// document (see public/embed-resize.js).
		$resize_script = '<script src="'
			. esc_url( AD_EMBED_URL . 'public/embed-resize.js?ver=' . AD_EMBED_VERSION )
			. '"></script>';

		// ── 7. Assemble the final document ─────────────────────────────
		return $doctype . "\n"
			. $html_tag . "\n"
			. $head_open . $head_inner . $head_extra . "\n</head>\n"
			. $body_new . "\n"
			. $main_html . "\n"
			. $harvested . "\n"
			. $resize_script . "\n</body>\n</html>";
	}

	/**
	 * Collect <style>, <script> and <link rel="stylesheet"> blocks from a
	 * body segment outside <main>, preserving their document order
	 * (scripts may depend on configs printed just before them).
	 *
	 * The speculation-rules block (WP 6.8+ prints JSON that tells the
	 * browser to prefetch other pages of the site) is dropped: inside an
	 * embed iframe that's pure wasted bandwidth for the partner's reader.
	 *
	 * @param string $segment Body HTML outside <main>.
	 * @return string Newline-joined blocks, '' when the segment has none.
	 */
	private function harvest( $segment ) {
		if ( '' === trim( $segment ) ) {
			return '';
		}

		// Keyed by byte offset within $segment; ksort() below restores
		// document order after the two collection passes.
		$blocks = array();

		// Pass 1: paired tags. The back-reference \1 makes sure a <style>
		// only closes at </style> and a <script> at </script>; the "s"
		// modifier lets ".*?" span newlines (inline scripts are multiline).
		if ( preg_match_all( '#<(style|script)\b[^>]*>.*?</\1\s*>#is', $segment, $m, PREG_OFFSET_CAPTURE ) ) {
			foreach ( $m[0] as $hit ) {
				$blocks[ $hit[1] ] = $hit[0];
			}
		}

		// Pass 2: void <link> tags — only stylesheets are interesting
		// (not prefetch/preconnect/icon links).
		if ( preg_match_all( '#<link\b[^>]*>#i', $segment, $m, PREG_OFFSET_CAPTURE ) ) {
			foreach ( $m[0] as $hit ) {
				if ( false !== stripos( $hit[0], 'stylesheet' ) ) {
					$blocks[ $hit[1] ] = $hit[0];
				}
			}
		}

		ksort( $blocks );

		$keep = array();
		foreach ( $blocks as $block ) {
			if ( false !== stripos( $block, 'speculationrules' ) ) {
				continue; // see docblock — prefetching is pointless in an iframe.
			}
			$keep[] = $block;
		}

		return implode( "\n", $keep );
	}

	/**
	 * Register GET /wp-json/ad-embed/v1/check?host=…
	 *
	 * The loader on a partner page calls this with its own hostname and
	 * shows a human-readable notice when the answer is "not allowed" —
	 * otherwise the visitor would just see an empty box, because a
	 * CSP-blocked iframe renders as nothing.
	 *
	 * Deliberately public (permission_callback returns true): it reveals
	 * only whether a hostname is allowlisted, which an attacker could
	 * test anyway by simply trying to frame the embed.
	 */
	public function register_rest_route() {
		register_rest_route(
			'ad-embed/v1',
			'/check',
			array(
				'methods'             => 'GET',
				'permission_callback' => '__return_true',
				'args'                => array(
					'host' => array(
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
				'callback'            => array( $this, 'rest_check' ),
			)
		);
	}

	/**
	 * REST callback: { "allowed": true|false } for the given host.
	 *
	 * @param WP_REST_Request $request REST request with a 'host' param.
	 * @return array
	 */
	public function rest_check( $request ) {
		return array(
			'allowed' => AD_Embed_Domains::matches( (string) $request->get_param( 'host' ) ),
		);
	}
}
