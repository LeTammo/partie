import { Controller } from '@hotwired/stimulus';

const STORAGE_KEY = 'partie.nickname';

/*
 * Saves the player's nickname in localStorage.
 */
export default class extends Controller {
    connect() {
        if (this.element.value === '') {
            const stored = localStorage.getItem(STORAGE_KEY);
            if (stored) {
                this.element.value = stored;
            }
        }
    }

    save() {
        localStorage.setItem(STORAGE_KEY, this.element.value.trim());
    }
}
