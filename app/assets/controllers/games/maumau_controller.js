import { Controller } from '@hotwired/stimulus';
import { glideFrom, spawnGhost } from '../../animation.js';

/*
 * Mau-Mau hand interactions, layered on top of two "zone_drop" drag-and-drop
 * type instances (see assets/controllers/dragdrop/zone_drop_controller.js)
 * that own the raw drag mechanics for "drag a card onto the discard pile"
 * (data-pair="play") and "drag the pile onto the hand" (data-pair="draw").
 * zone_drop auto-submits the dragged element's <form> on a valid drop, so
 * this controller only adds what's genuinely Mau-Mau-specific:
 *  - jacks detour through the wish-suit dialog instead of submitting
 *    immediately, whether played by click or by drag,
 *  - the optimistic glide/ghost animations,
 *  - locking the draw pile after it's used, so a stacked penalty (which
 *    must be drawn one card at a time) can't fire off two requests at once.
 */
export default class extends Controller {
    static targets = ['dropzone', 'dialog', 'handzone'];

    pendingForm = null;

    /** Bound to zone-drop:drop, fires for both a click-triggered and a drag-triggered play. */
    handlePlayDrop(event) {
        if ('play' !== event.detail.source.dataset.pair) {
            return;
        }

        const form = event.detail.source.closest('form');
        if ('true' === form.dataset.jack) {
            event.preventDefault(); // cancels zone_drop's auto-submit; wish() submits later
            this.openWishDialog(form);
            return;
        }
        this.animatePlay(form);
    }

    /** Bound to a jack's own click (jacks are type="button", so they never submit natively). */
    play(event) {
        this.openWishDialog(event.currentTarget.closest('form'));
    }

    openWishDialog(form) {
        this.pendingForm = form;
        this.dialogTarget.showModal();
    }

    /**
     * Optimistic play: the card glides from the hand onto the discard pile
     * before the server answers. Its DOM id is rewritten to the id the
     * server will render for the new top card, so the confirming morph
     * patches in place; a rejected move morphs the card back into the hand.
     */
    animatePlay(form) {
        const card = form.querySelector('[data-flip-id]');
        const top = this.dropzoneTarget.querySelector('[data-flip-id]');
        if (!card || !top) {
            return;
        }

        const before = card.getBoundingClientRect();
        card.id = `mm-top-${card.dataset.flipId.slice(3)}`;
        top.replaceWith(card);
        glideFrom(card, before);
        form.style.display = 'none';
    }

    wish(event) {
        const form = this.pendingForm;
        this.pendingForm = null;
        this.dialogTarget.close();
        if (!form) {
            return;
        }

        let input = form.querySelector('input[name="wish"]');
        if (!input) {
            input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'wish';
            form.appendChild(input);
        }
        input.value = event.currentTarget.dataset.suit;
        this.animatePlay(form);
        form.requestSubmit();
    }

    dialogClosed() {
        this.pendingForm = null;
    }

    /** Bound to the draw form's submit event: fires for both a click and a zone_drop-triggered drag submission. */
    draw(event) {
        this.animateDraw();
        const button = event.target.querySelector('button[type="submit"]');
        if (button) {
            button.disabled = true;
            button.removeAttribute('draggable');
        }
    }

    /**
     * Optimistic draw: a face-down ghost glides from the pile into the hand.
     * The actual drawn card can't be shown yet (the deck is server-side), so
     * the real reveal happens moments later when the morph deals the new
     * card into the hand with its own entry animation.
     */
    animateDraw() {
        const pileCard = document.getElementById('mm-pile-back');
        if (!pileCard || !this.hasHandzoneTarget) {
            return;
        }

        const before = pileCard.getBoundingClientRect();
        spawnGhost(pileCard.cloneNode(true), before, {
            travelTo: this.handzoneTarget,
            duration: 350,
            safetyNet: 800,
        });
    }
}
