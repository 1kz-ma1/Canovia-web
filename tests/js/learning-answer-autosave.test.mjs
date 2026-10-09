import assert from 'node:assert/strict';
import { test } from 'node:test';

const docEvents = {};
const windowEvents = {};
globalThis.document = { addEventListener(name, cb) { docEvents[name] = cb; } };
globalThis.window = { addEventListener(name, cb) { windowEvents[name] = cb; } };

const { mountLearningAnswerAutosave } =
    await import('../../resources/js/learning-answer-autosave.mjs');

function fixture(fetcher = async () => ({ ok: true })) {
    const listeners = {};
    const tasks = new Map();
    const status = { textContent: '未保存' };
    const requests = [];
    const beacons = [];
    let nextId = 0;
    const form = {
        dataset: { learningDraftUrl: '/draft' },
        isConnected: true,
        querySelector(selector) {
            assert.equal(selector, '[data-learning-draft-status]');
            return status;
        },
        addEventListener(name, cb) { listeners[name] = cb; },
        emit(name) { listeners[name]?.(); },
    };
    const root = { querySelector() { return form; } };
    const opts = {
        fetcher: async (...args) => {
            requests.push(args);
            return fetcher(...args);
        },
        makeBody: () => ({ csrf: 'fixture' }),
        delay: (fn) => { const id = ++nextId; tasks.set(id, fn); return id; },
        cancel: (id) => tasks.delete(id),
        sendBeacon: (url, body) => { beacons.push([url, body]); return true; },
    };
    const flushTimer = async () => {
        const callbacks = [...tasks.values()];
        tasks.clear();
        for (const callback of callbacks) await callback();
    };
    return { root, form, status, requests, beacons, tasks, opts, flushTimer };
}

test('debounced input saves once, status reflects real network result, and double-mount is inert', async () => {
    const a = fixture();
    mountLearningAnswerAutosave(a.root, a.opts);
    mountLearningAnswerAutosave(a.root, a.opts);
    assert.equal(a.form.dataset.learningDraftMounted, '1');
    a.form.emit('input');
    a.form.emit('change');
    assert.equal(a.tasks.size, 1);
    await a.flushTimer();
    assert.equal(a.requests.length, 1);
    assert.equal(a.requests[0][0], '/draft');
    assert.equal(a.requests[0][1].credentials, 'same-origin');
    assert.equal(a.requests[0][1].keepalive, true);
    assert.equal(a.status.textContent, '下書きを保存しました');
});

test('save failure never claims success or performs an endless automatic retry', async () => {
    const a = fixture(async () => ({ ok: false }));
    mountLearningAnswerAutosave(a.root, a.opts);
    a.form.emit('input');
    await a.flushTimer();
    assert.match(a.status.textContent, /保存できませんでした/);
    assert.equal(a.tasks.size, 0);
    a.form.emit('input');
    await a.flushTimer();
    assert.equal(a.requests.length, 2);
});

test('form submission stops drafts and pending input flushes on navigation', async () => {
    const a = fixture();
    mountLearningAnswerAutosave(a.root, a.opts);
    a.form.emit('input');
    docEvents['canovia:before-page-replace']?.();
    assert.equal(a.beacons.length, 1);
    a.form.emit('submit');
    assert.equal(a.tasks.size, 0);
    a.form.emit('input');
    assert.equal(a.tasks.size, 0);
    assert.equal(a.requests.length, 0);
});

test('unmounted or missing forms do not schedule a save', async () => {
    const a = fixture();
    mountLearningAnswerAutosave(a.root, a.opts);
    a.form.isConnected = false;
    a.form.emit('change');
    await a.flushTimer();
    assert.equal(a.requests.length, 0);
    mountLearningAnswerAutosave({ querySelector: () => null }, a.opts);
});
