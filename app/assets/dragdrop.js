// How to use, see
// docs/components/tokens-and-boards.md
// docs/components/cards.md

export const CELL_SELECTED_CLASS = 'cell-selected';
export const CELL_TARGET_CLASS = 'cell-target';

export function startDrag(event, data) {
    if (null == data) {
        return false;
    }
    event.dataTransfer.setData('text/plain', String(data));
    event.dataTransfer.effectAllowed = 'move';
    return true;
}

export function hover(event, el, accept, overClass = 'drop-over') {
    if (!accept) {
        return;
    }
    event.preventDefault();
    event.dataTransfer.dropEffect = 'move';
    el.classList.add(overClass);
}

export function unhover(el, overClass = 'drop-over') {
    el.classList.remove(overClass);
}

export function markTargets(els, readyClass = 'drop-ready') {
    els.forEach((el) => el.classList.add(readyClass));
}

export function clearTargets(els, readyClass = 'drop-ready', overClass = 'drop-over') {
    els.forEach((el) => {
        el.classList.remove(readyClass);
        unhover(el, overClass);
    });
}
