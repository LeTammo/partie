/*
 * Shared low-level move animations, factored out of flip.js and the game
 * controllers that were each reimplementing the same two mechanics:
 *
 *  - glideFrom: an element visibly slides from where it used to be to
 *    wherever it now sits (a "FLIP" glide). Used whenever a real DOM node
 *    moved (or was moved by the caller) and the jump should read as motion.
 *  - spawnGhost: a throwaway visual stand-in – a clone pinned as a fixed
 *    overlay – that either plays a CSS exit animation in place or travels
 *    toward a target, then removes itself. Used when the "real" element
 *    can't do the traveling itself (it's gone, or doesn't exist yet).
 *
 * Any game controller that wants an optimistic move/glide/ghost animation
 * should reach for these instead of hand-rolling getBoundingClientRect() +
 * Web Animations API again.
 */

/**
 * Glides `el` from `beforeRect` to its current position. Call this AFTER
 * moving/reflowing `el` – it diffs `beforeRect` against where `el` now sits.
 * No-ops if the position barely changed, and cancels any animation already
 * running on `el` so rapid repeated moves don't fight each other.
 */
export function glideFrom(el, beforeRect, { duration = 400, easing = 'cubic-bezier(0.22, 1, 0.36, 1)' } = {}) {
    const after = el.getBoundingClientRect();
    const dx = beforeRect.left - after.left;
    const dy = beforeRect.top - after.top;
    if (Math.abs(dx) < 2 && Math.abs(dy) < 2) {
        return;
    }

    el.getAnimations().forEach((animation) => animation.cancel());
    el.animate(
        [{ transform: `translate(${dx}px, ${dy}px)` }, { transform: 'translate(0, 0)' }],
        { duration, easing },
    );
}

/**
 * Pins `node` (typically a clone) as a fixed-position overlay at `rect`,
 * appended to <body>, then either plays `exitClass` in place (default) or
 * animates it toward `travelTo` (an element or DOMRect, centered). Removes
 * itself once the animation/class settles, with a safety-net timeout in
 * case that event never fires. Returns `node`.
 */
export function spawnGhost(node, rect, { exitClass, travelTo, duration, safetyNet = 1200 } = {}) {
    node.removeAttribute('id');
    node.removeAttribute('data-flip-id');
    Object.assign(node.style, {
        position: 'fixed',
        left: `${rect.left}px`,
        top: `${rect.top}px`,
        width: `${rect.width}px`,
        height: `${rect.height}px`,
        margin: '0',
        pointerEvents: 'none',
        zIndex: '40',
    });
    document.body.appendChild(node);

    const remove = () => node.remove();
    if (travelTo) {
        const to = travelTo instanceof Element ? travelTo.getBoundingClientRect() : travelTo;
        const dx = to.left + to.width / 2 - rect.width / 2 - rect.left;
        const dy = to.top + to.height / 2 - rect.height / 2 - rect.top;
        node.animate(
            [{ transform: 'translate(0, 0)' }, { transform: `translate(${dx}px, ${dy}px)` }],
            { duration: duration ?? 350, easing: 'cubic-bezier(0.34, 1.1, 0.64, 1)' },
        ).onfinish = remove;
    } else {
        node.className = node.className.replace(/\banim-[\w-]+\b/g, '');
        node.classList.add(exitClass ?? 'ghost-fade');
        node.addEventListener('animationend', remove, { once: true });
    }
    setTimeout(remove, safetyNet);

    return node;
}
