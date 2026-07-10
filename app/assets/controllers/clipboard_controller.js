import { Controller } from '@hotwired/stimulus';

/*
 * Copies a value to the clipboard and shows a confirmation state.
 */
export default class extends Controller {
    static values = { text: String };
    static targets = ['idle', 'done'];

    copy() {
        navigator.clipboard.writeText(this.textValue).then(() => {
            this.idleTarget.style.display = 'none';
            this.doneTarget.style.display = '';

            clearTimeout(this.timeout);
            this.timeout = setTimeout(() => this.reset(), 2000);
        });
    }

    reset() {
        this.idleTarget.style.display = '';
        this.doneTarget.style.display = 'none';
    }

    disconnect() {
        clearTimeout(this.timeout);
    }
}
