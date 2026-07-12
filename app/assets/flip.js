// How to use, see
// docs/components/ui-kit.md

import { glideFrom, spawnGhost } from './animation.js';

const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)');
const snapshots = new Map();
const restartSnapshots = new Map();

function snapshot() {
    snapshots.clear();
    restartSnapshots.clear();
    // [data-anim-restart]: replay the element's CSS animation after a morph
    // when its value changed - a morph may patch the element in place, which
    // would otherwise swallow the entry animation (a die showing a new roll)
    document.querySelectorAll('[data-anim-restart]').forEach((el) => {
        restartSnapshots.set(el, el.dataset.animRestart);
    });
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

        glideFrom(el, old.rect);
    });

    for (const [id, old] of snapshots) {
        if (!seen.has(id) && old.exit !== 'none') {
            const exitClass = old.exit === 'fly-left' || old.exit === 'fly-right' ? `ghost-${old.exit}` : 'ghost-fade';
            spawnGhost(old.clone, old.rect, { exitClass });
        }
    }
    snapshots.clear();

    // replay entry animations on morphed-in-place elements with a changed value
    document.querySelectorAll('[data-anim-restart]').forEach((el) => {
        if (restartSnapshots.has(el) && restartSnapshots.get(el) !== el.dataset.animRestart) {
            el.getAnimations().forEach((animation) => animation.cancel());
            el.style.animation = 'none';
            void el.offsetWidth;
            el.style.animation = '';
        }
    });
    restartSnapshots.clear();
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
