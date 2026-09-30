import test from 'node:test';
import assert from 'node:assert/strict';

import {
    INSTANT_RUNTIME_TRANSIENT_ATTRIBUTES,
    stripInstantRuntimeTransientState,
} from '../../resources/js/instant-navigation.mjs';

function fakeElement(attributes = [], children = []) {
    const attrs = new Set(attributes);

    return {
        children,
        hasAttribute(name) {
            return attrs.has(name);
        },
        removeAttribute(name) {
            attrs.delete(name);
        },
        querySelectorAll(selector) {
            const attribute = selector.match(/^\[([^\]]+)\]$/)?.[1];
            if (!attribute) return [];

            const descendants = [];
            const visit = (items) => {
                for (const child of items) {
                    if (child.hasAttribute?.(attribute)) descendants.push(child);
                    visit(child.children || []);
                }
            };
            visit(children);

            return descendants;
        },
        attributes() {
            return [...attrs].sort();
        },
    };
}

test('instant snapshot strips Map runtime initialization guards before cache reuse', () => {
    const mapPage = fakeElement([
        'data-map-focus-initialized',
        'data-map-semantic-transition-marked',
        'data-map-reprojected',
        'data-map-level',
    ]);
    const pageControl = fakeElement([
        'data-map-pages-initialized',
        'data-map-page-control',
    ]);
    const layerControl = fakeElement([
        'data-map-data-layers-initialized',
        'data-map-data-layer-control',
    ]);
    const personalization = fakeElement([
        'data-map-personalization-initialized',
        'data-canovia-map-page',
    ]);
    const intake = fakeElement([
        'data-space-station-intake-initialized',
        'data-space-station-intake',
    ]);
    const root = fakeElement(
        ['data-canovia-instant-initialized', 'data-canovia-page'],
        [mapPage, pageControl, layerControl, personalization, intake],
    );

    stripInstantRuntimeTransientState(root);

    assert.deepEqual(root.attributes(), ['data-canovia-page']);
    assert.deepEqual(mapPage.attributes(), ['data-map-level']);
    assert.deepEqual(pageControl.attributes(), ['data-map-page-control']);
    assert.deepEqual(layerControl.attributes(), ['data-map-data-layer-control']);
    assert.deepEqual(personalization.attributes(), ['data-canovia-map-page']);
    assert.deepEqual(intake.attributes(), ['data-space-station-intake']);

    for (const attribute of INSTANT_RUNTIME_TRANSIENT_ATTRIBUTES) {
        assert.equal(root.hasAttribute(attribute), false);
        assert.equal(mapPage.hasAttribute(attribute), false);
        assert.equal(pageControl.hasAttribute(attribute), false);
        assert.equal(layerControl.hasAttribute(attribute), false);
        assert.equal(personalization.hasAttribute(attribute), false);
        assert.equal(intake.hasAttribute(attribute), false);
    }
});

test('instant snapshot cleanup preserves canonical data attributes', () => {
    const child = fakeElement([
        'data-map-node-id',
        'data-map-node-type',
        'data-map-is-primary',
    ]);
    const root = fakeElement(['data-canovia-page'], [child]);

    assert.equal(stripInstantRuntimeTransientState(root), root);
    assert.deepEqual(root.attributes(), ['data-canovia-page']);
    assert.deepEqual(child.attributes(), [
        'data-map-is-primary',
        'data-map-node-id',
        'data-map-node-type',
    ]);
});
