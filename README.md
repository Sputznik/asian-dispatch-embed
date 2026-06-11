# Asian Dispatch Embed

**Version:** 1.0.0 | **Requires WordPress:** 5.8+ | **Requires PHP:** 7.2+ | **License:** GPL-2.0-or-later

Lets contributors copy a script that embeds a post on allowlisted external websites, rendered identically to the single-post template.

---

## Description

Logged-in contributors (and above) see an "Embed this post" button on every published post (frontend only — no wp-admin access needed). The button copies a snippet that partner websites paste to embed the post:

```html
<div class="ad-embed" data-post="123" data-title="Post title"></div>
<script async src="https://asiandispatch.net/wp-content/plugins/asian-dispatch-embed/public/embed.js"></script>
```

The snippet renders the post in an auto-sizing iframe (no scrollbars) showing only the contents of the site's `<main>` element — no site header or footer — with the exact same theme styles, fonts, and layout as on asiandispatch.net, for all single-post template variants.

Embedding only works on domains an administrator has allowlisted under **Settings → AD Embed**. Enforcement is server-side via a `Content-Security-Policy: frame-ancestors` header — browsers refuse to render the embed on any other domain.

---

## Installation

1. Copy the `asian-dispatch-embed` folder to `wp-content/plugins/`
   > The `_sample-post.html`, `_theme-main.css`, `PLAN.md`, `ASSET-ASSESSMENT.md`, and `_test-harness.php` files are working/dev documents and can be excluded from production.
2. Activate the plugin in **Plugins → Installed Plugins**.
3. Add allowlisted partner domains under **Settings → AD Embed**.
4. Add the two button hooks to the theme (see below).

---

## Settings

**Settings → AD Embed** (administrators only). One domain per line:

| Entry | Matches |
|-------|---------|
| `example.com` | `example.com` and `www.example.com` |
| `news.example.com` | that exact subdomain only |
| `*.example.com` | `example.com` and every subdomain |

An empty list allows embedding on **any** domain. Add domains here to restrict embedding to those sites only. Saving the list automatically purges WP Rocket / W3 Total Cache / WP Super Cache / LiteSpeed Cache when present, so the security header updates immediately.

---

## Hooks for Developers

| Hook | Type | Description |
|------|------|-------------|
| `do_action( 'ad_embed_button', $location )` | action | Render the copy button. `$location` is `'header'` or `'footer'`. |
| `ad_embed_capability` | filter | Capability required to see the button. Default `edit_posts` (Contributor+). |
| `ad_embed_post_types` | filter | Embeddable post types. Default `['post']`. |
| `ad_embed_csp_sources` | filter | Adjust CSP `frame-ancestors` sources, e.g. add `http://localhost:3000` during partner development. |
| `ad_embed_strip_selectors` | filter | Array of CSS selectors hidden inside embed views, e.g. `['.asdp-social-share']`. |
| `ad_embed_snippet` | filter | Customize the copied snippet markup. |
| `ad_embed_allowlist_updated` | action | Fires after the allowlist is saved. Hook custom cache purges here. |

---

## How It Works

- **Loader** (`public/embed.js`) creates an iframe pointing at the post URL with `?ad_embed=1` and keeps its height synced to the content via origin-verified `postMessage` — no inner scrollbar, reflows responsively.
- **Embed view** keeps the original page `<head>` (all stylesheets, fonts, scripts) and body classes, outputs only the `<main>` element, and carries over footer styles/scripts (including SiteOrigin's per-post layout CSS) so all template variants render pixel-identical to the live site.
- **Security** is enforced by a `Content-Security-Policy: frame-ancestors` header built from the admin allowlist — browser-level, cannot be bypassed by editing the loader JS.
- **Embed views** send `X-Robots-Tag: noindex` and a robots `<meta>` tag so they are not indexed by search engines.
- A public REST route (`/wp-json/ad-embed/v1/check`) lets the loader show a friendly notice on non-allowlisted domains; actual enforcement is always the CSP header.

---

## Changelog

### 1.0.0
- Initial release.
