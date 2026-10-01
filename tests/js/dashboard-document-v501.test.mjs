import test from 'node:test';
import assert from 'node:assert/strict';

import {
    documentFitScale,
    documentZoomScale,
    documentPinchScale,
    documentRegionFocusScale,
    documentAnchorScroll,
    mountDashboardDocuments,
} from '../../resources/js/dashboard-document.mjs';

test('Dashboard Document fit keeps the whole information board visible', () => {
    assert.equal(documentFitScale({
        viewportWidth: 390,
        viewportHeight: 700,
        documentWidth: 780,
        documentHeight: 560,
    }), 0.5);

    assert.equal(documentFitScale({
        viewportWidth: 1000,
        viewportHeight: 800,
        documentWidth: 720,
        documentHeight: 540,
    }), 1);
});

test('Dashboard Document camera preserves fit floor and zoom ceiling', () => {
    assert.equal(documentZoomScale(0.4, 'out', {
        minScale: 0.4,
        maxScale: 1.6,
    }), 0.4);

    assert.equal(documentZoomScale(0.4, 'in', {
        minScale: 0.4,
        maxScale: 1.6,
    }), 0.5);

    assert.equal(documentPinchScale(1.4, 100, 200, {
        minScale: 0.4,
        maxScale: 1.6,
    }), 1.6);
});

test('Dashboard Document region focus and anchored pan use reusable camera math', () => {
    assert.equal(documentRegionFocusScale({
        viewportWidth: 500,
        viewportHeight: 600,
        regionWidth: 250,
        regionHeight: 300,
        minScale: 0.4,
        maxScale: 1.6,
    }), 1.6);

    assert.deepEqual(documentAnchorScroll({
        naturalX: 400,
        naturalY: 300,
        scale: 1,
        screenX: 200,
        screenY: 150,
        contentWidth: 800,
        contentHeight: 600,
        viewportWidth: 400,
        viewportHeight: 300,
    }), {
        left: 200,
        top: 150,
    });
});

test('Map-owned Dashboard Documents are deliberately skipped by the generic runtime', () => {
    const root = {
        dataset: {
            dashboardDocumentOwner: 'map',
        },
        matches(selector) {
            return selector === '[data-dashboard-document]';
        },
    };

    assert.deepEqual(
        mountDashboardDocuments(root, { windowRef: {} }),
        [],
    );
});
