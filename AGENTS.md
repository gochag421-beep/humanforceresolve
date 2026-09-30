# AGENTS.md

## What this repo is

A **prebuilt, static** site (Greek staffing company, HUMAN FORCE). The source
Vite/React project is not in this repo — only the compiled output is committed:

- `index.html` — prerendered HTML for the home route (also `*/index.html` for other routes)
- `assets/index-<hash>.js` — the compiled React bundle (minified, single line)
- `assets/index-<hash>.css` — the compiled stylesheet
- `assets/*.webp`, `assets/*.jpeg` — images
- `api/` — PHP endpoints (`public.json`, `admin.php`); they need a PHP host, so they 404 under a plain static server

There is no `package.json`, no build step, and no test runner. Changes are made
by editing the committed output directly.

## Editing conventions

- **Keep HTML and the bundle in sync.** Anything rendered by React also exists in
  the prerendered `index.html`. Change both, or the prerendered page and the
  hydrated page will differ (hydration mismatch / flicker).
- The bundle is one minified line using template-literal class names:
  `` className:`story-track` ``. Match that style when patching.
- Custom CSS lives in `assets/hfs-3d.css` (hand-written, layered on top of
  `index-<hash>.css`) and is linked from `index.html`. Prefer adding/editing here
  over touching the compiled CSS.
- Custom behaviour lives in `assets/hfs-3d.js`, a plain IIFE. It is progressive
  enhancement: the page must stay usable with JS disabled.
- **Do not use the `hidden` attribute for JS-toggled visibility.** The compiled
  Tailwind preflight contains `@layer base` `[hidden]{display:none!important}`,
  and layer-`!important` beats unlayered-`!important`, so custom CSS cannot
  re-show it. Use a state class (e.g. `.is-current`) instead.

## Verifying a change

There is no test suite. Verification is done against the browser:

```bash
python3 -m http.server 12000 --directory /workspace/project   # serve the tree
/usr/bin/chromium --headless=new --remote-debugging-port=9222 --no-sandbox \
  --user-data-dir=/tmp/chrome-profile &
```

Then drive it over CDP (Node's built-in `ws` + `fetch` against
`http://127.0.0.1:9222/json/list`). Always check:

- `Runtime.exceptionThrown` / `Log.entryAdded` — page must log no errors
- `Emulation.setDeviceMetricsOverride` at 1440x900, 900x900, 390x844
- `Emulation.setEmulatedMedia` with `prefers-reduced-motion: reduce`
- `Network.setCacheDisabled` — the server serves these assets with caching, so
  without this you will read a stale stylesheet/script and chase ghosts
- `document.documentElement.scrollWidth > window.innerWidth` — horizontal overflow

## Gotchas

- `git rev-parse --is-shallow-repository` → this clone is shallow; use
  `git fetch --unshallow` before anything needing full history/blame.
- `assets/index-<hash>.css` is referenced *by filename* from `index.html`; if you
  ever rename a hashed asset, update the HTML link too.
- Prerendered pages for other routes live in their own directories
  (`construction/index.html`, `privacy/index.html`, …) and share the same bundle.
- Regenerating a prerendered page means: render the route in headless Chromium,
  take `#root`'s `outerHTML` plus the live `#hfs-page-jsonld` payload, and splice
  both into the static file while keeping the hand-written `<head>` meta.
- The site carries no delivery/transport offering any more. Keep it that way:
  `professional-drivers/` and `logistics-staffing/` are deleted, and the services
  array in the bundle now holds only construction, manufacturing, general workers
  and hospitality. `οδηγοί` survives only inside `εργοδηγοί` (construction
  foremen), which is intentional.
- The transport era also left a "Κατηγορία διπλώματος" dropdown in the candidate
  application form; it has been removed. Neither form should ask about a driving
  licence or a vehicle again.
- The `truck` icon in the bundle is a Lucide definition with zero call sites —
  dead code, not a feature.

## Images

- The optimised images sit at the repo root (`experts.webp`, `productivity.webp`)
  next to their JPEG originals, *not* under `assets/`.
- Both are wrapped in `<picture>` with the WebP `<source>` first and the JPEG
  `<img>` as fallback, in `index.html` and in the bundle. Keep the two in sync.

## The 3D storyline

- `assets/hfs-3d.js` drives `assets/hfs-3d.css`. The storyline is continuous:
  scroll sets a fractional base position and the pointer adds a rolling offset,
  so the three scenes cross-fade and moving the mouse scrubs the storyline.
  It writes `--active` on `.story-ring` and per-scene `opacity`/`visibility`.
- It is progressive enhancement. Without JS, or with `prefers-reduced-motion`,
  the scenes must fall back to stacked readable cards — the `html:not(.hfs-3d)`
  and reduced-motion blocks in the CSS do that. Do not break those.
- React may replace the prerendered markup, so the script re-queries its nodes
  through a `MutationObserver`; keep that re-binding when editing it.
