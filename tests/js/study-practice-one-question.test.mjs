import assert from 'node:assert/strict';
import { test } from 'node:test';

// The module only needs DOM helpers when explicitly mounted.
globalThis.document = { addEventListener() {} };
const { mountLegacyStudyQuestionPager } = await import('../../resources/js/study-practice-one-question.mjs');

function control() {
    const events = {};
    return {
        hidden: false,
        style: { display: '' },
        addEventListener(name, callback) { events[name] = callback; },
        click() { events.click?.(); },
        dispatch(name, event) { events[name]?.(event); },
    };
}

function question(requiredAnswer, optionalReasoning = '', error = false) {
    const input = { type: 'radio', checked: requiredAnswer, value: 'A' };
    const optional = { type: 'textarea', value: optionalReasoning };
    const requiredGroup = {
        querySelectorAll(selector) {
            assert.equal(selector, '[name^="answers["]');
            return [input];
        },
    };
    return {
        hidden: false,
        focusCalled: false,
        scrollCalled: false,
        querySelectorAll(selector) {
            assert.equal(selector, '[data-practice-required-field="true"]');
            return [requiredGroup];
        },
        querySelector(selector) {
            assert.equal(selector, '[data-practice-answer-error]');
            return error ? {} : null;
        },
        focus() { this.focusCalled = true; },
        scrollIntoView() { this.scrollCalled = true; },
        optional,
        input,
    };
}

function setup(questions, optionalNotes = []) {
    const pagers = [{ hidden: true, style: { display: 'none' } }, { hidden: true, style: { display: 'none' } }];
    const previous = control();
    const next = control();
    const submit = control();
    const progress = { textContent: '' };
    const parts = {
        '[data-study-practice-previous]': previous,
        '[data-study-practice-next]': next,
        '[data-study-practice-question-progress]': progress,
        '[data-study-practice-final-submit]': submit,
    };
    const events = {};
    const form = {
        dataset: {},
        querySelectorAll(selector) {
            if (selector === '[data-study-practice-question]') return questions;
            if (selector === '[data-study-practice-optional-note]') return optionalNotes;
            if (selector === '[data-study-practice-pager]') return pagers;
            throw new Error('unexpected selector: ' + selector);
        },
        querySelector(selector) { return parts[selector] || null; },
        addEventListener(name, callback) { events[name] = callback; },
        submitEvent() {
            const event = { prevented: false, preventDefault() { this.prevented = true; } };
            events.submit?.(event);
            return event;
        },
    };
    const root = { querySelector() { return form; } };
    mountLegacyStudyQuestionPager(root);
    return { root, form, questions, pagers, previous, next, submit, progress };
}

test('restored answers skip to first truly incomplete required question', () => {
    const questions = [question(true), question(false, '思考過程だけ保存'), question(false)];
    const page = setup(questions);
    assert.equal(page.progress.textContent, '問題 2 / 3');
    assert.deepEqual(questions.map(q => q.hidden), [true, false, true]);
    assert.equal(page.submit.style.display, 'none');
    assert.equal(page.previous.style.display, 'inline-flex');
    assert.equal(page.next.style.display, 'inline-flex');
    assert.deepEqual(page.pagers.map(p => p.style.display), ['flex', 'flex']);
    page.next.click();
    assert.equal(page.progress.textContent, '問題 3 / 3');
    assert.equal(page.next.style.display, 'none');
    assert.equal(page.submit.style.display, 'inline-flex');
    page.previous.click();
    assert.equal(page.progress.textContent, '問題 2 / 3');
    assert.equal(questions[1].optional.value, '思考過程だけ保存');
    assert.equal(questions[0].input.checked, true);
    assert.equal(questions[2].focusCalled, true);
    assert.equal(questions[1].scrollCalled, true);
});

test('Enter before final question advances rather than posting whole set', () => {
    const page = setup([question(false), question(false)]);
    const first = page.form.submitEvent();
    assert.equal(first.prevented, true);
    assert.equal(page.progress.textContent, '問題 2 / 2');
    const last = page.form.submitEvent();
    assert.equal(last.prevented, false);
    // Repeated mounts must not add another submit handler.
    mountLegacyStudyQuestionPager(page.root);
    assert.equal(page.form.dataset.questionPagerMounted, '1');
});

test('server-side field errors take priority over previously saved answers', () => {
    const page = setup([question(true, '', true), question(false), question(false)]);
    assert.equal(page.progress.textContent, '問題 1 / 3');
    assert.equal(page.previous.style.display, 'none');
});


test('locally restored optional reasoning reopens even for a single-question session', () => {
    const note = {
        open: false,
        querySelector(selector) {
            assert.equal(selector, 'textarea');
            return { value: '復元した思考過程' };
        },
    };
    const page = setup([question(false)], [note]);
    assert.equal(note.open, true);
    // A one-question session still uses the original final form submission.
    assert.equal(page.submit.style.display, '');
    assert.deepEqual(page.pagers.map(p => p.style.display), ['none', 'none']);
});

test('empty optional reasoning stays collapsed after pager initialization', () => {
    const note = {
        open: false,
        querySelector() { return { value: '  ' }; },
    };
    const page = setup([question(false), question(false)], [note]);
    assert.equal(note.open, false);
    assert.equal(page.next.style.display, 'inline-flex');
});
