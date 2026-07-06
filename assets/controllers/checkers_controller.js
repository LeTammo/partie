import { Controller } from '@hotwired/stimulus';

/*
 * Two-tap move picker for Checkers.
 *
 * The server renders the viewer's legal moves into `movesValue`
 * ({"x:y": [{toX, toY}, ...]}). Tapping an own piece selects it, tapping a
 * highlighted destination fills the hidden form and submits the move.
 */
export default class extends Controller {
    static targets = ['form', 'fromX', 'fromY', 'toX', 'toY'];
    static values = { moves: Object };

    connect() {
        this.selected = null;
        this.autoSelectSinglePiece();
        this.paint();
    }

    movesValueChanged() {
        this.selected = null;
        this.autoSelectSinglePiece();
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

        if (this.selected === null) {
            return;
        }

        const legal = (this.movesValue[this.selected] || []).some((m) => m.toX === x && m.toY === y);
        if (!legal) {
            return;
        }

        const [fromX, fromY] = this.selected.split(':');
        this.fromXTarget.value = fromX;
        this.fromYTarget.value = fromY;
        this.toXTarget.value = x;
        this.toYTarget.value = y;
        this.formTarget.requestSubmit();
    }

    autoSelectSinglePiece() {
        const origins = Object.keys(this.movesValue);
        if (origins.length === 1) {
            this.selected = origins[0];
        }
    }

    paint() {
        const targets = this.selected ? this.movesValue[this.selected] || [] : [];

        this.element.querySelectorAll('[data-cell]').forEach((el) => {
            const key = `${el.dataset.x}:${el.dataset.y}`;
            el.classList.toggle('cell-selected', key === this.selected);
            el.classList.toggle(
                'cell-target',
                targets.some((m) => `${m.toX}:${m.toY}` === key),
            );
        });
    }
}
