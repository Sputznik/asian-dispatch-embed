<?php
/**
 * Plugin Name:       Asian Dispatch Embed
 * Plugin URI:        https://asiandispatch.net/
 * Description:       Lets contributors copy a script that embeds a post on allowlisted external websites, rendered identically to the single-post template.
 * Version:           1.0.0
 * Requires at least: 5.8
 * Requires PHP:      7.2
 * Author:            Asian Dispatch
 * License:           GPL-2.0-or-later
 * Text Domain:       asian-dispatch-embed
 *
 * ─────────────────────────────────────────────────────────────────────────
 * WHAT THIS PLUGIN DOES (the 30-second tour)
 * ─────────────────────────────────────────────────────────────────────────
 *
 * 1. THE BUTTON (class-ad-embed-button.php)
 *    Logged-in users with the Contributor role (or higher) see an
 *    "Embed this post" button on every published post. Clicking it shows
 *    a small popover with a copy-to-clipboard snippet:
 *
 *        <div class="ad-embed" data-post="123" data-title="..."></div>
 *        <script async src=".../asian-dispatch-embed/public/embed.js"></script>
 *
 * 2. THE LOADER (public/embed.js)
 *    A partner website pastes that snippet. The loader script replaces the
 *    <div> with an <iframe> pointing back at our site:
 *
 *        https://asiandispatch.net/?p=123&ad_embed=1
 *
 * 3. THE EMBED VIEW (class-ad-embed-endpoint.php)
 *    That ?ad_embed=1 URL renders the post through the theme's normal
 *    single-post template, then strips everything except the <main>
 *    element (no site header/footer) while keeping every style and script
 *    — so the embedded post looks pixel-identical to our site.
 *
 * 4. THE ALLOWLIST (class-ad-embed-domains.php + class-ad-embed-settings.php)
 *    Administrators maintain a list of partner domains under
 *    Settings → AD Embed. The embed view sends a Content-Security-Policy
 *    "frame-ancestors" header built from that list, so browsers refuse to
 *    render the iframe on any domain that is not allowlisted. This is
 *    enforced by the browser itself and cannot be bypassed by editing JS.
 *
 * 5. NO SCROLLBARS (public/embed-resize.js)
 *    Inside the iframe, a small script measures the content height and
 *    reports it to the partner page via postMessage; the loader resizes
 *    the iframe to match. The embed reads like native page content.
 *
 * This file is only the entry point: it defines constants, loads the
 * classes, and boots the plugin. All real behavior lives in /includes.
 */

// Safety net: every PHP file in this plugin bails out when accessed
// directly (outside of WordPress), so nothing can be executed by
// requesting the file's URL.
defined( 'ABSPATH' ) || exit;

// Version is appended to asset URLs (?ver=1.0.0) for cache-busting:
// bump it on release and browsers re-download changed CSS/JS.
define( 'AD_EMBED_VERSION', '1.0.0' );

// Absolute filesystem path of this file (used for activation hooks etc.).
define( 'AD_EMBED_FILE', __FILE__ );

// Filesystem path to the plugin folder, with trailing slash.
// Example: /var/www/wp-content/plugins/asian-dispatch-embed/
define( 'AD_EMBED_DIR', plugin_dir_path( __FILE__ ) );

// Public URL of the plugin folder, with trailing slash.
// Example: https://asiandispatch.net/wp-content/plugins/asian-dispatch-embed/
define( 'AD_EMBED_URL', plugin_dir_url( __FILE__ ) );

// Load order matters only in that Domains has no dependencies and the
// Plugin class (loaded last) instantiates the others.
require_once AD_EMBED_DIR . 'includes/class-ad-embed-domains.php';
require_once AD_EMBED_DIR . 'includes/class-ad-embed-endpoint.php';
require_once AD_EMBED_DIR . 'includes/class-ad-embed-button.php';
require_once AD_EMBED_DIR . 'includes/class-ad-embed-settings.php';
require_once AD_EMBED_DIR . 'includes/class-ad-embed-plugin.php';

// Boot. From here on, everything is driven by WordPress hooks that the
// component classes register in their constructors.
AD_Embed_Plugin::instance();
