// How to use, see
// docs/components/optimism.md

function isOptimistic(form) {
    return form instanceof HTMLFormElement
        && form.dataset.optimistic !== 'off'
        && null !== form.closest('#game-area');
}

document.addEventListener('turbo:submit-start', (event) => {
    const form = event.target;
    if (!isOptimistic(form)) {
        return;
    }

    form.classList.add('pending');

    const submitter = event.detail.formSubmission.submitter;
    if (submitter instanceof HTMLElement) {
        if (submitter.hasAttribute('draggable')) {
            submitter.dataset.optimisticDraggable = submitter.getAttribute('draggable');
            submitter.removeAttribute('draggable');
        }
        submitter.disabled = true;
    }
});

document.addEventListener('turbo:submit-end', (event) => {
    const form = event.target;
    if (!isOptimistic(form)) {
        return;
    }

    form.classList.remove('pending');

    const submitter = event.detail.formSubmission.submitter;
    if (submitter instanceof HTMLElement) {
        submitter.disabled = false;
        if (submitter.dataset.optimisticDraggable) {
            submitter.setAttribute('draggable', submitter.dataset.optimisticDraggable);
            delete submitter.dataset.optimisticDraggable;
        }
    }
});
