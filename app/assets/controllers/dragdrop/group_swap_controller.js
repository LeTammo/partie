import { Controller } from '@hotwired/stimulus';
import { glideFrom } from '../../animation.js';
import { startDrag, hover, unhover, markTargets, clearTargets } from '../../dragdrop.js';

// How to use, see
// docs/components/cards.md
export default class extends Controller {
    static targets = ['item', 'submitButton', 'swapAllButton'];
    static values = { idTemplate: String };

    disconnect() {
        this.dragging = null;
    }

    beforeSubmit(event) {
        if (event.submitter === this.submitButtonTarget) {
            const [a, b] = this.checkedPair();
            if (a && b) {
                this.swap(a, b);
            }
        } else if (this.hasSwapAllButtonTarget && event.submitter === this.swapAllButtonTarget) {
            this.swapAllPairs();
        }
    }

    swapAllPairs() {
        const [groupA, groupB] = this.groups();
        groupA.forEach((a, i) => {
            if (groupB[i]) {
                this.swap(a, groupB[i]);
            }
        });
    }

    swap(itemA, itemB) {
        const cardA = itemA.querySelector('[data-flip-id]');
        const cardB = itemB.querySelector('[data-flip-id]');
        if (!cardA || !cardB) {
            return;
        }

        const rectA = cardA.getBoundingClientRect();
        const rectB = cardB.getBoundingClientRect();
        const parentA = cardA.parentElement;
        cardB.parentElement.appendChild(cardA);
        parentA.appendChild(cardB);

        if (this.hasIdTemplateValue) {
            cardA.id = this.renderId(itemB, cardA);
            cardB.id = this.renderId(itemA, cardB);
        }

        glideFrom(cardA, rectA);
        glideFrom(cardB, rectB);
    }

    renderId(slotItem, card) {
        const identity = card.dataset.flipId.split('-').slice(1).join('-');

        return this.idTemplateValue
            .replace('{group}', this.groupOf(slotItem))
            .replace('{slot}', slotItem.dataset.slot)
            .replace('{identity}', identity);
    }

    dragStart(event) {
        const item = event.currentTarget;
        const input = item.querySelector('input');
        if (input?.disabled || !startDrag(event, this.groupOf(item))) {
            event.preventDefault();
            return;
        }

        this.dragging = item;
        markTargets(this.itemTargets.filter((i) => !this.sameGroup(i, item)));
    }

    dragOver(event) {
        hover(event, event.currentTarget, !!this.dragging && !this.sameGroup(event.currentTarget, this.dragging));
    }

    dragLeave(event) {
        unhover(event.currentTarget);
    }

    drop(event) {
        const dragging = this.dragging;
        this.dragging = null;
        this.clearHighlight();
        if (!dragging || this.sameGroup(event.currentTarget, dragging)) {
            return;
        }
        event.preventDefault();

        for (const item of [dragging, event.currentTarget]) {
            const input = item.querySelector('input');
            if (input) {
                input.checked = true;
            }
        }
        if (this.hasSubmitButtonTarget) {
            this.submitButtonTarget.form.requestSubmit(this.submitButtonTarget);
        }
    }

    dragEnd() {
        this.dragging = null;
        this.clearHighlight();
    }

    groupOf(item) {
        return item.querySelector('input')?.name;
    }

    sameGroup(a, b) {
        return this.groupOf(a) === this.groupOf(b);
    }

    groups() {
        const names = [...new Set(this.itemTargets.map((i) => this.groupOf(i)))];
        return names.map((name) => this.itemTargets.filter((i) => this.groupOf(i) === name));
    }

    checkedPair() {
        const [groupA, groupB] = this.groups();
        return [
            groupA.find((i) => i.querySelector('input')?.checked),
            groupB.find((i) => i.querySelector('input')?.checked),
        ];
    }

    clearHighlight() {
        clearTargets(this.itemTargets);
    }
}
