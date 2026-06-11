# Asian Dispatch Embed — Implementation Plan

**Plugin slug:** `asian-dispatch-embed`
**Purpose:** Allow logged-in contributors (and above) to copy an embed script from any single post on asiandispatch.net and place that post — rendered identically to the site's single-post template — on their own (allowlisted) website.

**Date:** 2026-06-11
**Status:** Plan approved decisions baked in; implementation not started.

---

## 1. Confirmed decisions

| # | Question | Decision |
|---|----------|----------|
| 1 | Embed mechanism | **Iframe** via loader script (Option A) |
| 2 | Theme | Custom theme; SiteOrigin page builder + Gutenberg blocks |
| 3 | Button placement | Theme will add `do_action()` hooks in the single-post template(s) |
| 4 | Footer button position | Theme dev places the footer hook after the footnote area |
| 5 | Settings UI | Standalone plugin settings page (admin-only) |
| 6 | Domain matching | Exact hostnames **and** wildcards (`*.example.com`) |
| 7 | Who sees the button | Contributor role **and above** (capability: `edit_posts`, filterable) |
| 8 | Post types | Posts only (for now; filterable for later) |
| 9 | Scrolling | Iframe must auto-size to content — **no inner scrollbar** |
| 10 | Usage tracking | Not requested (see §10, deferred) |

Reference post for asset assessment:
`https://asiandispatch.net/west-bengals-new-chapter-can-bjp-bring-about-real-change/`

---

## 2. Architecture overview

```
Partner website (allowlisted domain)
┌──────────────────────────────────────────────┐
│  <div class="ad-embed" data-post="1234">     │
│  <script async src="https://asiandispatch    │
│    .net/.../embed.js">                       │
│        │                                     │
│        ▼  loader creates iframe              │
│  ┌────────────────────────────────────────┐  │
│  │ iframe → asiandispatch.net post URL    │  │
│  │          + ?ad_embed=1                 │  │
│  │  (renders <main> content only, with    │  │
│  │   all theme/plugin assets, on AD's     │  │
│  │   own origin)                          │  │
│  └────────────────────────────────────────┘  │
│        ▲                                     │
│        └─ postMessage height-sync            │
│           (no scrollbars, responsive)        │
└──────────────────────────────────────────────┘
```

Security model: the embed view sends a `Content-Security-Policy: frame-ancestors` header
built from the admin-managed domain allowlist. Browsers refuse to render the iframe on
any non-allowlisted domain. This is server-enforced and cannot be bypassed by editing
the loader JS. The JS-side domain check exists only to show a friendly error message.

---

## 3. Plugin file structure

```
asian-dispatch-embed/
├── asian-dispatch-embed.php      Main plugin file: header, constants, bootstrap
├── PLAN.md                       This document
├── readme.txt                    WP-style readme + theme integration instructions
├── includes/
│   ├── class-ad-embed-plugin.php       Singleton bootstrap, hook wiring
│   ├── class-ad-embed-settings.php     Settings page + allowlist storage/sanitization
│   ├── class-ad-embed-endpoint.php     Embed view: query var, headers, HTML transform
│   ├── class-ad-embed-button.php       Copy-button rendering + capability checks
│   └── class-ad-embed-domains.php      Domain normalization, wildcard matching, CSP builder
├── public/
│   ├── embed.js                  Loader script copied by contributors (static, no build step)
│   └── embed-resize.js           Height-reporting script injected into the embed view
└── assets/
    ├── button.css                Copy-button + popover styles (frontend, gated)
    └── button.js                 Click-to-copy + popover behavior (frontend, gated)
```

No build tooling — plain PHP/JS/CSS so it deploys by copying the folder.

---

## 4. Component details

### 4.1 Embed endpoint (`class-ad-embed-endpoint.php`)

- **URL shape:** the post's normal permalink + `?ad_embed=1`.
  Registered as a public query var (`ad_embed`). We deliberately avoid WordPress's
  native `/embed/` oEmbed endpoint to prevent conflicts.
- **Canonical redirect:** `redirect_canonical` is bypassed when `ad_embed=1` so the
  query var survives.
- **Eligibility:** only `is_singular('post')`, post status `publish`, not
  password-protected. Anything else → 404. Post type list filterable
  (`ad_embed_post_types`).
- **Rendering strategy — output-buffer transform (theme-agnostic):**
  1. On `template_redirect`, when `ad_embed=1`, start an output buffer over the
     theme's normal template render. The theme renders the post exactly as it
     normally would — whichever of the 3 single-post variants applies, SiteOrigin
     layouts, Gutenberg blocks, footnotes, everything.
  2. The buffer callback rebuilds the document:
     - Keep the original `<head>…</head>` intact (all enqueued CSS/JS, fonts,
       inline styles — this is what guarantees pixel-identical rendering).
     - Keep the original `<body>` tag and its classes, plus an extra
       `ad-embed-view` class.
     - Body content = **only the first `<main>…</main>` element** (site header,
       nav, sidebars, site footer are dropped).
     - Re-append every `<script>` **and `<style>`** block that lived in `<body>`
       outside `<main>`, in original order (footer-enqueued scripts, inline
       config like SiteOrigin's `panelsStyles`, and the layout-critical
       `siteorigin-panels-layouts-footer` style block — see ASSET-ASSESSMENT.md).
       Exception: the speculation-rules prefetch JSON block is stripped.
  3. Extraction uses tolerant regex over the final HTML (DOMDocument chokes on
     HTML5); first-`<main>`-open to last-`</main>`-close.
- **Embed-view adjustments injected into `<head>`:**
  - `<meta name="robots" content="noindex">` (don't index the embed variant).
  - Small CSS: `html, body { overflow: hidden; background: transparent; }` and a
    hook class (`.ad-embed-view`) the theme can target for fine-tuning.
  - `position: sticky` elements inside the post are neutralized (sticky cannot
    work inside a full-height iframe).
  - Admin bar suppressed (`show_admin_bar` → false) on embed views.
- **Headers sent:**
  - `Content-Security-Policy: frame-ancestors 'self' <allowlisted origins>`
    (built by the domains class; wildcards map to `https://*.example.com`).
  - When the allowlist is empty, only `'self'` is sent — embeds are effectively
    disabled externally rather than open to everyone.
- **Height reporting:** `embed-resize.js` is injected before `</body>`. It uses
  `ResizeObserver` on `document.documentElement` (catching image loads, font
  swaps, accordion toggles) and posts `{ type: 'ad-embed-height', height, post }`
  to the parent. `targetOrigin: '*'` is acceptable here (height is not sensitive);
  the **loader** verifies `event.origin` strictly.
- **Caching:** the embed view is a normal GET and page-cache friendly. Saving the
  allowlist fires `ad_embed_allowlist_updated` so cache purges can hook in
  (and we call common purge functions if present — WP Rocket, W3TC, LiteSpeed).

### 4.2 Loader script (`public/embed.js`)

The static file contributors' snippet points at. No PHP rendering — origin is
derived at runtime from `document.currentScript.src`, so the same file works on
staging and production.

Behavior:
1. Find all `div.ad-embed[data-post]` not yet initialized (supports multiple
   embeds per page; idempotent if the script is included twice).
2. For each, create `<iframe>`:
   - `src = {origin}/?p={postId}&ad_embed=1` (WP resolves `?p=` to the post;
     canonical redirect preserves `ad_embed`, and the endpoint also accepts the
     pretty-permalink form).
   - `width: 100%`, `border: 0`, `scrolling="no"`, sensible `min-height`
     skeleton until the first height message arrives (avoids a flash of
     collapsed/oversized frame).
   - `loading="lazy"`, descriptive `title` attribute for accessibility.
3. Listen for `message` events; **strictly verify `event.origin` equals the
   Asian Dispatch origin** before applying `height` to the matching iframe.
   Height updates apply continuously → responsive reflow on window resize /
   device rotation, never an inner scrollbar.
4. Friendly failure: the loader fetches a tiny public REST route
   (`GET /wp-json/ad-embed/v1/check?host={hostname}`) and, if the host is not
   allowlisted, replaces the placeholder with a short "This domain is not
   authorized to embed Asian Dispatch content" notice instead of a silently
   blank CSP-blocked iframe. (Enforcement remains the CSP header; this is UX.)

### 4.3 Copied snippet (what contributors get)

```html
<div class="ad-embed" data-post="1234" data-title="Post title here"></div>
<script async src="https://asiandispatch.net/wp-content/plugins/asian-dispatch-embed/public/embed.js"></script>
```

- `data-title` is informational (helps partners see what they pasted).
- Snippet is generated server-side per post, shown in the copy popover.

### 4.4 Copy button (`class-ad-embed-button.php` + `assets/button.*`)

- **Theme integration — the theme adds, in its single-post template(s):**
  ```php
  <?php do_action( 'ad_embed_button', 'header' ); ?>   // in the post header area
  <?php do_action( 'ad_embed_button', 'footer' ); ?>   // after the footnote area
  ```
  One action, location argument; the plugin renders the same control in both
  spots (location lands in a CSS class for styling differences).
- **Visibility:** rendered only when **all** are true:
  - `is_singular('post')`, post is published and not password-protected;
  - user is logged in and `current_user_can('edit_posts')`
    (Contributor and above; capability filterable via `ad_embed_capability`).
  - Logged-out visitors and lower roles: the action renders nothing at all.
- **UI:** a compact "Embed this post" button. Click opens a small popover
  containing the snippet in a readonly `<textarea>` + a **Copy** button
  (`navigator.clipboard.writeText` with `document.execCommand('copy')`
  fallback) + brief "where to paste this" hint + note that it only works on
  allowlisted domains. "Copied ✓" confirmation state.
- **Assets:** `button.css`/`button.js` are enqueued **only** when the button will
  actually render (singular post + capability), so zero overhead for normal
  visitors.

### 4.5 Settings page (`class-ad-embed-settings.php`)

- Location: **Settings → AD Embed** (standalone page, Settings API).
- Capability: `manage_options` — administrators only, per requirement.
- Fields:
  - **Allowlisted domains** — textarea, one entry per line. Accepted forms:
    `example.com`, `sub.example.com`, `*.example.com`. Scheme/path/port are
    stripped on save; entries lowercased; invalid lines rejected with an
    admin notice naming the bad line.
  - Read-only helper section: current CSP header preview + a copy of the
    snippet format, so admins can sanity-check what's being enforced.
- Storage: single option `ad_embed_allowlist` (array of normalized entries).
- `example.com` matches the bare domain **and** `www.example.com` (explicit
  convenience rule, documented on the page); `*.example.com` matches any
  subdomain plus the bare domain.

### 4.6 Domain logic (`class-ad-embed-domains.php`)

- Normalization (strip scheme/path/port, lowercase, punycode via
  `idn_to_ascii` when available).
- `matches( $hostname )` — used by the REST check route.
- `csp_sources()` — maps entries to CSP origins:
  - `example.com` → `https://example.com https://www.example.com`
  - `*.example.com` → `https://example.com https://*.example.com`
  - HTTPS-only by default; `ad_embed_csp_sources` filter for edge cases
    (e.g. allowing `http://localhost:3000` during partner development).

---

## 5. Security summary

| Layer | Mechanism | Bypassable? |
|-------|-----------|-------------|
| Primary | `frame-ancestors` CSP header on the embed view | No — browser-enforced |
| UX | Loader REST check → friendly error on non-allowlisted hosts | Yes (cosmetic only) |
| Content | Only published, non-password posts; `noindex` on embed view | — |
| Button | Capability check `edit_posts`; nothing rendered otherwise | — |
| postMessage | Loader verifies `event.origin` before acting | — |
| Settings | `manage_options`, nonces, sanitized input | — |

Notes:
- The embed view itself is intentionally **public** (the posts are public);
  the allowlist controls *framing*, not *reading*.
- No personal data is processed; no cookies are required on the partner site
  (iframe is third-party there — see §9 caveats).

---

## 6. Asset assessment (pre-implementation step)

Goal: verify the output-buffer strategy preserves everything a single post needs.

1. Fetch the reference post HTML (server-side fetch of
   `https://asiandispatch.net/west-bengals-new-chapter-can-bjp-bring-about-real-change/`).
2. Inventory, grouped by origin (theme / SiteOrigin / each plugin / core):
   - `<link rel="stylesheet">` handles and inline `<style>` blocks in head;
   - `<script>` in head vs. body, inline vs. external;
   - fonts (Google Fonts / self-hosted), preloads.
3. Identify the `<main>` element boundaries and the footnote markup (to confirm
   extraction works and to document where the theme should place the footer hook).
4. Record findings in `ASSET-ASSESSMENT.md` in this folder.

Because the iframe renders on asiandispatch.net's own origin with the original
`<head>`, the assessment is **verification**, not a porting exercise — nothing
needs to be re-hosted on partner sites.

Also captured in the assessment: anything in `<main>` that should be stripped or
adjusted in embed context (e.g. related-posts modules, share bars, sticky
elements), to be handled via the injected embed CSS or an exclusion filter
(`ad_embed_strip_selectors`).

---

## 7. Implementation order

1. **Asset assessment** — fetch + analyze the reference post; write
   `ASSET-ASSESSMENT.md`. Confirms `<main>` extraction is viable before any code.
2. **Plugin skeleton** — main file, constants, activation (option defaults),
   uninstall cleanup.
3. **Domains + settings** — allowlist storage, sanitization, wildcard matching,
   CSP builder, settings page.
4. **Embed endpoint** — query var, output-buffer transform, CSP header,
   resize script injection, REST check route.
5. **Loader** — `embed.js` with multi-embed support, origin-verified height
   sync, friendly error state.
6. **Copy button** — `ad_embed_button` action, capability gating, popover,
   click-to-copy, gated asset enqueue.
7. **Docs** — `readme.txt`: theme-integration snippet for the dev (where to put
   the two `do_action` calls), admin instructions, partner-facing "how to embed"
   blurb.
8. **Verification pass** — against a local/staging WP if available, otherwise a
   static harness: simulate partner page with the loader, confirm height sync,
   CSP behavior, wildcard matching, copy flow, and that all 3 single-post
   variants extract cleanly.

---

## 8. Hooks & filters exposed (for the theme / future needs)

| Hook | Type | Purpose |
|------|------|---------|
| `ad_embed_button` | action | Theme placement of the copy button (`'header'` / `'footer'` arg) |
| `ad_embed_capability` | filter | Change who sees the button (default `edit_posts`) |
| `ad_embed_post_types` | filter | Extend beyond `post` later |
| `ad_embed_csp_sources` | filter | Adjust CSP origins (e.g. dev hosts) |
| `ad_embed_strip_selectors` | filter | CSS selectors to hide inside embed view |
| `ad_embed_snippet` | filter | Customize the copied snippet markup |
| `ad_embed_allowlist_updated` | action | Cache purge integration point |

---

## 9. Known caveats (accepted with Option A)

- **SEO:** embedded content lives in an iframe — partner sites get no
  syndication SEO value. Confirmed acceptable.
- **Sticky elements** inside the post can't stick within the iframe; they are
  flattened via embed CSS.
- **Initial height flash:** mitigated with a min-height skeleton until the
  first height message (typically < 100 ms after load).
- **Third-party iframe context:** if any in-post feature depends on cookies
  (e.g. logged-in comment forms), browsers may block third-party cookies inside
  the iframe on partner sites. Plain article content is unaffected.
- **CSP merge:** if the site (or a security plugin) already sends a
  `Content-Security-Policy` header, the endpoint must merge/replace carefully —
  checked during implementation.

---

## 10. Explicitly deferred (not in v1)

- Usage tracking / analytics (which contributor copied what; which domains
  render embeds).
- Post types beyond `post`.
- Per-post or per-contributor embed permissions.
- AMP/feed variants of the embed.
