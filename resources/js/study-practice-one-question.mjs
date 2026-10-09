/**
 * Progressive enhancement for legacy StudyPracticeSession question sets.
 *
 * The adaptive Learning Run remains a separate, per-answer grading flow.
 * Here we only page the existing single form; its draft autosave, POST shape,
 * score semantics, and server-side validation stay unchanged.
 */
export function mountLegacyStudyQuestionPager(root = document) {
    const form = root.querySelector('[data-study-practice-draft-form][data-study-practice-one-question]');
    if (!form || form.dataset.questionPagerMounted === '1') return;

    const questions = [...form.querySelectorAll('[data-study-practice-question]')];
    const pager = form.querySelector('[data-study-practice-pager]');
    const previous = form.querySelector('[data-study-practice-previous]');
    const next = form.querySelector('[data-study-practice-next]');
    const progress = form.querySelector('[data-study-practice-question-progress]');
    const submit = form.querySelector('[data-study-practice-final-submit]');
    if (!pager || !previous || !next || !progress || !submit || questions.length < 2) return;

    // Existing autosave has already restored any local draft before this is mounted.
    const hasAnswer = (fieldset) => [...fieldset.querySelectorAll('[name^="answers["]')]
        .some((input) => {
            if (input.type === 'radio' || input.type === 'checkbox') return input.checked;
            return String(input.value ?? '').trim() !== '';
        });
    const firstIncomplete = questions.findIndex((question) => !hasAnswer(question));
    const firstError = questions.findIndex((question) => question.querySelector('[data-practice-answer-error]'));
    let index = firstError >= 0 ? firstError : (firstIncomplete < 0 ? questions.length - 1 : firstIncomplete);

    const render = ({ focus = false } = {}) => {
        questions.forEach((question, i) => { question.hidden = i !== index; });
        progress.textContent = `問題 ${index + 1} / ${questions.length}`;
        previous.hidden = index === 0;
        next.hidden = index === questions.length - 1;
        submit.hidden = index !== questions.length - 1;
        if (focus) {
            // Keep keyboard and screen-reader users on the newly shown question.
            questions[index].focus({ preventScroll: true });
            questions[index].scrollIntoView({ block: 'start', behavior: 'auto' });
        }
    };

    pager.hidden = false;
    form.dataset.questionPagerMounted = '1';
    render();
    previous.addEventListener('click', () => { if (index > 0) { index -= 1; render({ focus: true }); } });
    next.addEventListener('click', () => { if (index < questions.length - 1) { index += 1; render({ focus: true }); } });

    // Pressing Enter in an input must not prematurely submit the entire set.
    form.addEventListener('submit', (event) => {
        if (index < questions.length - 1) {
            event.preventDefault();
            index += 1;
            render({ focus: true });
        }
    });
}

document.addEventListener('DOMContentLoaded', () => {
    // Run after the existing StudyPractice draft-restoration listener.
    window.requestAnimationFrame(() => mountLegacyStudyQuestionPager());
});
