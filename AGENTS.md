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
  (`delivery/index.html`, `privacy/index.html`, …) and share the same bundle.
