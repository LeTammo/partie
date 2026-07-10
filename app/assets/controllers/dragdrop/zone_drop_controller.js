import { Controller } from '@hotwired/stimulus';
import { startDrag, hover, unhover, markTargets, clearTargets } from '../../dragdrop.js';

/*
 * "zone_drop" drag-and-drop type: one or more draggable `source` elements
 * that, dropped onto a `zone`, submit the source's own <form> - dropping is
 * just an alternate way to trigger that form, alongside however else it's
 * activated (typically a plain click on the source itself). Sources and
 * zones are paired up via a shared `data-pair` value, so one controller
 * instance can serve several independent source(s)->zone relationships at
 * once (e.g. a hand of cards -> a discard pile, AND separately a draw
 * pile -> the hand).
 *
 * Dispatches a cancelable `zone-drop:drop` event (detail: { source }) before
 * submitting, so a listener can run an optimistic animation first, or call
 * event.preventDefault() to take over entirely (e.g. to show a dialog
 * instead of submitting immediately, and submit later itself).
 */
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
