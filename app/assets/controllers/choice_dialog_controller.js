import { Controller } from '@hotwired/stimulus';

// How to use, see
// docs/components/optimism.md
export default class extends Controller {
    stashedForm = null;
    stashedName = null;
    openPopout = null;
    outsideClickListener = null;
    keydownListener = null;

    require(event) {
        const form = event.target;
        const input = form.querySelector(`[name="${event.params.name}"]`);
        if (input && input.value) {
            return;
        }

        event.preventDefault();

        const popout = this.element.querySelector(event.params.dialog);
        if (!popout) {
            return;
        }

        this.stashedForm = form;
        this.stashedName = event.params.name;
        this.openPopout = popout;
        popout.classList.remove('hidden');

        this.outsideClickListener = (e) => {
            if (!popout.contains(e.target)) {
                this.cancel();
            }
        };
        this.keydownListener = (e) => {
            if ('Escape' === e.key) {
                this.cancel();
            }
        };
        document.addEventListener('click', this.outsideClickListener);
        document.addEventListener('keydown', this.keydownListener);
    }

    pick(event) {
        const form = this.stashedForm;
        const input = this.stashedName ? form?.querySelector(`[name="${this.stashedName}"]`) : null;
        if (input) {
            input.value = event.params.value;
        }
        this.hidePopout();
        form?.requestSubmit();
    }

    // Popout dismissed without picking a value - an outside click or Escape.
    cancel() {
        const input = this.stashedForm && this.stashedName ? this.stashedForm.querySelector(`[name="${this.stashedName}"]`) : null;
        if (input) {
            input.value = '';
        }
        this.hidePopout();
    }

    hidePopout() {
        this.openPopout?.classList.add('hidden');
        this.openPopout = null;
        if (this.outsideClickListener) {
            document.removeEventListener('click', this.outsideClickListener);
            this.outsideClickListener = null;
        }
        if (this.keydownListener) {
            document.removeEventListener('keydown', this.keydownListener);
            this.keydownListener = null;
        }
        this.stashedForm = null;
        this.stashedName = null;
    }

    disconnect() {
        this.hidePopout();
    }
}
