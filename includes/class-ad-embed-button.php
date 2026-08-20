<?php
/**
 * The "Embed this post" copy button shown to contributors on the frontend.
 *
 * ─────────────────────────────────────────────────────────────────────────
 * PLACEMENT STRATEGY — developer-controlled via shortcode
 * ─────────────────────────────────────────────────────────────────────────
 * No automatic injection into post content. The developer places the
 * button explicitly, wherever it belongs in the theme/page-builder markup:
 *
 *   [ad_embed_button]                        defaults to location="header"
 *   [ad_embed_button location="footer"]
 *   [ad_embed_button text="Embed this article"]
 *   [ad_embed_button icon="yes"]             icon-only, no visible label
 *
 * The "location" attribute is only a CSS/behavior modifier (it flips
 * which direction the popover opens — see button.css) — it does not
 * control WHERE on the page the button appears; that's entirely up to
 * where the developer places the shortcode. "text" and "icon" control
 * the button's own label/appearance — see shortcode() for full details.
 *
 * The same render() method can also be called directly from PHP, e.g.
 * from a template:
 *   <?php echo do_shortcode( '[ad_embed_button location="footer"]' ); ?>
 * or:
 *   <?php AD_Embed_Plugin::instance()->button->render( 'footer' ); ?>
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

	/**
	 * Shortcode tag developers use to place the button.
	 */
	const SHORTCODE_TAG = 'ad_embed_button';

	public function __construct() {
		add_shortcode( self::SHORTCODE_TAG, array( $this, 'shortcode' ) );

		// Assets are registered through the normal enqueue pipeline so
		// they get version query-strings and can be dequeued if needed.
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	/**
	 * Shortcode handler for [ad_embed_button].
	 *
	 * Examples:
	 *   [ad_embed_button]
	 *   [ad_embed_button location="footer"]
	 *   [ad_embed_button text="Embed this article"]
	 *   [ad_embed_button icon="yes"]                    icon-only, no visible label
	 *   [ad_embed_button icon="yes" text="Embed post"]   icon-only, custom aria-label
	 *
	 * @param array|string $atts Shortcode attributes. Supports:
	 *                           'location' => 'header'|'footer' (default 'header').
	 *                           'text'     => button label / accessible name
	 *                                         (default "Embed this post").
	 *                           'icon'     => 'yes' to render an icon-only
	 *                                         button (default 'no').
	 * @return string Button markup, or '' if should_show() is false.
	 */
	public function shortcode( $atts ) {
		$atts = shortcode_atts(
			array(
				'location' => 'header',
				'text'     => __( 'Embed this post', 'asian-dispatch-embed' ),
				'icon'     => 'no',
			),
			$atts,
			self::SHORTCODE_TAG
		);

		// Shortcodes must return their output, not echo it.
		ob_start();
		$this->render(
			$atts['location'],
			array(
				'text' => $atts['text'],
				'icon' => in_array( strtolower( (string) $atts['icon'] ), array( 'yes', 'true', '1' ), true ),
			)
		);
		return ob_get_clean();
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
	 * Enqueue the button's CSS/JS for any qualifying single-post view
	 * (per should_show()), regardless of whether [ad_embed_button] is
	 * actually placed on the page. Assets are still zero-cost for
	 * virtually all traffic since should_show() requires Contributor+.
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
	 * Called by the [ad_embed_button] shortcode via output buffering (see
	 * shortcode()). Can also be called directly, e.g. from a template:
	 *   <?php AD_Embed_Plugin::instance()->button->render( 'footer', array( 'icon' => true ) ); ?>
	 * — this method is the single implementation regardless of how it is
	 * invoked.
	 *
	 * @param string $location 'header' or 'footer'. Only used as a CSS
	 *                         modifier class — e.g. the footer popover
	 *                         opens UPWARD so it isn't cut off at the
	 *                         bottom of the page. Defaults to 'header'.
	 * @param array  $args {
	 *     Optional display overrides.
	 *
	 *     @type string $text Button label (also the accessible name when
	 *                        icon-only). Default "Embed this post".
	 *     @type bool   $icon When true, hides the text label visually and
	 *                        shows only the "</>" glyph — the label stays
	 *                        in the markup as the button's aria-label /
	 *                        screen-reader text. Default false.
	 * }
	 */
	public function render( $location = 'header', $args = array() ) {
		if ( ! $this->should_show() ) {
			return;
		}

		// Whitelist the modifier so a typo in a template can't inject
		// arbitrary class names.
		$location = ( 'footer' === $location ) ? 'footer' : 'header';

		$args = wp_parse_args(
			$args,
			array(
				'text' => __( 'Embed this post', 'asian-dispatch-embed' ),
				'icon' => false,
			)
		);
		$text = $args['text'];
		$icon = (bool) $args['icon'];

		$post_id = get_queried_object_id();
		$snippet = $this->snippet( $post_id );

		$toggle_class = 'ad-embed-copy__toggle' . ( $icon ? ' ad-embed-copy__toggle--icon' : '' );

		// Markup contract with assets/button.js + button.css:
		//   .ad-embed-copy               wrapper (position:relative anchor)
		//   .ad-embed-copy__toggle       opens/closes the popover
		//   .ad-embed-copy__toggle--icon icon-only modifier (visually hides the label,
		//                                switches the glyph to a Font Awesome <i>)
		//   .ad-embed-copy__toggle-label the label text (kept for screen readers when icon-only)
		//   .ad-embed-copy__popover      hidden by default ([hidden] attr)
		//   .ad-embed-copy__code         readonly textarea with the snippet
		//   .ad-embed-copy__btn          copies the textarea to the clipboard
		//
		// icon="yes" uses the theme's existing Font Awesome 4 (fa fa-code) —
		// the plugin doesn't enqueue FA itself, it's already loaded
		// site-wide (SiteOrigin Widgets Bundle + cdnjs, per
		// ASSET-ASSESSMENT.md). The non-icon variant keeps the CSS-drawn
		// "</>" glyph (button.css ::before) so it needs no icon font.
		?>
		<div class="ad-embed-copy ad-embed-copy--<?php echo esc_attr( $location ); ?>">
			<button
				type="button"
				class="<?php echo esc_attr( $toggle_class ); ?>"
				aria-expanded="false"
				<?php echo $icon ? 'aria-label="' . esc_attr( $text ) . '"' : ''; ?>
			>
				<?php if ( $icon ) : ?>
					<i class="fa fa-code" aria-hidden="true"></i>
				<?php endif; ?>
				<span class="ad-embed-copy__toggle-label"><?php echo esc_html( $text ); ?></span>
			</button>
			<div class="ad-embed-copy__popover" hidden>
				<p class="ad-embed-copy__hint">
					<?php esc_html_e( 'Paste this code into your website where the article should appear.', 'asian-dispatch-embed' ); ?>
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
