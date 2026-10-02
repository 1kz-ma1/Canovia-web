import test from 'node:test';
import assert from 'node:assert/strict';

import {
    taskHash,
    taskTemplateFromHash,
} from '../../resources/js/plan-dashboard.mjs';

test('standalone Plan Dashboard task detail uses stable roadmap hash state', () => {
    assert.equal(
        taskHash('roadmap-task:42'),
        '#roadmap-task=roadmap-task%3A42',
    );

    assert.equal(
        taskTemplateFromHash({
            location: {
                hash: '#roadmap-task=roadmap-task%3A42',
            },
        }),
        'roadmap-task:42',
    );
});

test('non roadmap hashes do not open standalone Task Detail', () => {
    assert.equal(
        taskTemplateFromHash({
            location: {
                hash: '#section',
            },
        }),
        null,
    );
});
