import { Controller } from '@hotwired/stimulus';

/*
 * Optimistic Tic Tac Toe: the viewer's symbol pops into the cell the moment
 * the move is submitted. The server response morphs the page and either
 * confirms the symbol or removes it again (plus an error flash).
 */
export default class extends Controller {
    static values = { variant: String, color: String };

    place(event) {
        const button = event.target.querySelector('button');
        if (!button || '' === this.variantValue || button.querySelector('span')) {
            return;
        }

        const symbol = document.createElement('span');
        symbol.className = 'anim-pop pointer-events-none text-4xl font-extrabold sm:text-5xl';
        symbol.style.color = this.colorValue;
        symbol.textContent = 'x' === this.variantValue ? '✕' : '◯';
        button.appendChild(symbol);
    }
}
