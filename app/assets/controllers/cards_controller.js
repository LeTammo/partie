import { Controller } from '@hotwired/stimulus';

// How to use, see
// docs/components/cards.md
export default class extends Controller {
    static targets = ['card', 'input'];

    toggle(event) {
        const card = event.currentTarget;
        card.classList.toggle('ring-4');
        card.classList.toggle('ring-softblue-300');
        card.classList.toggle('-translate-y-2');
        this.sync();
    }

    clear() {
        for (const card of this.cardTargets) {
            card.classList.remove('ring-4', 'ring-softblue-300', '-translate-y-2');
        }
        this.sync();
    }

    sync() {
        const selected = this.cardTargets
            .filter((card) => card.classList.contains('ring-4'))
            .map((card) => card.dataset.index)
            .join(',');
        for (const input of this.inputTargets) {
            input.value = selected;
        }
    }
}
