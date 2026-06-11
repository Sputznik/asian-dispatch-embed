<?php
/**
 * The "Embed this post" copy button shown to contributors on the frontend.
 *
 * ─────────────────────────────────────────────────────────────────────────
 * PLACEMENT STRATEGY — no theme edits needed
 * ─────────────────────────────────────────────────────────────────────────
 * Buttons are injected via the_content filter:
 *   - Header button: prepended before the post body text.
 *   - Footer button: appended after the post body text.
 *
 * Note: the footer button appears after the post body but BEFORE any
 * theme-rendered elements that live outside the_content() call (such as
 * .post-footnote). Without theme access this is the closest placement
 * available. If theme access is later restored, the original do_action
 * approach can be reinstated by swapping the hook in __construct().
 *
 * The filter is guarded by is_main_query() + in_the_loop() to avoid
 * injecting into widget areas, shortcodes, or REST/feed calls that also
 * invoke the_content() behind the scenes.
 *
 * WHY CONTRIBUTORS DON'T NEED wp-admin
 * Everything happens on the frontend: the capability check runs against
 * their login session cookie, the popover is plain HTML/CSS/JS, and the
 * snippet is pre-rendered into the page — no AJAX, no admin screens.
 *
 * @package asian-dispatch-embed
 */

defined( 'ABSPATH' ) || exit;

class AD_Embed_Button {

	public function __construct() {
		// Inject buttons directly into post content — no theme hooks needed.
		add_filter( 'the_content', array( $this, 'inject_buttons' ) );

		// Assets are registered through the normal enqueue pipeline so
		// they get version query-strings and can be dequeued if needed.
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	/**
	 * Prepend the header button and append the footer button to the post
	 * content. Fires on every the_content() call, but the guards below
	 * limit actual injection to one main-query singular-post view per page.
	 *
	 * @param string $content The post content HTML.
	 * @return string Content with buttons wrapped around it, or unchanged.
	 */
	public function inject_buttons( $content ) {
		// is_main_query(): skip widget areas, shortcodes, and REST/feed
		// calls — those also run the_content() but we only want the
		// primary post body in the single-post template.
		// in_the_loop(): skip any the_content() call outside a WP loop
		// (some page builders do this).
		if ( ! is_main_query() || ! in_the_loop() ) {
			return $content;
		}

		// Capture the header button (render() outputs nothing if
		// should_show() returns false, so ob_get_clean() is safe).
		ob_start();
		$this->render( 'header' );
		$header_btn = ob_get_clean();

		ob_start();
		$this->render( 'footer' );
		$footer_btn = ob_get_clean();

		// Nothing to add (e.g. logged-out visitor) — return untouched.
		if ( '' === $header_btn && '' === $footer_btn ) {
			return $content;
		}

		return $header_btn . $content . $footer_btn;
	}

	/**
	 * Single source of truth for "does the current visitor get a button
	 * on the current request?" — used by both render() and enqueue() so
	 * the markup and its assets always agree.
	 *
	 * @return bool
	 */
	private function should_show() {
		// Never show the button INSIDE an embed view: the embed contains
		// <main>, and the_content() is called inside <main>, so without
		// this check a logged-in contributor previewing an embed would
		// see a button inside the iframe.
		if ( AD_Embed_Endpoint::is_embed_request() ) {
			return false;
		}

		// Posts only (per project scope; the post-type list is filterable
		// centrally in AD_Embed_Plugin::post_types()).
		if ( ! is_singular( AD_Embed_Plugin::post_types() ) ) {
			return false;
		}

		// Drafts/private/scheduled posts can't be embedded (the endpoint
		// would 404), so don't offer a snippet that won't work. Same for
		// password-protected content.
		if ( 'publish' !== get_post_status() || post_password_required() ) {
			return false;
		}

		if ( ! is_user_logged_in() ) {
			return false;
		}

		/**
		 * Capability required to see the copy button.
		 *
		 * Default 'edit_posts' is the lowest capability the Contributor
		 * role has, so this means "Contributor and every role above it"
		 * (Author, Editor, Administrator) — exactly the project brief.
		 *
		 * Example — restrict to Editors and up:
		 *   add_filter( 'ad_embed_capability', function () {
		 *       return 'edit_others_posts';
		 *   } );
		 *
		 * @param string $capability A WordPress capability name.
		 */
		$capability = apply_filters( 'ad_embed_capability', 'edit_posts' );

		return current_user_can( $capability );
	}

	/**
	 * Enqueue the button's CSS/JS — but only when the button will really
	 * render. For everyone else (i.e. virtually all traffic) the plugin
	 * adds zero bytes to the page.
	 */
	public function enqueue() {
		if ( ! $this->should_show() ) {
			return;
		}

		wp_enqueue_style(
			'ad-embed-button',
			AD_EMBED_URL . 'assets/button.css',
			array(),               // no dependencies
			AD_EMBED_VERSION       // cache-buster
		);
		wp_enqueue_script(
			'ad-embed-button',
			AD_EMBED_URL . 'assets/button.js',
			array(),               // vanilla JS, no jQuery needed
			AD_EMBED_VERSION,
			true                   // in footer
		);
	}

	/**
	 * Output the button + hidden copy popover.
	 *
	 * Called by inject_buttons() via output buffering. Can also be called
	 * directly from a theme template via:
	 *   <?php do_action( 'ad_embed_button', 'header' ); ?>
	 * if theme access is later restored — this method is the single
	 * implementation regardless of how it is invoked.
	 *
	 * @param string $location 'header' or 'footer'. Only used as a CSS
	 *                         modifier class — e.g. the footer popover
	 *                         opens UPWARD so it isn't cut off at the
	 *                         bottom of the page. Defaults to 'header'.
	 */
	public function render( $location = 'header' ) {
		if ( ! $this->should_show() ) {
			return;
		}

		// Whitelist the modifier so a typo in a template can't inject
		// arbitrary class names.
		$location = ( 'footer' === $location ) ? 'footer' : 'header';
		$post_id  = get_queried_object_id();
		$snippet  = $this->snippet( $post_id );

		// Markup contract with assets/button.js + button.css:
		//   .ad-embed-copy            wrapper (position:relative anchor)
		//   .ad-embed-copy__toggle    opens/closes the popover
		//   .ad-embed-copy__popover   hidden by default ([hidden] attr)
		//   .ad-embed-copy__code      readonly textarea with the snippet
		//   .ad-embed-copy__btn       copies the textarea to the clipboard
		?>
		<div class="ad-embed-copy ad-embed-copy--<?php echo esc_attr( $location ); ?>">
			<button type="button" class="ad-embed-copy__toggle" aria-expanded="false">
				<?php esc_html_e( 'Embed this post', 'asian-dispatch-embed' ); ?>
			</button>
			<div class="ad-embed-copy__popover" hidden>
				<p class="ad-embed-copy__hint">
					<?php esc_html_e( 'Paste this code into your website where the article should appear. It only works on domains allowlisted by Asian Dispatch.', 'asian-dispatch-embed' ); ?>
				</p>
				<textarea class="ad-embed-copy__code" readonly rows="4" spellcheck="false"><?php echo esc_textarea( $snippet ); ?></textarea>
				<button type="button" class="ad-embed-copy__btn">
					<?php esc_html_e( 'Copy', 'asian-dispatch-embed' ); ?>
				</button>
			</div>
		</div>
		<?php
	}

	/**
	 * Build the snippet contributors copy.
	 *
	 * Two lines:
	 *   1. A placeholder <div> identifying the post by ID (IDs are stable
	 *      even if the slug is later edited; data-title is informational
	 *      only, so partners can recognize the snippet in their markup —
	 *      the loader also uses it as the iframe's accessible title).
	 *   2. The loader <script> (async so it never blocks the partner
	 *      page's rendering). One script tag handles any number of embed
	 *      divs on the same page, and including it twice is harmless —
	 *      embed.js guards against double-initialization.
	 *
	 * @param int $post_id Post ID.
	 * @return string Snippet markup.
	 */
	private function snippet( $post_id ) {
		$snippet = sprintf(
			"<div class=\"ad-embed\" data-post=\"%d\" data-title=\"%s\"></div>\n<script async src=\"%s\"></script>",
			(int) $post_id,
			esc_attr( get_the_title( $post_id ) ),
			esc_url( AD_EMBED_URL . 'public/embed.js' )
		);

		/**
		 * Filter the embed snippet shown in the copy popover.
		 *
		 * @param string $snippet Snippet markup.
		 * @param int    $post_id Post being embedded.
		 */
		return apply_filters( 'ad_embed_snippet', $snippet, $post_id );
	}
}
