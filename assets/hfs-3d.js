/* HUMAN FORCE — scroll-driven 3D storyline + pointer parallax.
   Progressive enhancement over the prerendered markup: nothing here is
   required for the page to be readable. */
(function () {
  "use strict";

  var doc = document.documentElement;
  if (!window.matchMedia || !window.CSS || !CSS.supports || !CSS.supports("transform-style", "preserve-3d")) {
    return;
  }

  doc.classList.add("hfs-3d");

  var clamp = function (v, min, max) { return Math.min(max, Math.max(min, v)); };

  var state = { targets: [], track: null, ring: null, scenes: [], copy: [], rail: [] };
  var pointer = { x: 0, y: 0, cx: 0, cy: 0 };
  var pointerActive = false;
  var looping = false;

  /* ------------------------------------------------------------ *
   * Pointer parallax (hero scene + storyline stage)
   * ------------------------------------------------------------ */
  function frame() {
    pointer.cx += (pointer.x - pointer.cx) * 0.09;
    pointer.cy += (pointer.y - pointer.cy) * 0.09;

    var t, i;
    for (i = 0; i < state.targets.length; i++) {
      t = state.targets[i];
      t.el.style.setProperty("--px", (pointer.cx * t.depth).toFixed(2) + "px");
      t.el.style.setProperty("--py", (pointer.cy * t.depth).toFixed(2) + "px");
      if (t.tilt) {
        t.el.style.setProperty("--rx", (6 - pointer.cy * t.tilt).toFixed(2) + "deg");
        t.el.style.setProperty("--ry", (pointer.cx * t.tilt).toFixed(2) + "deg");
      }
    }

    if (Math.abs(pointer.x - pointer.cx) > 0.001 || Math.abs(pointer.y - pointer.cy) > 0.001) {
      requestAnimationFrame(frame);
    } else {
      looping = false;
    }
  }

  function kick() {
    if (!looping) {
      looping = true;
      requestAnimationFrame(frame);
    }
  }

  window.addEventListener("pointermove", function (e) {
    if (e.pointerType === "touch") return;
    pointerActive = true;
    pointer.x = (e.clientX / window.innerWidth) * 2 - 1;
    pointer.y = (e.clientY / window.innerHeight) * 2 - 1;
    kick();
  }, { passive: true });

  window.addEventListener("deviceorientation", function (e) {
    if (pointerActive || e.gamma == null || e.beta == null) return;
    pointer.x = clamp(e.gamma / 35, -1, 1);
    pointer.y = clamp((e.beta - 45) / 35, -1, 1);
    kick();
  }, { passive: true });

  window.addEventListener("blur", function () { pointer.x = 0; pointer.y = 0; kick(); });

  /* ------------------------------------------------------------ *
   * Scroll storyline
   * ------------------------------------------------------------ */
  var active = -1;

  function apply() {
    var track = state.track;
    if (!track || !track.isConnected || state.scenes.length < 2) return;

    var rect = track.getBoundingClientRect();
    var span = rect.height - window.innerHeight;
    var raw = span > 0 ? clamp(-rect.top / span, 0, 1) : 0;
    var pos = raw * state.scenes.length;
    var index = clamp(Math.floor(pos), 0, state.scenes.length - 1);

    if (index !== active) {
      active = index;
      var i;
      for (i = 0; i < state.scenes.length; i++) {
        state.scenes[i].classList.toggle("is-active", i === index);
      }
      for (i = 0; i < state.copy.length; i++) {
        state.copy[i].classList.toggle("is-current", i === index);
      }
      for (i = 0; i < state.rail.length; i++) {
        state.rail[i].classList.toggle("is-on", i <= index);
      }
      if (state.ring) state.ring.style.setProperty("--active", index);
    }
  }

  var ticking = false;
  function onScroll() {
    if (ticking) return;
    ticking = true;
    requestAnimationFrame(function () { ticking = false; apply(); });
  }

  window.addEventListener("scroll", onScroll, { passive: true });
  window.addEventListener("resize", onScroll, { passive: true });

  /* ------------------------------------------------------------ *
   * Binding. React may replace the prerendered markup, so re-query
   * whenever the bound nodes leave the document.
   * ------------------------------------------------------------ */
  function bind() {
    state.targets = [
      { el: document.querySelector(".delivery-scene"), depth: 13, tilt: 5 },
      { el: document.querySelector(".hero-visual .orbit-text"), depth: 7, tilt: 0 },
      { el: document.querySelector(".hero-visual .top-card"), depth: -20, tilt: 7 },
      { el: document.querySelector(".hero-visual .bottom-card"), depth: 24, tilt: -7 },
      { el: document.querySelector(".story-stage"), depth: 0, tilt: 7 }
    ].filter(function (t) { return t.el; });

    state.track = document.querySelector("[data-storyline]");
    state.ring = state.track ? state.track.querySelector(".story-ring") : null;
    state.scenes = state.track ? [].slice.call(state.track.querySelectorAll(".story-scene")) : [];
    state.copy = state.track ? [].slice.call(state.track.querySelectorAll("[data-story-copy]")) : [];
    state.rail = state.track ? [].slice.call(state.track.querySelectorAll(".story-rail span")) : [];

    active = -1;
    apply();
  }

  function scheduleBind() {
    requestAnimationFrame(function () {
      if (!state.track || !state.track.isConnected) bind();
      else apply();
    });
  }

  function init() {
    bind();
    if (window.MutationObserver) {
      new MutationObserver(scheduleBind).observe(document.body, { childList: true, subtree: true });
    }
    window.addEventListener("load", scheduleBind);
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", init);
  } else {
    init();
  }
})();
