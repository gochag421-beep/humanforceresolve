const stage = document.getElementById('stage');
const canvas = document.getElementById('scene');
const fallback = document.getElementById('stageFallback');
const stageUI = document.getElementById('stageUI');

const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

function useFallback(reason) {
  console.warn('Falling back to CSS orb:', reason);
  canvas.remove();
  fallback.hidden = false;
  stage.classList.add('ready');
}

let THREE = null;
try {
  THREE = await import('https://cdn.jsdelivr.net/npm/three@0.160.0/build/three.module.js');
} catch (err) {
  useFallback(err);
}

if (THREE) {
  try {
    boot(THREE);
  } catch (err) {
    useFallback(err);
  }
}

/* -------------------------------------------------------------------------- */
/*  helpers                                                                    */
/* -------------------------------------------------------------------------- */

function makeRng(seed) {
  let s = seed >>> 0;
  return () => {
    s = (s * 1664525 + 1013904223) >>> 0;
    return s / 4294967296;
  };
}

// Neon circuit-board texture, drawn once and reused as an emissive map.
function circuitTexture(THREE, seed, palette) {
  const size = 1024;
  const c = document.createElement('canvas');
  c.width = c.height = size;
  const g = c.getContext('2d');
  const rand = makeRng(seed);

  g.fillStyle = '#000';
  g.fillRect(0, 0, size, size);

  const cx = size / 2;
  g.lineCap = 'round';
  g.lineJoin = 'round';

  // concentric rings
  for (let i = 0; i < 4; i++) {
    const c2 = palette[i % palette.length];
    g.strokeStyle = c2;
    g.shadowColor = c2;
    g.shadowBlur = 26;
    g.lineWidth = 5;
    g.beginPath();
    g.arc(cx, cx, size * (0.24 + i * 0.075), 0, Math.PI * 2);
    g.stroke();
  }

  // orthogonal traces with vias
  for (let i = 0; i < 46; i++) {
    const col = palette[(i * 3) % palette.length];
    g.strokeStyle = col;
    g.shadowColor = col;
    g.shadowBlur = 18;
    g.lineWidth = 4;

    let x = size * (0.06 + rand() * 0.88);
    let y = size * (0.06 + rand() * 0.88);
    const path = [[x, y]];
    let horiz = rand() < 0.5;
    const steps = 2 + Math.floor(rand() * 3);
    for (let s = 0; s < steps; s++) {
      const len = size * (0.04 + rand() * 0.16) * (rand() < 0.5 ? -1 : 1);
      if (horiz) x += len; else y += len;
      x = Math.min(size * 0.97, Math.max(size * 0.03, x));
      y = Math.min(size * 0.97, Math.max(size * 0.03, y));
      path.push([x, y]);
      horiz = !horiz;
    }

    g.beginPath();
    g.moveTo(path[0][0], path[0][1]);
    for (let p = 1; p < path.length; p++) g.lineTo(path[p][0], path[p][1]);
    g.stroke();

    for (const [px, py] of path) {
      g.fillStyle = col;
      g.shadowBlur = 22;
      g.beginPath();
      g.arc(px, py, 7, 0, Math.PI * 2);
      g.fill();
    }
  }

  // outer segmented dashed ring
  g.shadowBlur = 24;
  for (let i = 0; i < 72; i++) {
    const a0 = (i / 72) * Math.PI * 2;
    const a1 = a0 + (Math.PI * 2) / 72 * 0.45;
    const col = palette[i % palette.length];
    g.strokeStyle = col;
    g.shadowColor = col;
    g.lineWidth = 7;
    g.beginPath();
    g.arc(cx, cx, size * 0.455, a0, a1);
    g.stroke();
  }

  const tex = new THREE.CanvasTexture(c);
  tex.colorSpace = THREE.SRGBColorSpace;
  tex.anisotropy = 4;
  return tex;
}

/* -------------------------------------------------------------------------- */
/*  scene                                                                      */
/* -------------------------------------------------------------------------- */

function boot(THREE) {
  const renderer = new THREE.WebGLRenderer({ canvas, antialias: true, alpha: true });
  renderer.setPixelRatio(Math.min(window.devicePixelRatio || 1, 2));
  renderer.setClearColor(0x000000, 0);
  renderer.toneMapping = THREE.ACESFilmicToneMapping;
  renderer.toneMappingExposure = 1.18;

  const scene = new THREE.Scene();
  const camera = new THREE.PerspectiveCamera(38, 1, 0.1, 100);
  camera.position.set(0, 0, 4.1);
  camera.lookAt(0, 0, 0);

  scene.environment = buildEnvironment(THREE, renderer);

  const NEON = 0x4de2ff;
  const NEON_PINK = 0xff6ec7;

  const goldMat = new THREE.MeshStandardMaterial({
    color: 0xd8a646, metalness: 1, roughness: 0.2, envMapIntensity: 1.5,
  });
  const goldDeep = new THREE.MeshStandardMaterial({
    color: 0x9c7429, metalness: 1, roughness: 0.38, envMapIntensity: 1.2,
  });
  const neonMat = (hex, intensity = 2.6) => new THREE.MeshStandardMaterial({
    color: 0x05070c, emissive: hex, emissiveIntensity: intensity,
    metalness: 0.2, roughness: 0.4, toneMapped: false,
  });
  const darkMat = new THREE.MeshStandardMaterial({
    color: 0x14161f, metalness: 0.85, roughness: 0.34, envMapIntensity: 1.1,
  });

  const circuit = circuitTexture(THREE, 20260923, ['#4de2ff', '#ff6ec7', '#b6ff5c']);

  const flywheel = new THREE.Group();
  flywheel.position.y = 0.06;
  scene.add(flywheel);

  /* ------------------------------ gold coin ------------------------------ */

  const coin = new THREE.Group();

  const faceMat = new THREE.MeshStandardMaterial({
    color: 0xd9a745, metalness: 1, roughness: 0.24,
    emissive: NEON, emissiveMap: circuit, emissiveIntensity: 1.5,
    envMapIntensity: 1.5,
  });
  const body = new THREE.Mesh(
    new THREE.CylinderGeometry(1, 1, 0.18, 96, 1),
    [goldMat, faceMat, faceMat],
  );
  body.rotation.x = Math.PI / 2;
  coin.add(body);

  const ring = (r, tube, mat, z, rot) => {
    for (const s of [1, -1]) {
      const t = new THREE.Mesh(new THREE.TorusGeometry(r, tube, 16, 128), mat);
      t.position.z = z * s;
      if (rot) t.rotation.z = rot;
      coin.add(t);
    }
  };
  ring(1.0, 0.045, goldMat, 0.09);   // beveled rim
  ring(0.9, 0.022, goldDeep, 0.108); // inner ridge
  ring(0.86, 0.014, neonMat(NEON, 3.0), 0.116); // neon circuit halo

  // embossed gear emblem, both faces
  for (const s of [1, -1]) {
    const hub = new THREE.Mesh(new THREE.CylinderGeometry(0.3, 0.3, 0.1, 48), goldMat);
    hub.rotation.x = Math.PI / 2;
    hub.position.z = 0.13 * s;
    coin.add(hub);

    const gear = new THREE.Mesh(new THREE.TorusGeometry(0.44, 0.05, 16, 96), goldDeep);
    gear.position.z = 0.115 * s;
    coin.add(gear);

    const spokeGeo = new THREE.BoxGeometry(0.06, 0.14, 0.05);
    for (let i = 0; i < 16; i++) {
      const a = (i / 16) * Math.PI * 2;
      const tooth = new THREE.Mesh(spokeGeo, goldMat);
      tooth.position.set(Math.cos(a) * 0.44, Math.sin(a) * 0.44, 0.13 * s);
      tooth.rotation.z = a;
      coin.add(tooth);
    }

    const dotGeo = new THREE.SphereGeometry(0.033, 16, 12);
    for (let i = 0; i < 24; i++) {
      const a = (i / 24) * Math.PI * 2;
      const dot = new THREE.Mesh(dotGeo, goldMat);
      dot.position.set(Math.cos(a) * 0.64, Math.sin(a) * 0.64, 0.1 * s);
      coin.add(dot);
    }

    const rimLight = new THREE.Mesh(new THREE.TorusGeometry(0.74, 0.012, 12, 128), neonMat(NEON_PINK, 2.6));
    rimLight.position.z = 0.112 * s;
    coin.add(rimLight);
  }

  flywheel.add(coin);

  /* --------------------------- next-gen controller ------------------------ */

  const pad = new THREE.Group();
  pad.visible = false;

  const shell = new THREE.Mesh(new THREE.SphereGeometry(1, 64, 44), darkMat);
  shell.scale.set(0.8, 0.37, 0.33);
  pad.add(shell);

  for (const s of [1, -1]) {
    const grip = new THREE.Mesh(new THREE.CapsuleGeometry(0.135, 0.5, 12, 24), darkMat);
    grip.position.set(0.53 * s, -0.44, 0.02);
    grip.rotation.z = -0.3 * s;
    grip.rotation.x = 0.16;
    pad.add(grip);

    const gripLight = new THREE.Mesh(new THREE.CapsuleGeometry(0.028, 0.44, 6, 12), neonMat(NEON, 2.4));
    gripLight.position.set(0.6 * s, -0.42, 0.12);
    gripLight.rotation.z = -0.3 * s;
    gripLight.rotation.x = 0.16;
    pad.add(gripLight);

    const shoulder = new THREE.Mesh(new THREE.BoxGeometry(0.3, 0.1, 0.2), darkMat);
    shoulder.position.set(0.42 * s, 0.31, 0);
    shoulder.rotation.z = -0.22 * s;
    pad.add(shoulder);

    const shoulderLight = new THREE.Mesh(new THREE.BoxGeometry(0.26, 0.028, 0.03), neonMat(s > 0 ? NEON_PINK : NEON, 2.6));
    shoulderLight.position.set(0.42 * s, 0.365, 0.075);
    shoulderLight.rotation.z = -0.22 * s;
    pad.add(shoulderLight);

    // analog stick
    const well = new THREE.Mesh(new THREE.CylinderGeometry(0.125, 0.125, 0.03, 32), darkMat);
    well.position.set(0.3 * s, 0.05, 0.28);
    well.rotation.x = Math.PI / 2;
    pad.add(well);

    const ringGlow = new THREE.Mesh(new THREE.TorusGeometry(0.125, 0.016, 12, 48), neonMat(NEON, 2.8));
    ringGlow.position.set(0.3 * s, 0.05, 0.3);
    pad.add(ringGlow);

    const stick = new THREE.Mesh(new THREE.CylinderGeometry(0.06, 0.075, 0.1, 24), darkMat);
    stick.position.set(0.3 * s, 0.05, 0.33);
    stick.rotation.x = Math.PI / 2;
    pad.add(stick);

    const cap = new THREE.Mesh(new THREE.SphereGeometry(0.085, 24, 16), darkMat);
    cap.position.set(0.3 * s, 0.05, 0.38);
    cap.scale.z = 0.7;
    pad.add(cap);
  }

  // d-pad: two crossed bars
  for (let i = 0; i < 2; i++) {
    const arm = new THREE.Mesh(new THREE.BoxGeometry(0.05, 0.15, 0.045), darkMat);
    arm.position.set(-0.3, -0.16, 0.3);
    arm.rotation.z = i === 0 ? 0 : Math.PI / 2;
    pad.add(arm);
  }

  // face buttons
  const btnPositions = [[0.3, -0.06], [0.4, -0.16], [0.3, -0.26], [0.2, -0.16]];
  const btnColors = [NEON_PINK, NEON, 0xb6ff5c, 0xffd166];
  btnPositions.forEach(([bx, by], i) => {
    const b = new THREE.Mesh(new THREE.CylinderGeometry(0.05, 0.055, 0.05, 24), neonMat(btnColors[i], 2.2));
    b.position.set(bx, by, 0.29);
    b.rotation.x = Math.PI / 2;
    pad.add(b);
  });

  // centre status bar
  const bar = new THREE.Mesh(new THREE.BoxGeometry(0.34, 0.035, 0.03), neonMat(NEON, 3.0));
  bar.position.set(0, 0.16, 0.315);
  pad.add(bar);

  const logo = new THREE.Mesh(new THREE.TorusGeometry(0.06, 0.016, 12, 32), neonMat(NEON_PINK, 3.0));
  logo.position.set(0, 0.02, 0.315);
  pad.add(logo);

  const underGlow = new THREE.Mesh(new THREE.CircleGeometry(1.5, 48), new THREE.MeshBasicMaterial({
    color: NEON, transparent: true, opacity: 0.12, side: THREE.DoubleSide, depthWrite: false,
  }));
  underGlow.rotation.x = -Math.PI / 2;
  underGlow.position.y = -1.15;
  pad.add(underGlow);

  flywheel.add(pad);

  /* ------------------------------ lighting ------------------------------- */

  scene.add(new THREE.AmbientLight(0xffffff, 0.35));

  const key = new THREE.DirectionalLight(0xfff2d0, 2.2);
  key.position.set(3, 3.4, 4);
  scene.add(key);

  const rimA = new THREE.PointLight(NEON, 26, 12);
  rimA.position.set(-3.2, 1.8, 2.4);
  scene.add(rimA);

  const rimB = new THREE.PointLight(NEON_PINK, 20, 12);
  rimB.position.set(3.2, -1.6, -2.2);
  scene.add(rimB);

  const warm = new THREE.PointLight(0xffc46b, 14, 10);
  warm.position.set(-1.4, -2.6, 3);
  scene.add(warm);

  /* ------------------------------- controls ------------------------------ */

  const state = {
    yaw: -0.5, pitch: 0.24, targetYaw: -0.5, targetPitch: 0.24,
    dragging: false, auto: !reduceMotion, lastX: 0, lastY: 0, vx: 0, spin: 0,
  };

  const switchBtn = document.getElementById('switchBtn');
  const switchLabel = document.getElementById('switchLabel');
  const autoBtn = document.getElementById('autoBtn');

  function applyMode(showPad) {
    pad.visible = showPad;
    coin.visible = !showPad;
    switchLabel.textContent = showPad ? 'Δες το χρυσό νόμισμα' : 'Δες τον controller';
    switchBtn.setAttribute('aria-pressed', String(showPad));
    state.spin = 0;
  }

  switchBtn.addEventListener('click', () => applyMode(!pad.visible));

  function syncAutoBtn() {
    autoBtn.textContent = `Περιστροφή: ${state.auto ? 'Ναι' : 'Όχι'}`;
    autoBtn.setAttribute('aria-pressed', String(state.auto));
  }
  autoBtn.addEventListener('click', () => { state.auto = !state.auto; syncAutoBtn(); });
  syncAutoBtn();

  const DRAG_SCALE = 0.011;

  function rotateBy(dx, dy) {
    state.targetYaw += dx * DRAG_SCALE;
    state.targetPitch = Math.max(-1.15, Math.min(1.15, state.targetPitch + dy * DRAG_SCALE));
  }

  canvas.addEventListener('pointerdown', (e) => {
    state.dragging = true;
    state.lastX = e.clientX;
    state.lastY = e.clientY;
    state.vx = 0;
    canvas.setPointerCapture?.(e.pointerId);
    stage.classList.add('grabbing');
  });

  canvas.addEventListener('pointermove', (e) => {
    if (state.dragging) {
      const dx = e.clientX - state.lastX;
      const dy = e.clientY - state.lastY;
      state.lastX = e.clientX;
      state.lastY = e.clientY;
      rotateBy(dx, dy);
      state.vx = dx;
    } else {
      // gentle parallax for pointer-only users
      const nx = (e.clientX / window.innerWidth) * 2 - 1;
      const ny = (e.clientY / window.innerHeight) * 2 - 1;
      state.targetYaw += nx * 0.0016;
      state.targetPitch = Math.max(-1.15, Math.min(1.15, state.targetPitch + ny * 0.0014));
    }
  });

  const endDrag = () => {
    if (!state.dragging) return;
    state.dragging = false;
    stage.classList.remove('grabbing');
    state.targetYaw += state.vx * 0.02; // momentum
  };
  canvas.addEventListener('pointerup', endDrag);
  canvas.addEventListener('pointercancel', endDrag);
  canvas.addEventListener('pointerleave', endDrag);

  // keyboard access
  canvas.tabIndex = 0;
  canvas.addEventListener('keydown', (e) => {
    const step = 14;
    if (e.key === 'ArrowLeft') rotateBy(-step, 0);
    else if (e.key === 'ArrowRight') rotateBy(step, 0);
    else if (e.key === 'ArrowUp') rotateBy(0, -step);
    else if (e.key === 'ArrowDown') rotateBy(0, step);
    else if (e.key === ' ' || e.key === 'Enter') applyMode(!pad.visible);
    else return;
    e.preventDefault();
  });

  const hint = document.getElementById('controlHint');
  const hideHint = () => hint?.classList.add('used');
  canvas.addEventListener('pointerdown', hideHint, { once: true });
  switchBtn.addEventListener('click', hideHint, { once: true });

  /* ------------------------------- resize -------------------------------- */

  function resize() {
    const w = canvas.clientWidth || stage.clientWidth || 1;
    const h = canvas.clientHeight || w;
    renderer.setSize(w, h, false);
    // The canvas is square by design; lock the camera so the sphere never stretches.
    camera.aspect = 1;
    camera.updateProjectionMatrix();
  }
  if ('ResizeObserver' in window) new ResizeObserver(resize).observe(stage);
  window.addEventListener('resize', resize);
  resize();

  /* -------------------------------- loop --------------------------------- */

  const clock = new THREE.Clock();
  const reduced = reduceMotion;
  let revealed = false;

  renderer.setAnimationLoop(() => {
    const t = clock.getElapsedTime();

    if (state.auto && !state.dragging) state.targetYaw += 0.0042;

    state.yaw += (state.targetYaw - state.yaw) * 0.09;
    state.pitch += (state.targetPitch - state.pitch) * 0.09;
    state.vx *= 0.9;

    flywheel.rotation.y = state.yaw;
    flywheel.rotation.x = state.pitch;
    flywheel.rotation.z = reduced ? 0 : Math.sin(t * 0.5) * 0.05;
    flywheel.position.y = 0.06 + (reduced ? 0 : Math.sin(t * 0.85) * 0.075);

    if (!reduced) {
      bar.scale.x = 1 + Math.sin(t * 2.4) * 0.05;
      rimA.intensity = 24 + Math.sin(t * 1.6) * 6;
      rimB.intensity = 18 + Math.cos(t * 1.3) * 5;
    }

    renderer.render(scene, camera);

    if (!revealed) {
      revealed = true;
      stage.classList.add('ready');
      stageUI.hidden = false;
    }
  });
}

/* -------------------------------------------------------------------------- */
/*  procedural studio environment (gives the gold something to reflect)        */
/* -------------------------------------------------------------------------- */

function buildEnvironment(THREE, renderer) {
  const envScene = new THREE.Scene();

  const dome = new THREE.Mesh(
    new THREE.SphereGeometry(60, 32, 24),
    new THREE.MeshBasicMaterial({ color: 0x070912, side: THREE.BackSide }),
  );
  envScene.add(dome);

  const light = (hex, intensity, pos, scale) => {
    const m = new THREE.Mesh(
      new THREE.PlaneGeometry(scale, scale),
      new THREE.MeshBasicMaterial({ color: new THREE.Color(hex).multiplyScalar(intensity), side: THREE.DoubleSide }),
    );
    m.position.set(...pos);
    m.lookAt(0, 0, 0);
    envScene.add(m);
  };

  light(0xffffff, 7.0, [0, 26, 6], 26);
  light(0x4de2ff, 5.0, [-26, 8, 16], 22);
  light(0xff6ec7, 4.2, [26, 4, -14], 22);
  light(0xffc46b, 3.6, [4, -24, 12], 20);
  light(0xb6ff5c, 2.2, [-16, -14, -20], 16);

  const pmrem = new THREE.PMREMGenerator(renderer);
  pmrem.compileEquirectangularShader();
  const rt = pmrem.fromScene(envScene, 0.02);
  pmrem.dispose();
  return rt.texture;
}
