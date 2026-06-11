<?php
/**
 * Bootstrap class: wires up the plugin components.
 *
 * This class does almost nothing on purpose. It exists so that:
 *  - there is exactly ONE place where components are instantiated,
 *  - other code (or tests) can reach the live component objects via
 *    AD_Embed_Plugin::instance()->endpoint etc.,
 *  - admin-only code (the settings screen) is not even loaded into
 *    memory on frontend requests.
 *
 * @package asian-dispatch-embed
 */

defined( 'ABSPATH' ) || exit;

class AD_Embed_Plugin {

	/**
	 * The single shared instance (classic singleton pattern — WordPress
	 * plugins are effectively global, so one instance is all we want).
	 *
	 * @var AD_Embed_Plugin|null
	 */
	private static $instance = null;

	/**
	 * Handles the ?ad_embed=1 embed view + the REST allowlist check.
	 *
	 * @var AD_Embed_Endpoint
	 */
	public $endpoint;

	/**
	 * Renders the "Embed this post" copy button for contributors.
	 *
	 * @var AD_Embed_Button
	 */
	public $button;

	/**
	 * The Settings → AD Embed admin screen. Only instantiated in
	 * wp-admin; stays null on frontend requests.
	 *
	 * @var AD_Embed_Settings|null
	 */
	public $settings = null;

	/**
	 * Get (or lazily create) the shared plugin instance.
	 *
	 * Called once from asian-dispatch-embed.php at load time.
	 *
	 * @return AD_Embed_Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Private constructor: instantiating a component registers all of its
	 * WordPress hooks (each class hooks itself in its own constructor).
	 */
	private function __construct() {
		$this->endpoint = new AD_Embed_Endpoint();
		$this->button   = new AD_Embed_Button();

		// The settings screen is pure admin UI — skip it entirely for
		// frontend page loads to keep them lean.
		if ( is_admin() ) {
			$this->settings = new AD_Embed_Settings();
		}
	}

	/**
	 * The post types that can be embedded.
	 *
	 * Centralized here because both the endpoint (may this URL be
	 * embedded?) and the button (should the button render here?) need the
	 * same answer. Posts only for now, per project scope.
	 *
	 * @return string[] Post type slugs, e.g. [ 'post' ].
	 */
	public static function post_types() {
		/**
		 * Filter the embeddable post types.
		 *
		 * Example — allow a custom "report" post type to be embedded:
		 *   add_filter( 'ad_embed_post_types', function ( $types ) {
		 *       $types[] = 'report';
		 *       return $types;
		 *   } );
		 *
		 * @param string[] $types Post type slugs. Default [ 'post' ].
		 */
		return apply_filters( 'ad_embed_post_types', array( 'post' ) );
	}
}
