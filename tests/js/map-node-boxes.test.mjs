import test from 'node:test';
import assert from 'node:assert/strict';
import { fitMobileNodeBoxes } from '../../resources/js/map-node-boxes.mjs';

for (const width of [320, 390, 430, 767]) {
    test(`mobile boxes stay inside ${width}px and separate crowded nodes`, () => {
        const viewport = { width, height: 480 };
        const boxes = Array.from({ length: 7 }, (_, i) => ({ id: String(i), width: i === 0 ? 138 : 104, height: 76 }));
        const positions = new Map(boxes.map(box => [box.id, { x: 89, y: 50 }]));
        positions.set('0', { x: 50, y: 50 });
        const original = structuredClone(positions);
        const fitted = fitMobileNodeBoxes(positions, boxes, viewport, '0');
        assert.deepEqual(positions, original);
        if (width > 320) assert.deepEqual(fitted.get('0'), positions.get('0'));
        assert.deepEqual(fitted, fitMobileNodeBoxes(positions, boxes, viewport, '0'));
        const rects = boxes.map(box => ({ ...box, x: fitted.get(box.id).x / 100 * width, y: fitted.get(box.id).y / 100 * viewport.height }));
        for (const a of rects) {
            assert.ok(a.x - a.width / 2 >= 7.99 && a.x + a.width / 2 <= width - 7.99);
            for (const b of rects) {
                if (a.id === b.id) continue;
                assert.ok(Math.abs(a.x - b.x) >= (a.width + b.width) / 2 + 7.99
                    || Math.abs(a.y - b.y) >= (a.height + b.height) / 2 + 7.99);
            }
        }
    });
}

test('empty and temporarily unmeasurable nodes preserve their positions', () => {
    assert.deepEqual(fitMobileNodeBoxes(new Map(), [], { width: 0, height: 0 }), new Map());
    const positions = new Map([['a', { x: 50, y: 50 }]]);
    assert.deepEqual(fitMobileNodeBoxes(positions, [{ id: 'a', width: 0, height: 0 }], { width: 320, height: 480 }), positions);
});
