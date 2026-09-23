# Human Force Solutions Group

Static website reconstruction based on the ChatGPT Site project **human-force-solutions**.

## Hero: interactive 3D iridescent sphere

The hero renders a liquid-metal sphere on a WebGL canvas (`#scene`). It is drawn as a
heavily subdivided plane displaced by an FBM noise field, so the surface continuously
changes shape. A pointer-driven bulge is added to the displacement, and the fragment
stage samples a small procedural studio environment along the reflection vector to get
the iridescent, light-reflecting look. Moving the cursor moves both the bulge and the
camera-facing rotation.

Implementation notes:

- `three.js` is loaded as an ES module from jsDelivr. The whole scene is a single
  `ShaderMaterial`; no textures or external assets are used.
- Vertex positions outside the unit disc are collapsed onto its rim (`clampDisc`), which
  keeps the silhouette perfectly round while the interior stays liquid.
- If WebGL or the CDN module is unavailable, `useFallback()` swaps in a pure-CSS
  conic-gradient orb so the page never renders an empty hero.
- The `prefers-reduced-motion` media query disables ambient animation.

## Run locally

```bash
python3 -m http.server 12000
# then open http://127.0.0.1:12000/
```

Opening `index.html` directly from the filesystem also works, but a local server is
recommended because the script is an ES module.

## Contact

- +30 2107499385
- contact@humanforcesolutions.com

## GitHub Pages

Deployment uses `.github/workflows/pages.yml`. Enable Pages from
**repository Settings → Pages** and set the source to **GitHub Actions**; the workflow
then publishes the site root on every push to `main`.

Live at: https://gochag421-beep.github.io/humanforceresolve/
