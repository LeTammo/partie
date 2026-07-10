import { Controller } from '@hotwired/stimulus';
import { CELL_SELECTED_CLASS } from '../dragdrop.js';
import { glideFrom } from '../animation.js';

// How to use, see
// docs/components/cards.md
export default class extends Controller {
    static targets = ['form', 'from', 'to', 'source', 'zone'];
    static values = { moves: Object };

    connect() {
        this.selected = null;
        this.paint();
    }

    movesValueChanged() {
        this.selected = null;
        this.paint();
    }

    pick(event) {
        const key = event.currentTarget.dataset.source;
        event.stopPropagation();
        this.selected = this.selected === key ? null : key;
        this.paint();
    }

    pickZone(event) {
        const zone = event.currentTarget.dataset.zone;
        if (this.selected && this.isLegalTarget(zone)) {
            this.submitMove(this.selected, zone);
        }
    }

    dragStart(event) {
        this.selected = event.currentTarget.dataset.source;
        this.paint();
        event.dataTransfer.setData('text/plain', this.selected);
        event.dataTransfer.effectAllowed = 'move';
    }

    dragOver(event) {
        const zone = event.currentTarget.dataset.zone;
        if (this.selected && this.isLegalTarget(zone)) {
            event.preventDefault();
            event.dataTransfer.dropEffect = 'move';
        }
    }

    drop(event) {
        const zone = event.currentTarget.dataset.zone;
        if (!this.selected || !this.isLegalTarget(zone)) {
            return;
        }
        event.preventDefault();
        this.submitMove(this.selected, zone);
    }

    dragEnd() {
        this.selected = null;
        this.paint();
    }

    isLegalTarget(zone) {
        return (this.movesValue[this.selected] || []).includes(zone);
    }

    submitMove(from, to) {
        this.applyOptimistically(from, to);

        this.fromTarget.value = from;
        this.toTarget.value = to;
        this.formTarget.requestSubmit();
        this.selected = null;
        this.paint();
    }

    applyOptimistically(from, to) {
        const sourceEl = this.sourceTargets.find((el) => el.dataset.source === from);
        const zoneEl = this.zoneTargets.find((el) => el.dataset.zone === to);
        if (!sourceEl || !zoneEl) {
            return;
        }

        const siblings = Array.from(sourceEl.parentElement.children);
        const run = siblings.slice(siblings.indexOf(sourceEl));

        if (to.startsWith('foundation:')) {
            // a foundation only ever shows its current top card
            zoneEl.replaceChildren();
        }

        run.forEach((el) => {
            const before = el.getBoundingClientRect();
            zoneEl.appendChild(el);
            glideFrom(el, before);
        });
    }

    paint() {
        this.sourceTargets.forEach((el) => {
            el.classList.toggle(CELL_SELECTED_CLASS, el.dataset.source === this.selected);
        });
    }
}
