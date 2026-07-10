import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static values = { url: String, token: String, interval: { type: Number, default: 25000 } };

    connect() {
        this.ping();
        this.timer = setInterval(() => this.ping(), this.intervalValue);
    }

    disconnect() {
        clearInterval(this.timer);
    }

    ping() {
        fetch(this.urlValue, {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            body: new URLSearchParams({ _token: this.tokenValue }),
        }).catch(() => {});
    }
}
