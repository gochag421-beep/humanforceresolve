# Human Force Solutions Group

Static website for **Human Force Solutions** — an all-in-one staffing agency.
Rebuilt from the ChatGPT Site project `human-force-solutions`.

## Interactive 3D hero

The hero renders a real-time WebGL scene with two switchable subjects:

- **Embossed gold coin** — lathe-free construction from a cylinder plus beveled rims,
  gear emblem, spokes and stud ring. Both faces carry a procedurally drawn
  **neon circuit-board** canvas texture used as an emissive map.
- **Next-gen controller** — shell, grips, analog sticks with glowing wells, d-pad,
  colour-coded face buttons and a status bar, all with neon emissive accents.

Both subjects float and rotate a full 360° on a pivot group. The scene uses a
`PMREMGenerator` studio environment (coloured area lights around a dark dome) so the
gold has something real to reflect, with ACES tone mapping applied.

### Controls

| Input | Action |
|---|---|
| Drag (mouse or touch) | Rotate the model, with momentum on release |
| Button "Δες τον controller" / "Δες το χρυσό νόμισμα" | Switch between coin and controller |
| Button "Περιστροφή: Ναι/Όχι" | Toggle the idle auto-rotation |
| Arrow keys | Rotate in 15° steps (canvas is focusable) |
| Space / Enter | Switch subject |

Hovering the canvas without dragging adds a gentle parallax, so the model feels alive
even before the user interacts. `prefers-reduced-motion` disables drift, bob, glow
pulsing, auto-rotation and the ambient background animation.

### Resilience

If WebGL or the three.js CDN module is unavailable, `useFallback()` swaps in a
pure-CSS gradient coin so the hero is never empty. The canvas is kept strictly square
(`aspect-ratio: 1/1` plus a locked camera aspect) at every breakpoint so the sphere
never stretches on mobile.

## Content sections

Hero, quote from Nicko Anta (Internal Affairs), six service cards (Construction,
Manufacturing, Professional Drivers, Logistics Staffing, General Workers, Hospitality
Workers), "Rise to the top / Increase Productivity", candidate pool, and employer CTA.

## Run locally

```bash
python3 -m http.server 12000
# open http://127.0.0.1:12000/
```

A local server is recommended because `script.js` is an ES module.

## Candidate registration form

`#register` holds a validated form (name, phone, email, role, area, experience,
message, consent). Submissions are POSTed to FormSubmit, which relays them to
`contact@humanforcesolutions.com`.

### One-time activation (required)

1. Submit the form once on the live site.
2. FormSubmit emails `contact@humanforcesolutions.com` an **Activate Form** link.
3. Click it. Every submission after that lands in the inbox.

Until step 3 the relay replies `HTTP 200` with `{"success":"false"}`. The form
checks that body rather than the status code, so it will never claim a delivery
that did not happen: it falls back to opening the visitor's mail client with the
answers pre-filled and keeps what was typed.

To use a different provider, change `FORM_ENDPOINT` in `form.js` to a
Formspree/Web3Forms URL.

### Behaviour

| Situation | What happens |
|---|---|
| Missing or invalid field | Inline message under the field, focus jumps to the first problem |
| Relay confirms success | Green confirmation, form resets |
| Relay refuses or is unreachable | Amber notice, mail client opens pre-filled, answers are kept |
| Hidden honeypot field filled | Silently accepted-looking response, no network call |

`form.js` is a plain script loaded with `defer` and kept out of `script.js`, so
the form still works when WebGL is unavailable.

## Contact

- +30 2107499385
- contact@humanforcesolutions.com

## GitHub Pages

Deployment uses `.github/workflows/pages.yml`. Enable Pages from
**repository Settings → Pages** and set the source to **GitHub Actions**; the workflow
then publishes the site root on every push to `main`.

Live at: https://gochag421-beep.github.io/humanforceresolve/
