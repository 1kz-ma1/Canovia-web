import test from 'node:test';
import assert from 'node:assert/strict';

import {
    cookieHomeSurface,
    homeSurfaceUrl,
    normalizeHomeSurface,
    persistHomeSurface,
    resolveHomeSurface,
} from '../../resources/js/home-surface-preference.mjs';

function fakeStorage(initial = {}) {
    const values = new Map(Object.entries(initial));
    return {
        getItem: (key) => values.has(key) ? values.get(key) : null,
        setItem: (key, value) => values.set(key, String(value)),
        value: (key) => values.get(key),
    };
}

test('Home surface normalization is fail-safe to Classic', () => {
    assert.equal(normalizeHomeSurface('map'), 'map');
    assert.equal(normalizeHomeSurface('classic'), 'classic');
    assert.equal(normalizeHomeSurface('unknown'), 'classic');
    assert.equal(normalizeHomeSurface(null), 'classic');
});

test('stored device preference wins and falls back to the readable cookie', () => {
    const documentRef = { cookie: 'foo=1; canovia_home_surface=map' };

    assert.equal(
        resolveHomeSurface({
            storageRef: fakeStorage({ 'pacekeeper.ui.home_surface': 'classic' }),
            documentRef,
        }),
        'classic',
    );

    assert.equal(
        resolveHomeSurface({
            storageRef: fakeStorage(),
            documentRef,
        }),
        'map',
    );

    assert.equal(cookieHomeSurface({ cookie: 'canovia_home_surface=bad' }), 'classic');
});

test('persist writes both per-device storage and a server-visible cookie', () => {
    const storageRef = fakeStorage();
    const documentRef = { cookie: '' };

    const value = persistHomeSurface('map', {
        storageRef,
        documentRef,
        locationRef: { protocol: 'https:' },
    });

    assert.equal(value, 'map');
    assert.equal(storageRef.value('pacekeeper.ui.home_surface'), 'map');
    assert.match(documentRef.cookie, /canovia_home_surface=map/);
    assert.match(documentRef.cookie, /SameSite=Lax/);
    assert.match(documentRef.cookie, /Secure/);
});

test('preferred URL never changes explicit Classic and Map destinations', () => {
    assert.equal(homeSurfaceUrl('classic', { classicUrl: '/', mapUrl: '/map' }), '/');
    assert.equal(homeSurfaceUrl('map', { classicUrl: '/', mapUrl: '/map' }), '/map');
    assert.equal(homeSurfaceUrl('unexpected', { classicUrl: '/', mapUrl: '/map' }), '/');
});
