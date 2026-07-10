import { Controller } from '@hotwired/stimulus';

/*
 * Optimistic Yahtzee feedback:
 *  - holding/releasing a die toggles its ring immediately,
 *  - rolling starts an endless wobble on every unheld die until the server
 *    morph swaps in the real results,
 *  - scoring replaces the "+points" button with the score right away.
 * The server response corrects everything if a move was rejected.
 */
export default class extends Controller {
    static targets = ['die'];

    hold(event) {
        const button = event.currentTarget;
        button.classList.toggle('ring-2');
        button.classList.toggle('ring-softblue-300');
    }

    roll() {
        for (const die of this.dieTargets) {
            if ('true' !== die.dataset.locked) {
                die.classList.add('anim-rolling');
            }
        }
    }

    score(event) {
        const button = event.target.querySelector('button');
        if (!button) {
            return;
        }
        const points = document.createElement('span');
        points.className = 'anim-pop text-xl font-extrabold text-warmgray-700';
        points.textContent = button.textContent.trim().replace(/^\+/, '');
        button.replaceWith(points);
    }
}
