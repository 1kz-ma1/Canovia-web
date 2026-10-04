import test from 'node:test';
import assert from 'node:assert/strict';

import {
    workspaceModeContextLabel,
} from '../../resources/js/instant-navigation.mjs';

test('workspace mode source maps to stable visible context labels', () => {
    assert.equal(workspaceModeContextLabel('manual_preference'), '固定中');
    assert.equal(workspaceModeContextLabel('route_hint'), '画面に追従');
    assert.equal(workspaceModeContextLabel('plan_profile'), 'Planに追従');
    assert.equal(workspaceModeContextLabel('explicit'), '自動');
    assert.equal(workspaceModeContextLabel('default'), '自動');
    assert.equal(workspaceModeContextLabel(null), '自動');
});
