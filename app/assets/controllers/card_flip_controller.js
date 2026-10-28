import { Controller } from '@hotwired/stimulus';

// Alternate-input form submit for cards that can be turned over: middle-click,
// double-click, or pressing a key while hovering the card submits a flip.
// Attach once to the game root; each flippable card wires the actions.
//
// Two ways to wire the actual submission, pick whichever fits the card's own
// markup:
// - the card sits inside its own `<form>` (whose hidden fields say what
//   "flipping" means) - that form is submitted directly, no targets needed.
// - the card has no form of its own (e.g. a plain button that's also a
//   piece-move source/zone and can't be wrapped without breaking that) -
//   give it `data-flip-uid="{{ uid }}"` and wire one shared hidden form via
//   the `form`/`uid` targets once per game root.
//
// How to use, see
// docs/components/cards.md
//
// Values:
// - key: the hotkey while hovering (default 'f')
//
// Targets (only needed for the shared-form case):
// - form: the shared flip form
// - uid:  its hidden uid input
//
// Actions on the card element:
//   mousedown->card-flip#holdMiddleClick
//   auxclick->card-flip#flip dblclick->card-flip#flip
//   mouseenter->card-flip#arm mouseleave->card-flip#disarm
export default class extends Controller {
    static targets = ['form', 'uid'];
    static values = { key: { type: String, default: 'f' } };

    connect() {
        this.hovered = null;
        this.onKeydown = (event) => {
            if (
                this.hovered
                && !event.repeat
                && event.key.toLowerCase() === this.keyValue
                && !event.target.closest('input, textarea, select, [contenteditable]')
            ) {
                this.submit(this.hovered);
            }
        };
        document.addEventListener('keydown', this.onKeydown);
    }

    disconnect() {
        document.removeEventListener('keydown', this.onKeydown);
        this.hovered = null;
    }

    arm(event) {
        this.hovered = event.currentTarget;
    }

    disarm() {
        this.hovered = null;
    }

    // middle-click would otherwise start autoscroll
    holdMiddleClick(event) {
        if (event.button === 1) {
            event.preventDefault();
        }
    }

    flip(event) {
        if (event.type === 'auxclick' && event.button !== 1) {
            return;
        }
        event.preventDefault();
        this.submit(event.currentTarget);
    }

    submit(el) {
        this.hovered = null;
        const form = el.closest('form');
        if (form) {
            form.requestSubmit();
            return;
        }
        if (this.hasFormTarget && this.hasUidTarget && el.dataset.flipUid) {
            this.uidTarget.value = el.dataset.flipUid;
            this.formTarget.requestSubmit();
        }
    }
}
