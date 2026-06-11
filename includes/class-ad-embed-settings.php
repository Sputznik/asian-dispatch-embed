<?php
/**
 * The Settings → AD Embed admin screen (administrators only).
 *
 * One setting: the domain allowlist, entered as a textarea with one
 * domain per line. On save each line is normalized through
 * AD_Embed_Domains::normalize(); invalid lines are reported in an admin
 * notice and dropped, valid ones are stored as a clean array.
 *
 * Built on the WordPress Settings API, which gives us for free:
 *   - nonce verification + capability checks on save (options.php),
 *   - the standard settings-saved / error notices,
 *   - sane storage in a single wp_options row.
 *
 * This class is only instantiated inside wp-admin
 * (see AD_Embed_Plugin::__construct()).
 *
 * @package asian-dispatch-embed
 */

defined( 'ABSPATH' ) || exit;

class AD_Embed_Settings {

	/** Menu/page slug: wp-admin/options-general.php?page=ad-embed */
	const PAGE = 'ad-embed';

	/** Settings-API group name binding the form to its registered setting. */
	const GROUP = 'ad_embed_group';

	public function __construct() {
		add_action( 'admin_menu', array( $this, 'add_page' ) );
		add_action( 'admin_init', array( $this, 'register' ) );

		// WordPress fires "update_option_{name}" only when the stored
		// value actually CHANGES, and "add_option_{name}" the first time
		// it is created — together they cover every real save, which is
		// when cached embed views (and their CSP headers) go stale.
		add_action( 'update_option_' . AD_Embed_Domains::OPTION, array( $this, 'on_allowlist_change' ) );
		add_action( 'add_option_' . AD_Embed_Domains::OPTION, array( $this, 'on_allowlist_change' ) );
	}

	/**
	 * Register the page under Settings. 'manage_options' restricts it to
	 * administrators — the project requirement that ONLY admins manage
	 * the allowlist is enforced here (menu) and by options.php (save).
	 */
	public function add_page() {
		add_options_page(
			__( 'Asian Dispatch Embed', 'asian-dispatch-embed' ),  // <title>
			__( 'AD Embed', 'asian-dispatch-embed' ),              // menu label
			'manage_options',
			self::PAGE,
			array( $this, 'render_page' )
		);
	}

	/**
	 * Wire the setting, its section, and its single field into the
	 * Settings API.
	 */
	public function register() {
		register_setting(
			self::GROUP,
			AD_Embed_Domains::OPTION,
			array(
				'type'              => 'array',
				// Every save passes through here — the textarea string
				// goes in, a normalized array comes out.
				'sanitize_callback' => array( $this, 'sanitize_allowlist' ),
				'default'           => array(),
			)
		);

		add_settings_section(
			'ad_embed_main',
			__( 'Allowlisted domains', 'asian-dispatch-embed' ),
			array( $this, 'render_section_intro' ),
			self::PAGE
		);

		add_settings_field(
			'ad_embed_allowlist_field',
			__( 'Domains', 'asian-dispatch-embed' ),
			array( $this, 'render_allowlist_field' ),
			self::PAGE,
			'ad_embed_main',
			array( 'label_for' => 'ad_embed_allowlist_textarea' )
		);
	}

	/**
	 * Explanatory text + the matching rules, shown above the textarea.
	 * Keep this in sync with AD_Embed_Domains::matches().
	 */
	public function render_section_intro() {
		echo '<p>' . esc_html__( 'Embedded posts will only render on the domains listed below (enforced in the browser via a Content-Security-Policy frame-ancestors header). One entry per line. Leave empty to allow embedding on any domain.', 'asian-dispatch-embed' ) . '</p>';
		echo '<ul style="list-style:disc;margin-left:1.5em">';
		echo '<li><code>example.com</code> — ' . esc_html__( 'matches example.com and www.example.com', 'asian-dispatch-embed' ) . '</li>';
		echo '<li><code>news.example.com</code> — ' . esc_html__( 'matches that exact subdomain only', 'asian-dispatch-embed' ) . '</li>';
		echo '<li><code>*.example.com</code> — ' . esc_html__( 'matches example.com and every subdomain', 'asian-dispatch-embed' ) . '</li>';
		echo '</ul>';
	}

	/**
	 * The textarea itself. The stored array is joined back to one-per-line
	 * text for display.
	 */
	public function render_allowlist_field() {
		$entries = AD_Embed_Domains::get_allowlist();
		printf(
			'<textarea id="ad_embed_allowlist_textarea" name="%s" rows="10" cols="50" class="large-text code" placeholder="partner-site.com&#10;*.news-network.org">%s</textarea>',
			esc_attr( AD_Embed_Domains::OPTION ),
			esc_textarea( implode( "\n", $entries ) )
		);
		echo '<p class="description">' . esc_html__( 'Leave empty to allow embedding on any domain. Add domains here to restrict embedding to those sites only.', 'asian-dispatch-embed' ) . '</p>';
	}

	/**
	 * Sanitize callback: textarea string in → normalized string[] out.
	 *
	 * Each non-empty line goes through AD_Embed_Domains::normalize().
	 * Lines that fail are collected and surfaced to the admin in a
	 * settings error notice (so a typo is noticed, not silently eaten),
	 * then dropped. Duplicates are removed.
	 *
	 * @param string|array $raw The POSTed textarea value. Can also be an
	 *                          array: WordPress is known to run the
	 *                          sanitize callback twice when the option
	 *                          row doesn't exist yet, the second time
	 *                          with the already-sanitized value — so an
	 *                          array input is passed through (re-cleaned,
	 *                          not re-parsed).
	 * @return string[] Normalized, de-duplicated allowlist.
	 */
	public function sanitize_allowlist( $raw ) {
		if ( is_array( $raw ) ) {
			return array_values( array_unique( array_filter( array_map( 'strval', $raw ) ) ) );
		}

		$valid   = array();
		$invalid = array();

		foreach ( preg_split( '/[\r\n]+/', (string) $raw ) as $line ) {
			$line = trim( $line );
			if ( '' === $line ) {
				continue;
			}
			$normalized = AD_Embed_Domains::normalize( $line );
			if ( false === $normalized ) {
				$invalid[] = $line;
			} else {
				$valid[] = $normalized;
			}
		}

		if ( $invalid ) {
			add_settings_error(
				AD_Embed_Domains::OPTION,
				'ad_embed_invalid_domains',
				sprintf(
					/* translators: %s: comma-separated list of rejected lines. */
					__( 'These entries are not valid domains and were ignored: %s', 'asian-dispatch-embed' ),
					implode( ', ', array_map( 'esc_html', $invalid ) )
				)
			);
		}

		return array_values( array_unique( $valid ) );
	}

	/**
	 * Runs after the allowlist is saved with a changed value.
	 *
	 * Cached embed views carry the OLD CSP header until the page cache is
	 * cleared, which would make a freshly allowlisted partner appear
	 * "broken" — so we purge the popular cache plugins right away. Each
	 * call is guarded by function_exists, so absent plugins are no-ops.
	 */
	public function on_allowlist_change() {
		/**
		 * Fires after the embed domain allowlist is saved.
		 * Hook custom cache purges or partner notifications here.
		 */
		do_action( 'ad_embed_allowlist_updated' );

		if ( function_exists( 'rocket_clean_domain' ) ) {
			rocket_clean_domain(); // WP Rocket.
		}
		if ( function_exists( 'w3tc_flush_all' ) ) {
			w3tc_flush_all(); // W3 Total Cache.
		}
		if ( function_exists( 'wp_cache_clear_cache' ) ) {
			wp_cache_clear_cache(); // WP Super Cache.
		}
		do_action( 'litespeed_purge_all' ); // LiteSpeed Cache (no-op when absent).
	}

	/**
	 * Render the full settings screen:
	 *   1. the form (Settings API renders section + field, posts to
	 *      options.php which handles nonce/capability/save),
	 *   2. a read-only preview of the exact CSP header currently being
	 *      sent on embed views — so an admin can verify what is enforced
	 *      without reading code or response headers,
	 *   3. the snippet format contributors copy, for reference/support.
	 */
	public function render_page() {
		// Defense in depth — add_options_page already gates the menu.
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Asian Dispatch Embed', 'asian-dispatch-embed' ); ?></h1>

			<form action="options.php" method="post">
				<?php
				settings_fields( self::GROUP );      // nonce + hidden fields
				do_settings_sections( self::PAGE );  // intro + textarea
				submit_button();
				?>
			</form>

			<hr>

			<h2><?php esc_html_e( 'Current security header', 'asian-dispatch-embed' ); ?></h2>
			<p><?php esc_html_e( 'Embed views are served with this header. Browsers refuse to render the embed anywhere not listed:', 'asian-dispatch-embed' ); ?></p>
			<p><code>Content-Security-Policy: <?php echo esc_html( AD_Embed_Domains::csp_header_value() ); ?></code></p>

			<h2><?php esc_html_e( 'What contributors copy', 'asian-dispatch-embed' ); ?></h2>
			<p><?php esc_html_e( 'On every published post, contributors (and above) see an "Embed this post" button that copies a snippet in this format:', 'asian-dispatch-embed' ); ?></p>
			<pre><code>&lt;div class="ad-embed" data-post="123" data-title="Post title"&gt;&lt;/div&gt;
&lt;script async src="<?php echo esc_html( AD_EMBED_URL . 'public/embed.js' ); ?>"&gt;&lt;/script&gt;</code></pre>
		</div>
		<?php
	}
}
