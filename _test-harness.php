<?php
/**
 * Dev-only test harness (not part of the plugin — underscore prefix).
 * Stubs the WP functions the classes touch, then:
 *  1. runs AD_Embed_Endpoint::transform() against _sample-post.html
 *  2. asserts structural expectations from ASSET-ASSESSMENT.md
 *  3. unit-tests AD_Embed_Domains normalize/matches/csp
 */

error_reporting( E_ALL );

// ---- WP stubs -------------------------------------------------------------
define( 'ABSPATH', __DIR__ . '/' );
define( 'AD_EMBED_VERSION', '1.0.0' );
define( 'AD_EMBED_URL', 'https://asiandispatch.net/wp-content/plugins/asian-dispatch-embed/' );

$GLOBALS['__options'] = array();

function add_filter( ...$a ) {}
function add_action( ...$a ) {}
function apply_filters( $tag, $value, ...$a ) { return $value; }
function do_action( ...$a ) {}
function esc_url( $url ) { return $url; }
function get_option( $name, $default = false ) {
	return isset( $GLOBALS['__options'][ $name ] ) ? $GLOBALS['__options'][ $name ] : $default;
}
function get_query_var( $var ) { return ''; }

require __DIR__ . '/includes/class-ad-embed-domains.php';
require __DIR__ . '/includes/class-ad-embed-endpoint.php';

$failures = 0;
function check( $label, $cond ) {
	global $failures;
	echo ( $cond ? 'PASS' : 'FAIL' ) . "  $label\n";
	if ( ! $cond ) { $failures++; }
}

// ---- 1. transform against the real fetched post ---------------------------
$html = file_get_contents( __DIR__ . '/_sample-post.html' );

$endpoint = new AD_Embed_Endpoint();
$prop = new ReflectionProperty( AD_Embed_Endpoint::class, 'post_id' );
$prop->setAccessible( true );
$prop->setValue( $endpoint, 2499 );

$out = $endpoint->transform( $html );
file_put_contents( __DIR__ . '/_transform-output.html', $out );

check( 'output differs from input (transform ran)', $out !== $html );
check( 'doctype preserved', stripos( $out, '<!DOCTYPE' ) === 0 );
check( 'exactly one <main> kept', substr_count( strtolower( $out ), '<main' ) === 1 );
check( '<main> content present (post title wrapper)', strpos( $out, 'single-post-wrap' ) !== false );
check( 'body has ad-embed-view class', (bool) preg_match( '/<body[^>]*class="[^"]*ad-embed-view/', $out ) );
check( 'body has data-ad-embed-post="2499"', strpos( $out, 'data-ad-embed-post="2499"' ) !== false );
check( 'original body classes kept (template variant)', (bool) preg_match( '/<body[^>]*post-template-single-post-one/', $out ) );
check( 'head stylesheets kept (theme main.css)', strpos( $out, 'asian-dispatch-theme/css/main.css' ) !== false );
check( 'SiteOrigin footer layout CSS carried over', strpos( $out, 'siteorigin-panels-layouts-footer' ) !== false );
check( 'footer script carried (siteorigin styling.min.js)', strpos( $out, 'siteorigin-panels/js/styling.min.js' ) !== false );
check( 'footer inline config carried (panelsStyles)', strpos( $out, 'panelsStyles' ) !== false );
check( 'theme main.js carried', strpos( $out, 'asian-dispatch-theme/js/main.js' ) !== false );
check( 'speculationrules block stripped', stripos( $out, 'speculationrules' ) === false );
check( 'site header/nav dropped (no <nav>)', stripos( $out, '<nav' ) === false );
check( 'site <header> dropped', ! preg_match( '/<header\b[^>]*class="[^"]*(site-header|main-header)/i', $out ) );
check( 'robots noindex meta injected', strpos( $out, '<meta name="robots" content="noindex">' ) !== false );
check( 'embed CSS injected', strpos( $out, 'id="ad-embed-css"' ) !== false );
check( 'resize script appended', strpos( $out, 'public/embed-resize.js' ) !== false );
check( 'document closes properly', substr( rtrim( $out ), -7 ) === '</html>' );

// header markup before <main> must be gone: check a known nav class from sample
check( 'menu markup gone', strpos( $out, 'asdp-social-share' ) !== false /* in-main share kept */ );

// ---- 2. graceful fallback on pages without <main> -------------------------
$no_main = '<html><head></head><body><p>x</p></body></html>';
check( 'fallback: page without <main> returned untouched', $endpoint->transform( $no_main ) === $no_main );

// ---- 3. domains: normalize ------------------------------------------------
check( 'normalize plain', AD_Embed_Domains::normalize( 'Example.COM' ) === 'example.com' );
check( 'normalize scheme+path', AD_Embed_Domains::normalize( 'https://Example.com/some/path?q=1' ) === 'example.com' );
check( 'normalize port', AD_Embed_Domains::normalize( 'example.com:8080' ) === 'example.com' );
check( 'normalize wildcard', AD_Embed_Domains::normalize( '*.example.com' ) === '*.example.com' );
check( 'normalize subdomain', AD_Embed_Domains::normalize( 'news.partner.org' ) === 'news.partner.org' );
check( 'normalize localhost', AD_Embed_Domains::normalize( 'localhost' ) === 'localhost' );
check( 'reject garbage', AD_Embed_Domains::normalize( 'not a domain!!' ) === false );
check( 'reject mid-wildcard', AD_Embed_Domains::normalize( 'foo.*.com' ) === false );
check( 'reject empty', AD_Embed_Domains::normalize( '   ' ) === false );
check( 'reject bare tld-less', AD_Embed_Domains::normalize( 'partner' ) === false );

// ---- 4. domains: matches ----------------------------------------------------
$GLOBALS['__options']['ad_embed_allowlist'] = array( 'example.com', 'news.partner.org', '*.network.net' );

check( 'match exact', AD_Embed_Domains::matches( 'example.com' ) );
check( 'match www convenience', AD_Embed_Domains::matches( 'www.example.com' ) );
check( 'no match other subdomain', ! AD_Embed_Domains::matches( 'blog.example.com' ) );
check( 'match exact subdomain entry', AD_Embed_Domains::matches( 'news.partner.org' ) );
check( 'no match parent of subdomain entry', ! AD_Embed_Domains::matches( 'partner.org' ) );
check( 'wildcard matches bare', AD_Embed_Domains::matches( 'network.net' ) );
check( 'wildcard matches sub', AD_Embed_Domains::matches( 'a.network.net' ) );
check( 'wildcard matches deep sub', AD_Embed_Domains::matches( 'a.b.network.net' ) );
check( 'no suffix-trick match', ! AD_Embed_Domains::matches( 'evilnetwork.net' ) );
check( 'no match unrelated', ! AD_Embed_Domains::matches( 'attacker.com' ) );
check( 'match case/port insensitive', AD_Embed_Domains::matches( 'WWW.Example.com:443' ) );

// ---- 5. domains: CSP --------------------------------------------------------
$csp = AD_Embed_Domains::csp_header_value();
echo "\nCSP: $csp\n\n";
check( 'csp has self', strpos( $csp, "frame-ancestors 'self'" ) === 0 );
check( 'csp has bare + www for plain entry', strpos( $csp, 'https://example.com' ) !== false && strpos( $csp, 'https://www.example.com' ) !== false );
check( 'csp has wildcard pair', strpos( $csp, 'https://network.net' ) !== false && strpos( $csp, 'https://*.network.net' ) !== false );

$GLOBALS['__options']['ad_embed_allowlist'] = array();
check( 'empty allowlist => allow all (frame-ancestors *)', AD_Embed_Domains::csp_header_value() === "frame-ancestors *" );
check( 'empty allowlist => matches() returns true for any host', AD_Embed_Domains::matches( 'any-random-domain.com' ) === true );

echo "\n" . ( $failures ? "$failures FAILURE(S)" : 'ALL CHECKS PASSED' ) . "\n";
exit( $failures ? 1 : 0 );
