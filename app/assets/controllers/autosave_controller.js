import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    save() {
        fetch(this.element.action, {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            body: new URLSearchParams(new FormData(this.element)),
        }).catch(() => {});
    }
}
