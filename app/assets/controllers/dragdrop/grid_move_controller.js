import { Controller } from '@hotwired/stimulus';
import { glideFrom } from '../../animation.js';
import { CELL_SELECTED_CLASS, CELL_TARGET_CLASS } from '../../dragdrop.js';

// How to use, see
// docs/components/tokens-and-boards.md
export default class extends Controller {
    static targets = ['form', 'fromX', 'fromY', 'toX', 'toY', 'sacrifice'];
    static values = { moves: Object, captureDistance: Number, sacrificeSquares: Array };

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

        if (this.hasSacrificeSquaresValue && this.sacrificeSquaresValue.includes(key)) {
            this.submitSacrifice(key);
            return;
        }

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

    submitSacrifice(key) {
        const [x, y] = key.split(':').map(Number);
        this.cellAt(x, y)?.querySelector('[data-flip-id]')?.classList.add('ghost-fade');

        this.sacrificeTarget.value = key;
        this.formTarget.requestSubmit();
    }

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
