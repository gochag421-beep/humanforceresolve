const stage = document.getElementById('stage');
const canvas = document.getElementById('scene');
const fallback = document.getElementById('stageFallback');

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

// Smooth in-page navigation.
document.querySelectorAll('a[href^="#"]').forEach((a) => {
  a.addEventListener('click', (e) => {
    const el = document.getElementById(a.getAttribute('href').slice(1));
    if (!el) return;
    e.preventDefault();
    el.scrollIntoView({ behavior: 'smooth', block: 'start' });
  });
});

function boot(THREE) {
  const renderer = new THREE.WebGLRenderer({
    canvas,
    antialias: true,
    alpha: true,
    powerPreference: 'high-performance',
  });
  renderer.setPixelRatio(Math.min(window.devicePixelRatio || 1, 2));
  renderer.setClearColor(0x000000, 0);

  const scene = new THREE.Scene();
  const camera = new THREE.PerspectiveCamera(42, 1, 0.1, 60);
  camera.position.set(0, 0, 2.75);
  camera.lookAt(0, 0, 0);

  const uniforms = {
    uTime: { value: 0 },
    uPointer: { value: new THREE.Vector2(0, 0) },
    uAmp: { value: 0 },
  };

  const vertexShader = /* glsl */ `
    uniform float uTime;
    uniform vec2 uPointer;
    uniform float uAmp;

    varying vec3 vNormal;
    varying vec3 vViewPos;

    float hash(vec2 p) { return fract(sin(dot(p, vec2(127.1, 311.7))) * 43758.5453123); }

    float noise(vec2 p) {
      vec2 i = floor(p), f = fract(p);
      vec2 u = f * f * (3.0 - 2.0 * f);
      return mix(mix(hash(i), hash(i + vec2(1.0, 0.0)), u.x),
                 mix(hash(i + vec2(0.0, 1.0)), hash(i + vec2(1.0, 1.0)), u.x), u.y);
    }

    float fbm(vec2 p) {
      float v = 0.0, a = 0.5;
      for (int i = 0; i < 4; i++) {
        v += a * noise(p);
        p = p * 2.07 + vec2(11.3, 7.9);
        a *= 0.5;
      }
      return v;
    }

    // Collapse anything outside the unit disc onto its rim, so the silhouette is round.
    vec2 clampDisc(vec2 p) {
      float r = length(p);
      return r > 1.0 ? p / r : p;
    }

    float height(vec2 p) {
      vec2 q = clampDisc(p);
      float r = length(q);
      float sphere = sqrt(max(0.0, 1.0 - r * r));

      float t = uTime * 0.24;
      float n = fbm(q * 2.1 + vec2(t, -t * 0.7));
      n += 0.45 * fbm(q * 4.3 - vec2(t * 1.3, t * 0.5));
      float swell = sin(q.x * 5.0 + t * 3.1) * cos(q.y * 5.0 - t * 2.3) * 0.5;
      float bulge = exp(-3.4 * length(q - uPointer));

      float liquid = (n - 1.02) * 0.30 + swell * 0.10 + bulge * (0.30 + uAmp * 0.75);
      return sphere + liquid * 0.22 * (0.30 + 0.70 * sphere);
    }

    vec3 surface(vec2 p) { return vec3(clampDisc(p), height(p)); }

    void main() {
      vec2 p = position.xy;
      vec3 P = surface(p);

      float e = 0.022;
      vec3 a = surface(p + vec2(e, 0.0));
      vec3 b = surface(p + vec2(0.0, e));
      vec3 n = normalize(cross(a - P, b - P));

      vNormal = normalize(normalMatrix * n);
      vec4 mv = modelViewMatrix * vec4(P, 1.0);
      vViewPos = mv.xyz;
      gl_Position = projectionMatrix * mv;
    }
  `;

  const fragmentShader = /* glsl */ `
    precision highp float;

    varying vec3 vNormal;
    varying vec3 vViewPos;

    vec3 lobe(vec3 d, vec3 dir, vec3 col, float tight) {
      return col * pow(max(dot(d, normalize(dir)), 0.0), tight);
    }

    // Cheap studio environment: a handful of coloured lobes over a sky gradient.
    vec3 envColor(vec3 d) {
      vec3 c = mix(vec3(0.02, 0.03, 0.07), vec3(0.10, 0.13, 0.30), d.y * 0.5 + 0.5);
      c += lobe(d, vec3(0.0, 1.0, 0.25), vec3(0.25, 0.85, 1.00), 3.0);
      c += lobe(d, vec3(-0.90, 0.25, 0.35), vec3(0.45, 0.25, 1.00), 2.4);
      c += lobe(d, vec3(0.95, 0.10, 0.30), vec3(1.00, 0.30, 0.78), 2.6);
      c += lobe(d, vec3(0.10, -0.95, 0.25), vec3(0.55, 1.00, 0.35), 3.2);
      c += lobe(d, vec3(0.20, 0.85, -0.60), vec3(1.00, 0.95, 0.85), 12.0);
      return c;
    }

    void main() {
      vec3 N = normalize(vNormal);
      vec3 V = normalize(-vViewPos);
      vec3 R = reflect(-V, N);

      float fres = pow(1.0 - clamp(dot(N, V), 0.0, 1.0), 2.4);

      vec3 col = envColor(R) * (0.55 + 0.90 * fres);
      col += envColor(normalize(N + vec3(0.35, 0.0, 0.0))) * fres * 0.35;

      float spec = pow(max(dot(R, normalize(vec3(0.35, 0.9, 0.45))), 0.0), 48.0);
      col += vec3(1.0) * spec * 0.80;

      float rim = pow(1.0 - clamp(dot(N, V), 0.0, 1.0), 6.0);
      col += vec3(0.35, 0.55, 1.0) * rim * 0.35;

      col = vec3(1.0) - exp(-col * 1.35);   // tonemap
      col = pow(col, vec3(0.90));           // gamma

      gl_FragColor = vec4(col, 1.0);
    }
  `;

  const material = new THREE.ShaderMaterial({ uniforms, vertexShader, fragmentShader });
  const group = new THREE.Group();
  const mesh = new THREE.Mesh(new THREE.PlaneGeometry(2, 2, 96, 96), material);
  group.add(mesh);
  scene.add(group);

  // ---- pointer state -------------------------------------------------------
  const pointer = { x: 0, y: 0, tx: 0, ty: 0, amp: 0, ampTarget: 0, lastX: 0, lastY: 0, dragging: false };
  const SCALE = 0.9;

  function setTargets(clientX, clientY) {
    pointer.tx = ((clientX / window.innerWidth) * 2 - 1) * SCALE;
    pointer.ty = -(((clientY / window.innerHeight) * 2 - 1) * SCALE);
  }

  setTargets(window.innerWidth * 0.62, window.innerHeight * 0.42);

  window.addEventListener('pointermove', (e) => {
    const speed = Math.hypot(e.clientX - pointer.lastX, e.clientY - pointer.lastY);
    pointer.lastX = e.clientX;
    pointer.lastY = e.clientY;
    setTargets(e.clientX, e.clientY);
    pointer.ampTarget = Math.min(1, pointer.ampTarget + speed / 340);
  }, { passive: true });

  canvas.addEventListener('pointerdown', (e) => {
    pointer.dragging = true;
    canvas.setPointerCapture?.(e.pointerId);
    pointer.ampTarget = 1;
  });

  canvas.addEventListener('pointerup', () => { pointer.dragging = false; });
  canvas.addEventListener('pointercancel', () => { pointer.dragging = false; });

  // ---- layout --------------------------------------------------------------
  function resize() {
    const w = stage.clientWidth || 1;
    const h = stage.clientHeight || w;
    renderer.setSize(w, h, false);
    camera.aspect = w / h;
    camera.updateProjectionMatrix();
  }

  if ('ResizeObserver' in window) new ResizeObserver(resize).observe(stage);
  window.addEventListener('resize', resize);
  resize();

  // ---- loop ----------------------------------------------------------------
  const clock = new THREE.Clock();
  let revealed = false;

  renderer.setAnimationLoop(() => {
    const t = clock.getElapsedTime();

    pointer.x += (pointer.tx - pointer.x) * 0.075;
    pointer.y += (pointer.ty - pointer.y) * 0.075;

    pointer.ampTarget *= pointer.dragging ? 0.995 : 0.955;
    pointer.amp += (pointer.ampTarget - pointer.amp) * 0.06;

    uniforms.uTime.value = t;
    uniforms.uPointer.value.set(pointer.x, pointer.y);
    uniforms.uAmp.value = pointer.amp + Math.sin(t * 0.9) * 0.04;

    group.rotation.y = pointer.x * 0.28 + Math.sin(t * 0.22) * 0.05;
    group.rotation.x = -pointer.y * 0.24 + Math.cos(t * 0.19) * 0.04;

    renderer.render(scene, camera);

    if (!revealed) {
      revealed = true;
      stage.classList.add('ready');
    }
  });
}
