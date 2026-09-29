const PLAN_DESTINATIONS = Object.freeze([
    'career_capture',
    'recall_material',
    'task_evidence',
    'plan_resource',
    'execution_request',
]);

const TASK_DESTINATIONS = Object.freeze([
    'recall_material',
    'task_evidence',
    'execution_request',
]);

export function spaceStationDestinationScope(destination) {
    const key = String(destination || '');

    return {
        plan: PLAN_DESTINATIONS.includes(key),
        task: TASK_DESTINATIONS.includes(key),
        execution: key === 'execution_request',
    };
}

export function spaceStationNeedsExecutionDetails(destination) {
    return spaceStationDestinationScope(destination).execution;
}

export function spaceStationTaskBelongsToPlan(planId, taskPlanId) {
    const selectedPlan = String(planId || '').trim();
    const optionPlan = String(taskPlanId || '').trim();

    return selectedPlan !== '' && optionPlan !== '' && selectedPlan === optionPlan;
}

export function syncSpaceStationConfirmForm(form) {
    if (!form) return;

    const destination = form.querySelector?.('[data-space-station-destination]');
    const planField = form.querySelector?.('[data-space-station-plan-field]');
    const plan = form.querySelector?.('[data-space-station-plan]');
    const taskField = form.querySelector?.('[data-space-station-task-field]');
    const task = form.querySelector?.('[data-space-station-task]');
    const executionFields = form.querySelector?.('[data-space-station-execution-fields]');
    const executionInstruction = form.querySelector?.('[data-space-station-execution-instruction]');

    const scope = spaceStationDestinationScope(destination?.value);

    if (planField) planField.hidden = !scope.plan;
    if (plan) plan.disabled = !scope.plan;

    if (taskField) taskField.hidden = !scope.task;

    if (executionFields) executionFields.hidden = !scope.execution;
    if (executionInstruction) executionInstruction.required = scope.execution;

    if (task) {
        const selectedPlanId = String(plan?.value || '');
        let selectedTaskStillValid = task.value === '';

        task.querySelectorAll?.('[data-space-station-task-option]')?.forEach((option) => {
            const available = scope.task && spaceStationTaskBelongsToPlan(
                selectedPlanId,
                option.dataset.planId,
            );
            option.disabled = !available;

            if (option.selected && available) {
                selectedTaskStillValid = true;
            }
        });

        if (!selectedTaskStillValid || !scope.task) {
            task.value = '';
        }

        task.disabled = !scope.task || selectedPlanId === '';
    }
}

export function mountSpaceStationIntake({
    documentRef = globalThis.document,
} = {}) {
    const forms = [...(documentRef?.querySelectorAll?.('[data-space-station-confirm]') || [])];
    const mounted = [];

    forms.forEach((form) => {
        if (form.dataset.spaceStationIntakeInitialized === '1') return;

        form.dataset.spaceStationIntakeInitialized = '1';

        const destination = form.querySelector?.('[data-space-station-destination]');
        const plan = form.querySelector?.('[data-space-station-plan]');

        const onChange = () => syncSpaceStationConfirmForm(form);

        destination?.addEventListener?.('change', onChange);
        plan?.addEventListener?.('change', onChange);
        syncSpaceStationConfirmForm(form);

        mounted.push({
            form,
            destroy() {
                destination?.removeEventListener?.('change', onChange);
                plan?.removeEventListener?.('change', onChange);
                delete form.dataset.spaceStationIntakeInitialized;
            },
        });
    });

    return mounted;
}

function mountCurrentSpaceStationIntake() {
    mountSpaceStationIntake();
}

if (globalThis.document?.addEventListener) {
    globalThis.document.addEventListener('DOMContentLoaded', mountCurrentSpaceStationIntake);
    globalThis.document.addEventListener('canovia:page-ready', mountCurrentSpaceStationIntake);
}
