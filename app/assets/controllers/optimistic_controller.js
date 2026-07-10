import { Controller } from '@hotwired/stimulus';
import { glideFrom, spawnGhost } from '../animation.js';

// How to use, see
// docs/components/optimism.md
export default class extends Controller {
    insert(event) {
        if (event.defaultPrevented) {
            return;
        }
        const form = event.target;
        const template = form.querySelector('template');
        const into = this.resolveDestination(form, event.params.into, event.params.where);
        if (!template || !into) {
            return;
        }
        into.appendChild(template.content.cloneNode(true));
    }

    replace(event) {
        if (event.defaultPrevented) {
            return;
        }
        const form = event.target;
        const template = form.querySelector('template');
        const target = event.params.target ? this.element.querySelector(event.params.target) : form.querySelector('button');
        if (!template || !target) {
            return;
        }
        target.replaceWith(template.content.cloneNode(true));
    }

    move(event) {
        if (event.defaultPrevented) {
            return;
        }
        const form = event.target;
        const from = event.params.from ? this.element.querySelector(event.params.from) : form.querySelector('[data-flip-id]');
        const to = event.params.to ? this.element.querySelector(event.params.to) : null;
        if (!from || !to) {
            return;
        }

        const mode = event.params.mode || 'replace';
        const before = from.getBoundingClientRect();
        if (event.params.id) {
            from.id = event.params.id;
        }

        const occupant = 'replace' === mode ? to.querySelector('[data-flip-id]') : null;
        if (occupant) {
            occupant.replaceWith(from);
        } else {
            to.appendChild(from);
        }
        glideFrom(from, before);

        const hide = undefined !== event.params.hide ? event.params.hide : 'replace' === mode;
        if (hide) {
            form.style.display = 'none';
        }
    }

    ghost(event) {
        if (event.defaultPrevented) {
            return;
        }
        const form = event.target;
        const to = event.params.to ? this.element.querySelector(event.params.to) : null;
        const from = event.params.from
            ? document.querySelector(event.params.from)
            : (form.querySelector('[data-flip-id]') ?? form.querySelector('template')?.content.firstElementChild);
        if (!from || !to) {
            return;
        }
        spawnGhost(from.cloneNode(true), from.getBoundingClientRect(), { travelTo: to });
    }

    toggle(event) {
        if (event.defaultPrevented) {
            return;
        }
        this.classesOf(event.params.class).forEach((c) => event.currentTarget.classList.toggle(c));
    }

    pending(event) {
        if (event.defaultPrevented) {
            return;
        }
        const classes = this.classesOf(event.params.class);
        if (0 === classes.length || !event.params.selector) {
            return;
        }
        this.element.querySelectorAll(event.params.selector).forEach((el) => {
            if (event.params.unless && el.matches(event.params.unless)) {
                return;
            }
            el.classList.add(...classes);
        });
    }

    resolveDestination(form, selector, where) {
        if (!selector) {
            return form.querySelector('button');
        }
        const matches = [...this.element.querySelectorAll(selector)];
        if ('last-empty' === where) {
            return matches.reverse().find((el) => 0 === el.childElementCount);
        }
        return matches[0];
    }

    classesOf(value) {
        return (value || '').split(/\s+/).filter(Boolean);
    }
}
