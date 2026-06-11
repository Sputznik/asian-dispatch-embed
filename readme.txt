=== Asian Dispatch Embed ===
Contributors: asiandispatch
Requires at least: 5.8
Tested up to: 6.8
Requires PHP: 7.2
Stable tag: 1.0.0
License: GPL-2.0-or-later

Lets contributors copy a script that embeds a post on allowlisted external
websites, rendered identically to the single-post template.

== Description ==

Logged-in contributors (and above) see an "Embed this post" button on every
published post (frontend only — no wp-admin access needed). The button copies
a snippet that partner websites paste to embed the post:

    <div class="ad-embed" data-post="123" data-title="Post title"></div>
    <script async src="https://asiandispatch.net/wp-content/plugins/asian-dispatch-embed/public/embed.js"></script>

The snippet renders the post in an auto-sizing iframe (no scrollbars) showing
only the contents of the site's <main> element — no site header or footer —
with the exact same theme styles, fonts, and layout as on asiandispatch.net,
for all single-post template variants.

Embedding only works on domains an administrator has allowlisted
(Settings → AD Embed). Enforcement is server-side via a
Content-Security-Policy frame-ancestors header — browsers refuse to render
the embed on any other domain.

== Installation ==

1. Copy the `asian-dispatch-embed` folder to `wp-content/plugins/`
   (the `_sample-post.html`, `_theme-main.css`, `PLAN.md` and
   `ASSET-ASSESSMENT.md` files are working documents and can be excluded).
2. Activate the plugin.
3. Add allowlisted partner domains under Settings → AD Embed.
4. Add the two button hooks to the theme (next section).

== Theme integration ==

The theme controls where the button appears. In each single-post template
variant, add:

In the post header area:

    <?php do_action( 'ad_embed_button', 'header' ); ?>

After the footnote area (`.post-footnote`):

    <?php do_action( 'ad_embed_button', 'footer' ); ?>

The action renders nothing for logged-out visitors, users below Contributor,
non-post content, or inside embed views — it is always safe to call.

== Settings ==

Settings → AD Embed (administrators only). One domain per line:

* `example.com` — matches example.com and www.example.com
* `news.example.com` — matches that exact subdomain only
* `*.example.com` — matches example.com and every subdomain

An empty list disables embedding on all external sites. Saving the list
purges WP Rocket / W3 Total Cache / WP Super Cache / LiteSpeed Cache when
present, so the security header updates immediately.

== Hooks for developers ==

* `do_action( 'ad_embed_button', $location )` — render the copy button
  ('header' or 'footer').
* `ad_embed_capability` (filter) — capability required to see the button.
  Default `edit_posts` (Contributor and above).
* `ad_embed_post_types` (filter) — embeddable post types. Default `['post']`.
* `ad_embed_csp_sources` (filter) — adjust CSP frame-ancestors sources,
  e.g. add `http://localhost:3000` while a partner develops.
* `ad_embed_strip_selectors` (filter) — array of CSS selectors hidden inside
  embed views (e.g. `['.asdp-social-share']`).
* `ad_embed_snippet` (filter) — customize the copied snippet markup.
* `do_action( 'ad_embed_allowlist_updated' )` — fires after the allowlist
  is saved; hook custom cache purges here.

== How it works ==

* The snippet's loader (`public/embed.js`) creates an iframe pointing at the
  post URL with `?ad_embed=1` and keeps its height synced to the content via
  postMessage (origin-verified), so the embed never shows an inner scrollbar.
* The embed view keeps the original page `<head>` and body classes, outputs
  only the `<main>` element, and carries over footer styles/scripts
  (including SiteOrigin's per-post layout CSS) so all template variants
  render pixel-identical.
* Embed views send `X-Robots-Tag: noindex` and a robots meta tag; they are
  not indexed.
* A public REST route (`/wp-json/ad-embed/v1/check`) lets the loader show a
  friendly notice on non-allowlisted domains; actual enforcement is the CSP
  header.

== Changelog ==

= 1.0.0 =
* Initial release.
