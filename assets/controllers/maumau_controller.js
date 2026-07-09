import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['dropzone', 'cardForm', 'dialog'];

    disconnect() {
        this.pendingForm = null;
    }

    dragStart(event) {
        const form = event.currentTarget.closest('form');
        event.dataTransfer.setData('text/plain', form.dataset.index);
        event.dataTransfer.effectAllowed = 'move';
        this.dropzoneTarget.classList.add('drop-ready');
    }

    dragEnd() {
        this.clearHighlight();
    }

    dragOver(event) {
        event.preventDefault();
        event.dataTransfer.dropEffect = 'move';
        this.dropzoneTarget.classList.add('drop-over');
    }

    dragLeave() {
        this.dropzoneTarget.classList.remove('drop-over');
    }

    drop(event) {
        event.preventDefault();
        this.clearHighlight();
        const index = event.dataTransfer.getData('text/plain');
        const form = this.cardFormTargets.find((el) => el.dataset.index === index);
        if (form) {
            this.playForm(form);
        }
    }

    play(event) {
        this.playForm(event.currentTarget.closest('form'));
    }

    playForm(form) {
        if ('true' === form.dataset.jack) {
            this.pendingForm = form;
            this.dialogTarget.showModal();
            return;
        }
        form.requestSubmit();
    }

    wish(event) {
        const form = this.pendingForm;
        this.pendingForm = null;
        this.dialogTarget.close();
        if (!form) {
            return;
        }

        let input = form.querySelector('input[name="wish"]');
        if (!input) {
            input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'wish';
            form.appendChild(input);
        }
        input.value = event.currentTarget.dataset.suit;
        form.requestSubmit();
    }

    dialogClosed() {
        this.pendingForm = null;
    }

    clearHighlight() {
        this.dropzoneTarget.classList.remove('drop-ready', 'drop-over');
    }
}
