export function spaceStationNeedsExecutionDetails(destination) {
    return String(destination || '') === 'execution_request';
}

export function spaceStationTaskBelongsToPlan(planId, taskPlanId) {
    const selectedPlan = String(planId || '').trim();
    const optionPlan = String(taskPlanId || '').trim();

    return selectedPlan !== '' && optionPlan !== '' && selectedPlan === optionPlan;
}

export function syncSpaceStationConfirmForm(form) {
    if (!form) return;

    const destination = form.querySelector?.('[data-space-station-destination]');
    const plan = form.querySelector?.('[data-space-station-plan]');
    const task = form.querySelector?.('[data-space-station-task]');
    const executionFields = form.querySelector?.('[data-space-station-execution-fields]');
    const executionInstruction = form.querySelector?.('[data-space-station-execution-instruction]');

    const needsExecution = spaceStationNeedsExecutionDetails(destination?.value);
    if (executionFields) executionFields.hidden = !needsExecution;
    if (executionInstruction) executionInstruction.required = needsExecution;

    if (task) {
        const selectedPlanId = String(plan?.value || '');
        let selectedTaskStillValid = task.value === '';

        task.querySelectorAll?.('[data-space-station-task-option]')?.forEach((option) => {
            const available = spaceStationTaskBelongsToPlan(
                selectedPlanId,
                option.dataset.planId,
            );
            option.disabled = !available;

            if (option.selected && available) {
                selectedTaskStillValid = true;
            }
        });

        if (!selectedTaskStillValid) {
            task.value = '';
        }

        task.disabled = selectedPlanId === '';
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
