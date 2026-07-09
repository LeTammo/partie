import { Controller } from '@hotwired/stimulus';

/*
 * Optimistic Connect Four: dropping into a column immediately animates the
 * viewer's disc into the lowest free cell. The server morph confirms it or
 * takes it away again (plus an error flash).
 */
export default class extends Controller {
    static values = { outer: String, inner: String };

    drop(event) {
        const column = event.target.querySelector('input[name="column"]')?.value;
        if (undefined === column || '' === this.outerValue) {
            return;
        }

        const free = [...this.element.querySelectorAll(`[data-col="${column}"]`)]
            .filter((cell) => null === cell.querySelector('span'))
            .pop();
        if (!free) {
            return;
        }

        const disc = document.createElement('span');
        disc.className = 'anim-drop pointer-events-none grid size-9 place-items-center rounded-full shadow-soft sm:size-11';
        disc.style.backgroundColor = this.outerValue;
        const pip = document.createElement('span');
        pip.className = 'size-4 rounded-full sm:size-5';
        pip.style.backgroundColor = this.innerValue;
        disc.appendChild(pip);
        free.appendChild(disc);
    }
}
