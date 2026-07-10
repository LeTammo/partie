import { Controller } from '@hotwired/stimulus';
import { glideFrom } from '../../animation.js';
import { CELL_SELECTED_CLASS, CELL_TARGET_CLASS } from '../../dragdrop.js';

/*
 * "grid_move" drag-and-drop type: a 2D board where cells hold pieces that
 * move from one cell to another. Tap a piece then a highlighted destination,
 * or drag the piece onto it - both submit the same hidden
 * fromX/fromY/toX/toY form. The move is animated optimistically (the piece
 * glides there before the server confirms); a rejected move morphs
 * everything back via the flip.js exit-ghost mechanism.
 *
 * The server renders the viewer's legal moves into `movesValue`
 * ({"x:y": [{toX, toY}, ...]}). Cells carry data-cell/data-x/data-y; a
 * cell's piece (if any) carries data-flip-id so it can glide.
 *
 * Optional captureDistanceValue: for games where moving exactly this many
 * columns implies jumping over and capturing whatever piece sits at the
 * midpoint (e.g. checkers) - that piece fades out. Leave it unset for games
 * with no capture-by-jump rule.
 */
export default class extends Controller {
    static targets = ['form', 'fromX', 'fromY', 'toX', 'toY'];
    static values = { moves: Object, captureDistance: Number };

    connect() {
        this.selected = null;
        this.autoSelectSingleOrigin();
        this.paint();
    }

    movesValueChanged() {
        this.selected = null;
        this.autoSelectSingleOrigin();
        this.paint();
    }

    pick(event) {
        const cell = event.currentTarget;
        const x = Number(cell.dataset.x);
        const y = Number(cell.dataset.y);
        const key = `${x}:${y}`;

        if (this.movesValue[key]) {
            this.selected = this.selected === key ? null : key;
            this.paint();
            return;
        }

        if (this.selected === null || !this.isLegalTarget(x, y)) {
            return;
        }
        this.submitMove(x, y);
    }

    // ---------- drag & drop ----------

    dragStart(event) {
        const key = `${event.currentTarget.dataset.x}:${event.currentTarget.dataset.y}`;
        if (!this.movesValue[key]) {
            event.preventDefault();
            return;
        }
        this.selected = key;
        this.paint();
        event.dataTransfer.setData('text/plain', key);
        event.dataTransfer.effectAllowed = 'move';
    }

    dragOver(event) {
        const x = Number(event.currentTarget.dataset.x);
        const y = Number(event.currentTarget.dataset.y);
        if (this.selected !== null && this.isLegalTarget(x, y)) {
            event.preventDefault();
            event.dataTransfer.dropEffect = 'move';
        }
    }

    drop(event) {
        const x = Number(event.currentTarget.dataset.x);
        const y = Number(event.currentTarget.dataset.y);
        if (this.selected === null || !this.isLegalTarget(x, y)) {
            return;
        }
        event.preventDefault();
        this.submitMove(x, y);
    }

    dragEnd() {
        this.paint();
    }

    // ---------- move submission ----------

    submitMove(toX, toY) {
        const [fromX, fromY] = this.selected.split(':').map(Number);
        this.animateMove(fromX, fromY, toX, toY);

        this.fromXTarget.value = fromX;
        this.fromYTarget.value = fromY;
        this.toXTarget.value = toX;
        this.toYTarget.value = toY;
        this.formTarget.requestSubmit();

        this.selected = null;
        this.paint();
    }

    /** Optimistic move: glide the piece; fade a captured piece if captureDistanceValue matches. */
    animateMove(fromX, fromY, toX, toY) {
        const piece = this.cellAt(fromX, fromY)?.querySelector('[data-flip-id]');
        const target = this.cellAt(toX, toY);
        if (!piece || !target) {
            return;
        }

        const before = piece.getBoundingClientRect();
        target.appendChild(piece);
        glideFrom(piece, before);

        if (this.hasCaptureDistanceValue && this.captureDistanceValue === Math.abs(toX - fromX)) {
            const captured = this.cellAt((fromX + toX) / 2, (fromY + toY) / 2)?.querySelector('[data-flip-id]');
            if (captured) {
                captured.classList.add('ghost-fade');
                setTimeout(() => captured.remove(), 400);
            }
        }
    }

    // ---------- helpers ----------

    isLegalTarget(x, y) {
        return (this.movesValue[this.selected] || []).some((m) => m.toX === x && m.toY === y);
    }

    cellAt(x, y) {
        return this.element.querySelector(`[data-cell][data-x="${x}"][data-y="${y}"]`);
    }

    autoSelectSingleOrigin() {
        const origins = Object.keys(this.movesValue);
        if (origins.length === 1) {
            this.selected = origins[0];
        }
    }

    paint() {
        const targets = this.selected ? this.movesValue[this.selected] || [] : [];

        this.element.querySelectorAll('[data-cell]').forEach((el) => {
            const key = `${el.dataset.x}:${el.dataset.y}`;
            el.classList.toggle(CELL_SELECTED_CLASS, key === this.selected);
            el.classList.toggle(
                CELL_TARGET_CLASS,
                targets.some((m) => `${m.toX}:${m.toY}` === key),
            );
        });
    }
}
