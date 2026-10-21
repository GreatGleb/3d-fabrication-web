// Тесты просмотра модели W-31 (viewer/index.js): параметры прототипа, прореживание до 8 500 треугольников, порядок
// по глубине, работа без canvas. node --test, без npm-пакетов; DOM — минимальные заглушки элементов.
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { describe, test } from 'node:test';
import { parseStl } from '../../resources/js/calculator/core/index.js';
import {
  INITIAL_VIEW, MAX_FACES, ZOOM_MAX, ZOOM_MIN, mountViewer, projectFaces, viewForKey, zoomed,
} from '../../resources/js/calculator/viewer/index.js';

const fixture = (name) => {
  const b = readFileSync(new URL(`../fixtures/stl/${name}`, import.meta.url));
  return parseStl(b.buffer.slice(b.byteOffset, b.byteOffset + b.byteLength));
};

// Сетка из n треугольников, разнесённых по X (каждый — свой индекс в координате).
function strip(n) {
  const positions = new Float32Array(n * 9);
  for (let t = 0; t < n; t++) positions.set([t, 0, 0, t, 1, 0, t, 0, 1], t * 9);
  return positions;
}

describe('управление видом', () => {
  test('стрелки — ±0,15 рад, +/= и − — ×1,1, иные клавиши — null', () => {
    const close = (actual, expected) => assert.ok(Math.abs(actual - expected) < 1e-12, `${actual} ≠ ${expected}`);
    close(viewForKey(INITIAL_VIEW, 'ArrowRight').y, 0.75);
    close(viewForKey(INITIAL_VIEW, 'ArrowLeft').y, 0.45);
    close(viewForKey(INITIAL_VIEW, 'ArrowUp').x, -0.7);
    close(viewForKey(INITIAL_VIEW, 'ArrowDown').x, -0.4);
    close(viewForKey(INITIAL_VIEW, '+').zoom, 1.1);
    close(viewForKey(INITIAL_VIEW, '=').zoom, 1.1);
    close(viewForKey(INITIAL_VIEW, '-').zoom, 1 / 1.1);
    assert.equal(viewForKey(INITIAL_VIEW, 'a'), null);
    assert.deepEqual(INITIAL_VIEW, { x: -0.55, y: 0.6, zoom: 1 });
  });

  test('масштаб ограничен 0,3–3', () => {
    let view = INITIAL_VIEW;
    for (let i = 0; i < 50; i++) view = zoomed(view, 1.15);
    assert.equal(view.zoom, ZOOM_MAX);
    assert.equal(ZOOM_MAX, 3);
    for (let i = 0; i < 50; i++) view = viewForKey(view, '-');
    assert.equal(view.zoom, ZOOM_MIN);
    assert.equal(ZOOM_MIN, 0.3);
  });
});

describe('треугольники', () => {
  test('куб: все 12 треугольников, от дальних к ближним, по центру окна (сдвиг вниз на 8 px, как в прототипе)', () => {
    const faces = projectFaces(fixture('cube-10mm-binary.stl').positions, INITIAL_VIEW, 600, 330);
    assert.equal(faces.length, 12);
    for (let i = 1; i < faces.length; i++) assert.ok(faces[i - 1].depth <= faces[i].depth, 'порядок по глубине');
    const xs = faces.flatMap((f) => [f.points[0], f.points[2], f.points[4]]);
    const ys = faces.flatMap((f) => [f.points[1], f.points[3], f.points[5]]);
    const middle = (values) => (Math.min(...values) + Math.max(...values)) / 2;
    assert.ok(Math.abs(middle(xs) - 300) < 1 && Math.abs(middle(ys) - 173) < 1, `центр ${middle(xs)}, ${middle(ys)}`);
    // Ребро 10 мм при масштабе 1 — 0,72 меньшей стороны окна: проекция не меньше 0,72 × 330.
    assert.ok(Math.max(...ys) - Math.min(...ys) >= 0.72 * 330 - 1e-9);
    for (const { shade } of faces) assert.ok(shade >= 0.55 && shade <= 0.95);
  });

  test('500 000 треугольников — каждый 59-й, не больше 8 500', () => {
    const faces = projectFaces(strip(500000), { x: 0, y: 0, zoom: 1 }, 600, 330);
    assert.ok(faces.length <= MAX_FACES && faces.length > MAX_FACES - 100, `${faces.length}`);
    // Без поворота X проекции = t: шаг равномерный (0, 59, 118, …).
    const xs = faces.map((f) => f.points[0]).sort((a, b) => a - b);
    const gaps = new Set(xs.slice(1).map((x, i) => Math.round((x - xs[i]) * 1e6)));
    assert.equal(gaps.size, 1, 'неравномерное прореживание');
  });

  test('ближний к зрителю треугольник рисуется последним; после поворота на π — другой', () => {
    // A (z = 0) — высотой 1, B (z = 5) — высотой 3; без поворота ближе B (depth > 0), после поворота на π — A.
    const positions = new Float32Array([0, 0, 0, 1, 0, 0, 0, 1, 0, 0, 0, 5, 1, 0, 5, 0, 3, 5]);
    const height = (face) => Math.max(face.points[1], face.points[3], face.points[5]) - Math.min(face.points[1], face.points[3], face.points[5]);
    const front = projectFaces(positions, { x: 0, y: 0, zoom: 1 }, 600, 330);
    const back = projectFaces(positions, { x: 0, y: Math.PI, zoom: 1 }, 600, 330);
    assert.ok(front[0].depth < front[1].depth && back[0].depth < back[1].depth);
    assert.ok(height(front[1]) > height(front[0]) * 2, 'без поворота последним должен быть B');
    assert.ok(height(back[1]) * 2 < height(back[0]), 'после поворота последним должен быть A');
  });
});

// Заглушки DOM: элемент с обработчиками и canvas, getContext которого задаёт тест.
function fakeViewer(context) {
  const node = () => {
    const handlers = {};
    return { handlers, addEventListener: (type, handler) => { handlers[type] = handler; }, setPointerCapture() {} };
  };
  const canvas = { ...node(), clientWidth: 600, clientHeight: 330, getContext: () => context };
  const buttons = { '[data-zoom-in]': node(), '[data-zoom-out]': node(), '[data-reset-view]': node() };
  const element = { querySelector: (selector) => (selector === 'canvas' ? canvas : buttons[selector]) };
  return { element, canvas, buttons };
}

function recordingContext() {
  const calls = [];
  return new Proxy({ calls }, {
    get: (target, name) => (name in target ? target[name] : (...args) => calls.push(name)),
    set: () => true,
  });
}

describe('mountViewer', () => {
  test('клавиши, колесо, кнопки и сброс меняют вид и перерисовывают модель', () => {
    const context = recordingContext();
    const { element, canvas, buttons } = fakeViewer(context);
    const viewer = mountViewer(element);
    viewer.show(fixture('cube-10mm-binary.stl'));
    assert.equal(context.calls.filter((c) => c === 'fill').length, 12);

    let prevented = false;
    canvas.handlers.keydown({ key: 'ArrowRight', preventDefault: () => { prevented = true; } });
    assert.ok(prevented && Math.abs(viewer.view.y - 0.75) < 1e-12);
    canvas.handlers.wheel({ deltaY: 100, preventDefault() {} });
    assert.ok(Math.abs(viewer.view.zoom - 0.93) < 1e-12);
    buttons['[data-zoom-in]'].handlers.click();
    assert.ok(Math.abs(viewer.view.zoom - 0.93 * 1.15) < 1e-12);
    canvas.handlers.pointerdown({ clientX: 0, clientY: 0, pointerId: 1 });
    canvas.handlers.pointermove({ clientX: 10, clientY: 0 });
    assert.ok(Math.abs(viewer.view.y - (0.75 + 0.08)) < 1e-12);
    canvas.handlers.pointerup();
    canvas.handlers.pointermove({ clientX: 50, clientY: 0 });
    assert.ok(Math.abs(viewer.view.y - (0.75 + 0.08)) < 1e-12, 'вращение после отпускания');
    buttons['[data-reset-view]'].handlers.click();
    assert.deepEqual(viewer.view, INITIAL_VIEW);
    assert.equal(context.calls.filter((c) => c === 'fill').length, 12 * 6);
  });

  test('без canvas (getContext → null) не падает и вид всё равно управляется', () => {
    const { element, canvas } = fakeViewer(null);
    const viewer = mountViewer(element);
    viewer.show(fixture('cube-10mm-binary.stl'));
    canvas.handlers.keydown({ key: 'ArrowRight', preventDefault() {} });
    viewer.show(null);
    assert.ok(Math.abs(viewer.view.y - 0.75) < 1e-12);
  });
});
