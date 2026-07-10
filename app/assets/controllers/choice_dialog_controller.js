import { Controller } from '@hotwired/stimulus';

// How to use, see
// docs/components/optimism.md
export default class extends Controller {
    stashedForm = null;
    stashedName = null;

    require(event) {
        const form = event.target;
        const input = form.querySelector(`[name="${event.params.name}"]`);
        if (input && input.value) {
            return;
        }

        event.preventDefault();
        this.stashedForm = form;
        this.stashedName = event.params.name;
        this.element.querySelector(event.params.dialog)?.showModal();
    }

    pick(event) {
        const form = this.stashedForm;
        if (!form) {
            return;
        }

        const input = this.stashedName ? form.querySelector(`[name="${this.stashedName}"]`) : null;
        if (input) {
            input.value = event.params.value;
        }
        event.currentTarget.closest('dialog')?.close();
        form.requestSubmit();
    }

    closed() {
        const input = this.stashedForm && this.stashedName ? this.stashedForm.querySelector(`[name="${this.stashedName}"]`) : null;
        if (input) {
            input.value = '';
        }
        this.stashedForm = null;
        this.stashedName = null;
    }
}
