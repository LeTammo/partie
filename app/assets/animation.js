// How to use, see
// docs/components/optimism.md
// docs/components/ui-kit.md

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
