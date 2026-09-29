import test from 'node:test';
import assert from 'node:assert/strict';

import {
    MAP_PAGE_SCHEMA_VERSION,
    createMapPagePreset,
    moveMapPage,
    normalizeMapPageName,
    normalizeMapPageRoute,
    normalizeMapPageState,
    parseMapPageState,
    removeMapPage,
    resolveMapPageActiveId,
} from '../../resources/js/map-pages.mjs';

const layers = {
    version: 1,
    enabled: ['progress', 'status'],
};

test('Map Page route keeps only structural Map context and drops focus/hash or unrelated query state', () => {
    assert.equal(
        normalizeMapPageRoute('/map?plan=42&foo=secret&intent=execution&level=l3#focus=task%3A9'),
        '/map?level=l3&intent=execution&plan=42',
    );
    assert.equal(normalizeMapPageRoute('/inbox?level=l3'), '/map');
});

test('Map Page preset stores view state without canonical Plan or Task payload copies', () => {
    const page = createMapPagePreset({
        id: 'page:study',
        name: '  AP   学習  ',
        route: '/map?level=l3&intent=execution&plan=12#focus=task%3A99',
        layers,
        createdAt: 1234,
        plan: { id: 12, title: 'should not persist' },
        task: { id: 99, title: 'should not persist' },
    });

    assert.deepEqual(page, {
        id: 'page:study',
        name: 'AP 学習',
        route: '/map?level=l3&intent=execution&plan=12',
        layers,
        created_at: 1234,
    });
    assert.equal('plan' in page, false);
    assert.equal('task' in page, false);
});

test('Map Page names are compact and capped for switcher readability', () => {
    assert.equal(normalizeMapPageName('  開発    作業  '), '開発 作業');
    assert.equal(normalizeMapPageName('x'.repeat(80)).length, 40);
});

test('Map Page state ignores malformed and duplicate entries and respects the custom page cap', () => {
    const state = normalizeMapPageState({
        version: MAP_PAGE_SCHEMA_VERSION,
        pages: [
            { id: 'page:a', name: 'A', route: '/map', layers },
            { id: 'page:a', name: 'duplicate', route: '/map?level=l1', layers },
            { id: '', name: 'invalid', route: '/map', layers },
            { id: 'page:b', name: 'B', route: '/map?level=l1&intent=execution', layers },
            { id: 'page:c', name: 'C', route: '/map?level=l1&intent=reflection', layers },
        ],
    }, 2);

    assert.deepEqual(state.pages.map((page) => page.id), ['page:a', 'page:b']);
    assert.deepEqual(parseMapPageState('not-json').pages, []);
});

test('custom Map Pages can be reordered and deleted without touching their referenced route', () => {
    const state = normalizeMapPageState({
        version: 1,
        pages: [
            { id: 'page:a', name: 'A', route: '/map?level=l1&intent=execution', layers },
            { id: 'page:b', name: 'B', route: '/map?level=l1&intent=reflection', layers },
        ],
    });

    const moved = moveMapPage(state, 'page:b', -1);
    assert.deepEqual(moved.pages.map((page) => page.id), ['page:b', 'page:a']);
    assert.equal(moved.pages[0].route, '/map?level=l1&intent=reflection');

    const removed = removeMapPage(moved, 'page:b');
    assert.deepEqual(removed.pages.map((page) => page.id), ['page:a']);
    assert.equal(removed.pages[0].route, '/map?level=l1&intent=execution');
});

test('active Map Page prefers stored page only while it still matches the current structural route', () => {
    const customPages = [
        createMapPagePreset({
            id: 'page:study',
            name: 'AP学習',
            route: '/map?level=l3&intent=execution&plan=12',
            layers,
        }),
    ];
    const builtinPages = [
        { id: 'builtin:overview', route: '/map' },
        { id: 'builtin:execution', route: '/map?level=l1&intent=execution' },
    ];

    assert.equal(resolveMapPageActiveId({
        currentRoute: '/map?level=l3&intent=execution&plan=12#focus=task%3A1',
        storedActiveId: 'page:study',
        customPages,
        builtinPages,
    }), 'page:study');

    assert.equal(resolveMapPageActiveId({
        currentRoute: '/map?level=l1&intent=execution',
        storedActiveId: 'page:study',
        customPages,
        builtinPages,
    }), 'builtin:execution');
});
