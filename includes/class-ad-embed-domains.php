<?php
/**
 * Domain allowlist logic: normalization, wildcard matching, CSP building.
 *
 * This class is pure logic — no hooks, no UI. Everything is static so the
 * other components can simply call AD_Embed_Domains::matches( $host ) etc.
 *
 * STORAGE FORMAT
 * The allowlist lives in one WordPress option (see self::OPTION) as an
 * array of already-normalized entries, for example:
 *
 *     [ 'example.com', 'news.partner.org', '*.network.net' ]
 *
 * The settings screen (class-ad-embed-settings.php) is responsible for
 * turning the admin's textarea input into this normalized form, using
 * self::normalize() on every line.
 *
 * MATCHING RULES (documented on the settings page for admins)
 *     example.com      matches example.com AND www.example.com
 *                      (the www convenience is deliberate: admins think of
 *                      "the partner's domain", not its exact host header)
 *     news.example.com matches that exact subdomain only
 *     *.example.com    matches example.com and EVERY subdomain of it
 *
 * WHERE THE LIST IS ENFORCED
 *  1. csp_header_value() → the Content-Security-Policy "frame-ancestors"
 *     header on embed views. This is the REAL enforcement: the partner's
 *     browser refuses to render our iframe on any origin not listed.
 *  2. matches() → the public REST route /ad-embed/v1/check, which the
 *     loader script uses purely to show a friendly "not authorized"
 *     message instead of a silently blank (CSP-blocked) iframe.
 *
 * @package asian-dispatch-embed
 */

defined( 'ABSPATH' ) || exit;

class AD_Embed_Domains {

	/**
	 * Name of the wp_options row that stores the allowlist.
	 */
	const OPTION = 'ad_embed_allowlist';

	/**
	 * Read the allowlist from the database.
	 *
	 * Defensive: whatever is stored, this always returns a clean,
	 * re-indexed array of non-empty strings — callers never have to
	 * worry about a corrupted option value.
	 *
	 * @return string[] Normalized allowlist entries.
	 */
	public static function get_allowlist() {
		$value = get_option( self::OPTION, array() );
		return is_array( $value ) ? array_values( array_filter( array_map( 'strval', $value ) ) ) : array();
	}

	/**
	 * Normalize ONE user-entered line into a canonical allowlist entry.
	 *
	 * Admins paste all sorts of things — full URLs, hosts with ports,
	 * mixed case. We accept generously and store strictly:
	 *
	 *     "https://Example.com/some/path"  → "example.com"
	 *     "sub.example.com:8080"           → "sub.example.com"
	 *     "*.Example.com"                  → "*.example.com"
	 *     "not a domain!!"                 → false (rejected)
	 *
	 * @param string $entry Raw line from the settings textarea.
	 * @return string|false Normalized entry, or false when the line cannot
	 *                      be interpreted as a domain.
	 */
	public static function normalize( $entry ) {
		$domain = strtolower( trim( (string) $entry ) );
		if ( '' === $domain ) {
			return false;
		}

		// Strip the parts of a URL that are not the hostname, in order:
		$domain = preg_replace( '#^[a-z][a-z0-9+.-]*://#', '', $domain ); // scheme  ("https://")
		$domain = preg_replace( '#[/?\#].*$#', '', $domain );             // path / query / fragment
		$domain = preg_replace( '#:\d+$#', '', $domain );                 // port    (":8080")
		$domain = trim( $domain, ". \t" );                                // stray dots/whitespace

		// A leading "*." marks a wildcard entry. Remember it, validate the
		// base domain without it, and re-attach it at the end.
		$wildcard = false;
		if ( 0 === strpos( $domain, '*.' ) ) {
			$wildcard = true;
			$domain   = substr( $domain, 2 );
		}

		// Any other use of "*" (e.g. "foo.*.com") is not supported —
		// CSP source expressions only allow a leftmost wildcard label.
		if ( '' === $domain || false !== strpos( $domain, '*' ) ) {
			return false;
		}

		// International domains (e.g. "münchen.de") must be stored in
		// punycode ("xn--mnchen-3ya.de") because that is what browsers put
		// in location.hostname and in CSP origin comparisons.
		if ( preg_match( '/[^\x20-\x7e]/', $domain ) && function_exists( 'idn_to_ascii' ) ) {
			$ascii = idn_to_ascii( $domain, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46 );
			if ( false !== $ascii && null !== $ascii ) {
				$domain = $ascii;
			}
		}

		// Final shape check: one or more labels + a TLD-looking last label.
		// "localhost" is whitelisted as a special case for local partner
		// development (it has no dot, so the regex alone would reject it).
		$is_hostname = (bool) preg_match(
			'/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z][a-z0-9-]{1,62}$/',
			$domain
		);
		if ( ! $is_hostname && 'localhost' !== $domain ) {
			return false;
		}

		return ( $wildcard ? '*.' : '' ) . $domain;
	}

	/**
	 * Is this hostname covered by the allowlist?
	 *
	 * Used by the REST check route. The $host value ultimately comes from
	 * the partner page's window.location.hostname (sent by the loader).
	 *
	 * @param string $host Hostname (no scheme), e.g. "news.partner.org".
	 * @return bool
	 */
	public static function matches( $host ) {
		// Mirror the storage normalization so comparisons are apples to
		// apples: lowercase, no port, trimmed.
		$host = strtolower( trim( (string) $host ) );
		$host = preg_replace( '#:\d+$#', '', $host );
		if ( '' === $host ) {
			return false;
		}

		$allowlist = self::get_allowlist();

		// Empty allowlist = no restrictions; every domain is permitted.
		// This lets the plugin work out of the box before an admin has
		// configured anything. Add domains to restrict embedding.
		if ( empty( $allowlist ) ) {
			return true;
		}

		foreach ( $allowlist as $entry ) {
			if ( 0 === strpos( $entry, '*.' ) ) {
				// Wildcard entry: "*.network.net" matches the bare domain
				// ("network.net") or anything ENDING IN ".network.net".
				// The leading dot in the suffix comparison is what stops
				// lookalike domains: "evilnetwork.net" does NOT end in
				// ".network.net", so it does not match.
				$base = substr( $entry, 2 );
				if ( $host === $base || substr( $host, -strlen( '.' . $base ) ) === '.' . $base ) {
					return true;
				}
			} elseif ( $host === $entry || $host === 'www.' . $entry ) {
				// Plain entry: exact match, plus the documented "www."
				// convenience (entry "example.com" also covers
				// "www.example.com" — but NOT any other subdomain).
				return true;
			}
		}

		return false;
	}

	/**
	 * Build the CSP source expressions for the frame-ancestors directive.
	 *
	 * One allowlist entry expands to the origins a browser might actually
	 * report, mirroring the matches() rules:
	 *
	 *     'example.com'    → https://example.com https://www.example.com
	 *     '*.example.com'  → https://example.com https://*.example.com
	 *     'localhost'      → http://localhost https://localhost
	 *                        (both schemes: local dev servers run on HTTP)
	 *
	 * Real partner sites must be HTTPS. localhost is the sole exception:
	 * local dev servers run on HTTP, so both schemes are emitted for it,
	 * covering any port (e.g. :3000, :3456, :8080).
	 *
	 * @return string[] CSP source expressions (never includes 'self' —
	 *                  the caller adds that).
	 */
	public static function csp_sources() {
		$entries = self::get_allowlist();
		$sources = array();

		foreach ( $entries as $entry ) {
			if ( 0 === strpos( $entry, '*.' ) ) {
				$base      = substr( $entry, 2 );
				$sources[] = 'https://' . $base;         // CSP "*." does not cover the bare domain,
				$sources[] = 'https://*.' . $base;        // so we emit both explicitly.
			} elseif ( 'localhost' === $entry ) {
				// Local dev servers are HTTP; emit both schemes so any
				// port on localhost is covered by the CSP header.
				$sources[] = 'http://localhost';
				$sources[] = 'https://localhost';
			} else {
				$sources[] = 'https://' . $entry;
				if ( 0 !== strpos( $entry, 'www.' ) ) {
					$sources[] = 'https://www.' . $entry; // the www convenience, mirrored in CSP.
				}
			}
		}

		/**
		 * Filter the CSP frame-ancestors sources built from the allowlist.
		 *
		 * Example — let a partner develop locally against production:
		 *   add_filter( 'ad_embed_csp_sources', function ( $sources ) {
		 *       $sources[] = 'http://localhost:3000';
		 *       return $sources;
		 *   } );
		 *
		 * @param string[] $sources CSP source expressions (without 'self').
		 * @param string[] $entries Raw allowlist entries they were built from.
		 */
		return apply_filters( 'ad_embed_csp_sources', array_values( array_unique( $sources ) ), $entries );
	}

	/**
	 * The full value of the Content-Security-Policy header sent on embed
	 * views, e.g.:
	 *
	 *     frame-ancestors 'self' https://partner.com https://www.partner.com
	 *
	 * 'self' is always included so our own site can preview embeds.
	 * With an EMPTY allowlist this becomes "frame-ancestors *" —
	 * i.e. any domain may embed. Add domains to the list to restrict.
	 *
	 * @return string
	 */
	public static function csp_header_value() {
		$sources = self::csp_sources();

		// No allowlist entries = unrestricted. The CSP wildcard "*" permits
		// framing from any origin, matching the matches() open-by-default rule.
		if ( empty( $sources ) ) {
			return "frame-ancestors *";
		}

		return 'frame-ancestors ' . implode( ' ', array_merge( array( "'self'" ), $sources ) );
	}
}
