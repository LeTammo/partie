import { Controller } from '@hotwired/stimulus';
import { randomNickname } from '../lib/random_nickname.js';

export default class extends Controller {
    static targets = ['display', 'text', 'form', 'input', 'mirror'];
    static values = { url: String, token: String, name: String, live: Boolean, autosubmit: Boolean };

    connect() {
        if ('' === this.nameValue) {
            this.apply(randomNickname());
        }
        if (this.autosubmitValue) {
            this.element.requestSubmit();
        }
    }

    edit() {
        this.inputTarget.value = this.nameValue;
        this.displayTarget.style.display = 'none';
        this.formTarget.style.display = '';
        this.inputTarget.focus();
        this.inputTarget.select();
    }

    cancel() {
        this.formTarget.style.display = 'none';
        this.displayTarget.style.display = '';
    }

    save(event) {
        const value = this.inputTarget.value.trim();
        if ('' === value) {
            event.preventDefault();
            return;
        }

        if (this.liveValue) {
            event.preventDefault();
            this.apply(value);
            this.cancel();
        }
    }

    apply(name) {
        this.nameValue = name;
        if (this.hasTextTarget) {
            this.textTarget.textContent = name;
        }
        if (this.hasInputTarget) {
            this.inputTarget.value = name;
        }
        this.mirrorTargets.forEach((mirror) => {
            mirror.value = name;
        });

        if (this.liveValue) {
            fetch(this.urlValue, {
                method: 'POST',
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                body: new URLSearchParams({ _token: this.tokenValue, nickname: name }),
            }).catch(() => {});
        }
    }
}
