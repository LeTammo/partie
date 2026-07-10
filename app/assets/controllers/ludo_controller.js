import { Controller } from '@hotwired/stimulus';
import { startDrag, hover, unhover, clearTargets } from '../dragdrop.js';

// How to use, see
// docs/components/tokens-and-boards.md
export default class extends Controller {
    static targets = ['zone'];

    dragStart(event) {
        if (!startDrag(event, 'pawn')) {
            event.preventDefault();
            return;
        }
        this.dragging = event.currentTarget;
    }

    dragOver(event) {
        hover(event, event.currentTarget, !!this.dragging);
    }

    dragLeave(event) {
        unhover(event.currentTarget);
    }

    drop(event) {
        const source = this.dragging;
        this.dragging = null;
        clearTargets(this.zoneTargets);
        if (!source) {
            return;
        }
        event.preventDefault();
        source.closest('form')?.requestSubmit();
    }

    dragEnd() {
        this.dragging = null;
        clearTargets(this.zoneTargets);
    }
}
