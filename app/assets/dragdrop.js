/*
 * Low-level HTML5 drag-and-drop ceremony shared by the dragdrop/* controller
 * types (assets/controllers/dragdrop/). Not a Stimulus controller itself -
 * just the bits every drag-and-drop interaction repeats regardless of its
 * shape: starting a drag, accepting/rejecting a dragover, and toggling a
 * hover highlight.
 */

/**
 * Starts a drag: sets the payload + effect (required for the drag to even
 * begin in some browsers, notably Firefox). Returns false if `data` is
 * null/undefined - the caller should then call event.preventDefault() to
 * refuse the drag.
 */
export function startDrag(event, data) {
    if (null == data) {
        return false;
    }
    event.dataTransfer.setData('text/plain', String(data));
    event.dataTransfer.effectAllowed = 'move';
    return true;
}

/** Call from a dragover handler: accepts the drag (preventDefault + dropEffect) and highlights `el`, but only if `accept` is true. */
export function hover(event, el, accept, overClass = 'drop-over') {
    if (!accept) {
        return;
    }
    event.preventDefault();
    event.dataTransfer.dropEffect = 'move';
    el.classList.add(overClass);
}

/** Call from dragleave (and on cleanup) to clear a hover highlight. */
export function unhover(el, overClass = 'drop-over') {
    el.classList.remove(overClass);
}
