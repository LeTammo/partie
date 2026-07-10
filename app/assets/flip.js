/*
 * FLIP + exit-ghost animations for Turbo morph refreshes.
 *
 * Elements opt in with data-flip-id (a stable identity). Around every morph:
 *  - an element whose flip-id survived but moved glides from its old to its
 *    new position (FLIP),
 *  - an element whose flip-id vanished is cloned and animated out as a ghost
 *    (data-flip-exit: "fade" (default), "fly-left", "fly-right" or "none"),
 *  - brand-new flip-ids are left to their CSS entry animations.
 */

import { glideFrom, spawnGhost } from './animation.js';

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
            return; // newly inserted: CSS entry animation handles it
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
