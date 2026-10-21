// Просмотр модели калькулятора на Canvas 2D (W-31, F-16, F-48), без WebGL и three. Параметры поведения — как в
// прототипе (initViewer/drawViewer app.js): начальный вид x −0,55 / y 0,6 рад, масштаб 1; перетаскивание 0,008 рад/px;
// стрелки ±0,15 рад; +/= и − ×1,1; кнопки ×1,15; колесо ×0,93/×1,07; масштаб 0,3–3; не больше 8 500 треугольников
// (каждый k-й) с сортировкой по глубине. Без canvas (getContext → null) просмотр молчит, расчёт от него не зависит.

export const INITIAL_VIEW = Object.freeze({ x: -0.55, y: 0.6, zoom: 1 });
export const MAX_FACES = 8500;
export const ROTATE_STEP = 0.15;
export const DRAG_STEP = 0.008;
export const ZOOM_MIN = 0.3;
export const ZOOM_MAX = 3;

export const zoomed = (view, factor) => ({ ...view, zoom: Math.min(ZOOM_MAX, Math.max(ZOOM_MIN, view.zoom * factor)) });

// Новый вид для клавиши или null, если клавиша просмотра не касается.
export function viewForKey(view, key) {
  switch (key) {
    case 'ArrowLeft': return { ...view, y: view.y - ROTATE_STEP };
    case 'ArrowRight': return { ...view, y: view.y + ROTATE_STEP };
    case 'ArrowUp': return { ...view, x: view.x - ROTATE_STEP };
    case 'ArrowDown': return { ...view, x: view.x + ROTATE_STEP };
    case '+': case '=': return zoomed(view, 1.1);
    case '-': return zoomed(view, 1 / 1.1);
    default: return null;
  }
}

// Треугольники для рисования: каждый k-й из positions (Float32Array, 9 чисел на треугольник), повёрнутые вокруг центра
// габарита и спроецированные в окно width × height; отсортированы от дальних к ближним (большее depth — ближе).
// shade 0,55–0,95 — по нормали к условному источнику света.
export function projectFaces(positions, view, width, height) {
  const count = positions.length / 9;
  const min = [Infinity, Infinity, Infinity];
  const max = [-Infinity, -Infinity, -Infinity];
  for (let i = 0; i < positions.length; i++) {
    const axis = i % 3;
    if (positions[i] < min[axis]) min[axis] = positions[i];
    if (positions[i] > max[axis]) max[axis] = positions[i];
  }
  const center = min.map((value, axis) => (value + max[axis]) / 2);
  const size = Math.max(max[0] - min[0], max[1] - min[1], max[2] - min[2], 1e-9);
  const scale = Math.min(width, height) * 0.72 / size * view.zoom;
  const cosY = Math.cos(view.y), sinY = Math.sin(view.y), cosX = Math.cos(view.x), sinX = Math.sin(view.x);
  const step = Math.max(1, Math.ceil(count / MAX_FACES));

  const faces = [];
  for (let t = 0; t < count; t += step) {
    const points = new Array(6);
    const rotated = new Array(9);
    for (let v = 0; v < 3; v++) {
      const offset = t * 9 + v * 3;
      const x = positions[offset] - center[0], y = positions[offset + 1] - center[1], z = positions[offset + 2] - center[2];
      const rx = x * cosY - z * sinY;
      const rz = x * sinY + z * cosY;
      const ry = y * cosX - rz * sinX;
      const depth = y * sinX + rz * cosX;
      rotated[v * 3] = rx; rotated[v * 3 + 1] = ry; rotated[v * 3 + 2] = depth;
      points[v * 2] = width / 2 + rx * scale;
      points[v * 2 + 1] = height / 2 - ry * scale + 8;
    }
    const ux = rotated[3] - rotated[0], uy = rotated[4] - rotated[1], uz = rotated[5] - rotated[2];
    const wx = rotated[6] - rotated[0], wy = rotated[7] - rotated[1], wz = rotated[8] - rotated[2];
    const nx = uy * wz - uz * wy, ny = uz * wx - ux * wz, nz = ux * wy - uy * wx;
    const length = Math.hypot(nx, ny, nz) || 1;
    faces.push({
      points,
      depth: (rotated[2] + rotated[5] + rotated[8]) / 3,
      shade: 0.55 + 0.4 * Math.abs((nx * 0.2 + ny * 0.5 + nz * 0.8) / length),
    });
  }
  return faces.sort((a, b) => a.depth - b.depth);
}

function draw(canvas, context, positions, view) {
  const width = canvas.clientWidth || 600;
  const height = canvas.clientHeight || 330;
  const ratio = Math.min(globalThis.devicePixelRatio || 1, 2);
  canvas.width = width * ratio;
  canvas.height = height * ratio;
  context.scale(ratio, ratio);
  context.clearRect(0, 0, width, height);

  context.strokeStyle = '#adbed133';
  context.lineWidth = 1;
  context.beginPath();
  for (let i = -12; i <= 12; i++) {
    context.moveTo(width / 2 + i * 28, 0);
    context.lineTo(width / 2 + i * 28, height);
  }
  for (let y = 0; y < height; y += 28) {
    context.moveTo(0, y);
    context.lineTo(width, y);
  }
  context.stroke();
  if (!positions) return;

  context.strokeStyle = 'rgba(70,155,255,.20)';
  context.lineWidth = 0.45;
  for (const { points, shade } of projectFaces(positions, view, width, height)) {
    context.beginPath();
    context.moveTo(points[0], points[1]);
    context.lineTo(points[2], points[3]);
    context.lineTo(points[4], points[5]);
    context.closePath();
    context.fillStyle = `rgb(${Math.round(15 * shade)},${Math.round(105 * shade)},${Math.round(245 * shade)})`;
    context.fill();
    context.stroke();
  }
  context.fillStyle = '#637a96';
  context.font = '10px Arial';
  context.fillText('X', width - 50, height - 60);
  context.fillText('Y', width - 30, height - 75);
  context.fillText('Z', width - 35, height - 98);
}

// Просмотр в элементе .viewer: canvas и кнопки [data-zoom-in], [data-zoom-out], [data-reset-view].
// show(model) — модель разбора ядра (positions) или null; вид при смене модели сохраняется, как в прототипе.
export function mountViewer(element) {
  const canvas = element.querySelector('canvas');
  const context = canvas.getContext('2d');
  let view = INITIAL_VIEW;
  let positions = null;
  const redraw = () => { if (context) draw(canvas, context, positions, view); };
  const update = (next) => { view = next; redraw(); };

  let drag = null;
  canvas.addEventListener('pointerdown', (event) => {
    drag = { x: event.clientX, y: event.clientY };
    canvas.setPointerCapture(event.pointerId);
  });
  canvas.addEventListener('pointermove', (event) => {
    if (!drag) return;
    const next = { ...view, y: view.y + (event.clientX - drag.x) * DRAG_STEP, x: view.x + (event.clientY - drag.y) * DRAG_STEP };
    drag = { x: event.clientX, y: event.clientY };
    update(next);
  });
  canvas.addEventListener('pointerup', () => { drag = null; });
  canvas.addEventListener('wheel', (event) => {
    event.preventDefault();
    update(zoomed(view, event.deltaY > 0 ? 0.93 : 1.07));
  }, { passive: false });
  canvas.addEventListener('keydown', (event) => {
    const next = viewForKey(view, event.key);
    if (!next) return;
    event.preventDefault();
    update(next);
  });
  element.querySelector('[data-zoom-in]').addEventListener('click', () => update(zoomed(view, 1.15)));
  element.querySelector('[data-zoom-out]').addEventListener('click', () => update(zoomed(view, 1 / 1.15)));
  element.querySelector('[data-reset-view]').addEventListener('click', () => update(INITIAL_VIEW));

  return {
    show(model) {
      positions = model ? model.positions : null;
      redraw();
    },
    get view() { return view; },
  };
}
