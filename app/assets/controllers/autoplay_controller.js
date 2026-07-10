import { Controller } from '@hotwired/stimulus';

// How to use, see
// docs/components/engine-and-state.md
export default class extends Controller {
    static values = {
        url: String,
        token: String,
        step: Number,
        delay: { type: Number, default: 1000 },
    };

    connect() {
        this.schedule();
    }

    disconnect() {
        clearTimeout(this.timer);
    }

    stepValueChanged() {
        this.schedule();
    }

    schedule() {
        clearTimeout(this.timer);
        this.timer = setTimeout(() => this.tick(), this.delayValue);
    }

    async tick() {
        try {
            await fetch(this.urlValue, {
                method: 'POST',
                body: new URLSearchParams({ _token: this.tokenValue, step: this.stepValue }),
            });
        } catch {
        }
    }
}
