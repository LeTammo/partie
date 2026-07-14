import { Controller } from '@hotwired/stimulus';

// A generic +/- stepper for a native number input - respects the input's
// own min/max/step via stepUp()/stepDown(), then fires a bubbling `change`
// so a wrapping form (e.g. the lobby settings' autosave) picks it up.
export default class extends Controller {
    static targets = ['input'];

    increment() {
        this.inputTarget.stepUp();
        this.dispatchChange();
    }

    decrement() {
        this.inputTarget.stepDown();
        this.dispatchChange();
    }

    dispatchChange() {
        this.inputTarget.dispatchEvent(new Event('change', { bubbles: true }));
    }
}
