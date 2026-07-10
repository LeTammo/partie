import { Controller } from '@hotwired/stimulus';
import { startDrag, hover, unhover, markTargets, clearTargets } from '../../dragdrop.js';

// How to use, see
// docs/components/cards.md
export default class extends Controller {
    static targets = ['source', 'zone'];

    disconnect() {
        this.dragging = null;
    }

    dragStart(event) {
        const source = event.currentTarget;
        if (!startDrag(event, source.dataset.pair || 'drag')) {
            event.preventDefault();
            return;
        }
        this.dragging = source;
        markTargets(this.zonesFor(source));
    }

    dragOver(event) {
        const zone = event.currentTarget;
        hover(event, zone, !!this.dragging && this.zonesFor(this.dragging).includes(zone));
    }

    dragLeave(event) {
        unhover(event.currentTarget);
    }

    drop(event) {
        const source = this.dragging;
        const zone = event.currentTarget;
        this.dragging = null;
        this.clearHighlight();
        if (!source || !this.zonesFor(source).includes(zone)) {
            return;
        }
        event.preventDefault();
        this.trigger(source);
    }

    dragEnd() {
        this.dragging = null;
        this.clearHighlight();
    }

    trigger(source) {
        const proceed = this.element.dispatchEvent(
            new CustomEvent('zone-drop:drop', { detail: { source }, bubbles: true, cancelable: true }),
        );
        if (proceed) {
            source.form?.requestSubmit();
        }
    }

    zonesFor(source) {
        return this.zoneTargets.filter((zone) => zone.dataset.pair === source.dataset.pair);
    }

    clearHighlight() {
        clearTargets(this.zoneTargets);
    }
}
