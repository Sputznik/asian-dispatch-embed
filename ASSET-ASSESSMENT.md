# Asset Assessment — Single Post on asiandispatch.net

**Reference post:** https://asiandispatch.net/west-bengals-new-chapter-can-bjp-bring-about-real-change/
**Fetched:** 2026-06-11 · 92,810 bytes · saved as `_sample-post.html` (theme CSS as `_theme-main.css`)
**Template variant:** `single-post-one` (from body class `post-template-single-post-one`)

## 1. Page anatomy

```
<head>            10 stylesheets, 15 inline <style> blocks, 3 external + 2 inline scripts
<body class="…">
  <header> + <nav>          ← site chrome, DROPPED in embed view
  <main> … </main>          ← ~23 KB; the only <main>; exactly one open/close tag ✓
  (site footer markup)      ← DROPPED in embed view
  <style id="siteorigin-panels-layouts-footer">   ← ⚠ REQUIRED, lives OUTSIDE <main>
  11 external + 4 inline <script>                 ← REQUIRED (footer-enqueued JS)
</body>
```

The single-`<main>` structure makes the planned extract-main strategy viable with a
simple first-open/last-close match. **Key finding:** layout-critical CSS and all
functional JS live in the body *after* `</main>` — the embed transform must carry
body-level `<style>` and `<script>` blocks, not scripts alone. (PLAN.md §4.1 amended.)

## 2. Stylesheets (head), by origin

| Origin | Files |
|--------|-------|
| Theme `asian-dispatch-theme` | `css/main.css` (30 KB — includes Google Fonts `@import`s) |
| SiteOrigin Panels | `front-flex.min.css` |
| SiteOrigin Widgets Bundle | `icons/fontawesome/style.css` |
| SiteOrigin generated (uploads) | `so-css-asian-dispatch-theme.css`, `sow-image-default-*.css`, `sow-social-media-buttons-flat-*.css` |
| Plugin `orbit-bundle` 2.4.0 | `main.css`, `common.css` |
| Plugin `sputznik-siteorigin-widgets` | `sow.css` |
| CDN (cdnjs) | Font Awesome 4.7.0 |

## 3. Inline `<style>` blocks

- **Head (15):** WP core block styles (`wp-block-*`), `global-styles-inline-css`,
  emoji styles, one unnamed theme/plugin block. All preserved automatically by
  keeping the original head.
- **Footer (1):** `siteorigin-panels-layouts-footer` — the per-post SiteOrigin
  grid/row layout CSS. Without it the post body loses its column layout.
  **Must be carried into the embed view.**

## 4. Scripts

**Head:** jQuery 3.7.1 + jquery-migrate (render-blocking, keep order), GA4 gtag
loader (`G-6CLDQEJTCG`). Inline: Yoast/schema JSON-LD, gtag init.

**Footer external (11), load order matters:**
orbit-bundle `common.js`, `orbit-query.js`, `orbit-slides.js`;
sputznik widgets `sow.js`, `odometer.js`, `honeycomb-user-popup.js`, `typed.js`
(+ typed.js 2.0.12 from jsDelivr); theme `js/main.js`;
SiteOrigin Premium `lightbox.min.js`; SiteOrigin Panels `styling.min.js`.

**Footer inline (4):**
| # | Content | Embed handling |
|---|---------|----------------|
| 0 | Speculation-rules prefetch JSON | **Strip** — prefetching site pages inside an iframe is waste |
| 1 | `var panelsStyles = {...}` | **Keep** — required by `styling.min.js` |
| 2–3 | WP emoji settings + loader | Keep (harmless) |

## 5. Fonts

- **Google Fonts via `@import` inside theme `main.css`:** Lora, Roboto Serif,
  Open Sans. Load automatically wherever the stylesheet loads → no extra work
  for the iframe. No preloads, no self-hosted `@font-face`.
- **Font Awesome 4.7** from cdnjs (used by share icons etc.).

## 6. Inside `<main>` (what the embed will show)

`.single-post-wrap > .single-post-container` containing:
`.single-post-header` (title, excerpt, category, **social share buttons**
(`asdp-social-share`: LinkedIn/WhatsApp/Facebook), post-meta with co-authors +
date, **language switcher** `asdp-languge-switcher`), featured image with
caption, post content (SiteOrigin/Gutenberg), and **`.post-footnote`** —
the footnote area is a theme element inside `<main>` (so the theme's footer
`do_action` goes right after it in the template).

Not present on single posts: related-posts module, comments, newsletter signup,
`position: sticky` elements (good — no sticky workaround needed).

## 7. Implications for the embed implementation

1. **Keep the entire original `<head>`** → all 10 stylesheets, 15 inline styles,
   fonts, jQuery arrive identically. Pixel parity is structural, not curated.
2. **Body transform = `<main>` + footer `<style>` blocks + footer `<script>`s**
   (external and inline, original order), minus the speculation-rules block.
3. **Site chrome between `<body>` and `<main>`** (header/nav) contains no
   styles/scripts — safe to drop wholesale.
4. **Variant-proof:** the body class carries the template variant
   (`post-template-single-post-one` here); since we keep the original body
   classes, variant-specific CSS keeps applying. Nothing template-specific to code.
5. **GA4 inside embeds:** keeping the head means embed views fire pageviews on
   the AD property, segmentable by the `ad_embed=1` query param — free embed
   analytics. Caveat: in a third-party iframe GA cookies may be partitioned, so
   user-level metrics are fuzzy; view counts still register. A filter will allow
   stripping gtag from embed views if preferred.
6. **In-embed modules to review with editorial:** social share buttons and the
   language switcher render inside embeds (probably desirable — share links use
   the canonical AD URL; the switcher navigates within the iframe). The planned
   `ad_embed_strip_selectors` filter can hide either with one line.
7. **Third-party CDNs** (cdnjs, jsDelivr) load fine in iframe context; no
   crossorigin/CORS issues since the iframe is a normal AD-origin document.
8. **Page weight per embed:** ~93 KB HTML + the asset stack — same as a normal
   AD page view, lazy-loaded via `loading="lazy"` on the iframe.

## 8. Verdict

The output-buffer + extract-`<main>` strategy in PLAN.md is **confirmed viable**
against the live site, with one amendment (carry footer `<style>` blocks, §7.2)
and one strip rule (speculation-rules JSON, §4). No blockers found.
