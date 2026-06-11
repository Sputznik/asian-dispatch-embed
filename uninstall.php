<?php
/**
 * Uninstall cleanup for Asian Dispatch Embed.
 *
 * WordPress runs this file (NOT the plugin's hooks — the plugin is no
 * longer loaded at this point) when the plugin is DELETED from the
 * Plugins screen. Deactivation alone keeps all data.
 *
 * The plugin stores exactly one thing: the domain allowlist option.
 * Everything else (the embed view, buttons, headers) is computed at
 * request time and leaves no trace in the database.
 *
 * @package asian-dispatch-embed
 */

// WP_UNINSTALL_PLUGIN is only defined when WordPress itself runs this
// file during uninstall — direct HTTP access bails out here.
defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( 'ad_embed_allowlist' );
