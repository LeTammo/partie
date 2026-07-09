const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)');
const snapshots = new Map();

function snapshot() {
    snapshots.clear();
    document.querySelectorAll('[data-flip-id]').forEach((el) => {
        snapshots.set(el.dataset.flipId, {
            rect: el.getBoundingClientRect(),
            clone: el.cloneNode(true),
            exit: el.dataset.flipExit || 'fade',
        });
    });
}

function play() {
    const seen = new Set();

    document.querySelectorAll('[data-flip-id]').forEach((el) => {
        const id = el.dataset.flipId;
        seen.add(id);

        const old = snapshots.get(id);
        if (!old) {
            return;
        }

        const now = el.getBoundingClientRect();
        const dx = old.rect.left - now.left;
        const dy = old.rect.top - now.top;
        if (Math.abs(dx) < 2 && Math.abs(dy) < 2) {
            return;
        }

        el.getAnimations().forEach((animation) => animation.cancel());
        el.animate(
            [{ transform: `translate(${dx}px, ${dy}px)` }, { transform: 'translate(0, 0)' }],
            { duration: 400, easing: 'cubic-bezier(0.22, 1, 0.36, 1)' },
        );
    });

    for (const [id, old] of snapshots) {
        if (!seen.has(id) && old.exit !== 'none') {
            spawnGhost(old);
        }
    }
    snapshots.clear();
}

function spawnGhost({ rect, clone, exit }) {
    clone.removeAttribute('id');
    clone.removeAttribute('data-flip-id');
    clone.className = clone.className.replace(/\banim-[\w-]+\b/g, '');
    clone.classList.add(exit === 'fly-left' || exit === 'fly-right' ? `ghost-${exit}` : 'ghost-fade');
    Object.assign(clone.style, {
        position: 'fixed',
        left: `${rect.left}px`,
        top: `${rect.top}px`,
        width: `${rect.width}px`,
        height: `${rect.height}px`,
        margin: '0',
        pointerEvents: 'none',
        zIndex: '40',
    });

    document.body.appendChild(clone);
    clone.addEventListener('animationend', () => clone.remove());
    setTimeout(() => clone.remove(), 1200); // safety net
}

document.addEventListener('turbo:before-render', (event) => {
    if (event.detail.renderMethod === 'morph' && !reducedMotion.matches) {
        snapshot();
    } else {
        snapshots.clear();
    }
});

document.addEventListener('turbo:render', (event) => {
    if (event.detail.renderMethod === 'morph' && !reducedMotion.matches) {
        requestAnimationFrame(play);
    } else {
        snapshots.clear();
    }
});
