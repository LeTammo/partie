import { Controller } from '@hotwired/stimulus';
import * as Turbo from '@hotwired/turbo';
import { glideFrom } from '../../animation.js';
import { CELL_SELECTED_CLASS, CELL_TARGET_CLASS } from '../../dragdrop.js';

// The one map-driven movement controller: pick a piece (click or drag),
// drop it on a legal zone, submit `from`/`to` zone keys through the hidden
// form. Zone keys are opaque strings - grid cells ("cell:x:y"), card piles
// ("tableau:3"), track squares ("ring:17") all work the same way.
//
// How to use, see
// docs/components/tokens-and-boards.md (boards) and docs/components/cards.md (piles)
//
// Values:
// - moves:      { sourceKey: [destZoneKey, ...] } - the legal-moves map
// - runs:       picking a source also picks its following siblings (Solitaire runs)
// - captureDistance: moving exactly N columns fades the piece midway
//                    (requires keys ending in ":x:y", e.g. Checkers jumps)
// - choices:    zone keys that submit the `choice` input when clicked
//               (Checkers' sacrifice pick)
// - autoSelect: preselect the only movable piece when there is exactly one
// - freeDrag:   any source may start a drag, even without a legal move -
//               the drop just never lands (natural card-game feel)
// - dragHighlight: while dragging, only zones whose key starts with this
//               prefix get the target highlight ('foundation:'); click-to-
//               move still highlights everything
//
// Targets:
// - form/from/to: the hidden move form and its inputs
// - choice:     hidden input filled by a choices-click (optional)
// - source:     pickable piece, keyed by data-source
// - zone:       drop target, keyed by data-zone; data-mode="replace" makes
//               the optimistic drop replace the zone's children (a pile
//               showing only its top card); data-mode="none" submits the
//               move without touching the DOM (the piece stays where it is
//               until the server render decides - an offer, a claim)
export default class extends Controller {
    static targets = ['form', 'from', 'to', 'choice', 'source', 'zone'];
    static values = {
        moves: Object,
        runs: Boolean,
        captureDistance: Number,
        choices: Array,
        autoSelect: Boolean,
        freeDrag: Boolean,
        dragHighlight: String,
    };

    connect() {
        this.selected = null;
        this.dragging = false;
        this.submitQueue = Promise.resolve();
        this.pendingSubmits = 0;
        this.suppressMorph = this.suppressMorph.bind(this);
        document.addEventListener('turbo:before-render', this.suppressMorph);
        this.autoSelectSingleOrigin();
        this.paint();
    }

    disconnect() {
        document.removeEventListener('turbo:before-render', this.suppressMorph);
    }

    // a morph confirms only the ONE request it was generated for - if this
    // client already has a later action queued behind it (rapid consecutive
    // moves, each animated optimistically the instant it's clicked, well
    // before any of them hear back), applying that stale morph would revert
    // the DOM to "only the first move happened" and then immediately have to
    // re-advance it again once the next response lands, a visible snap-back.
    // Skip every morph but the last one in the queue; once it drains, the
    // final morph reflects every move's cumulative effect at once.
    suppressMorph(event) {
        if (this.pendingSubmits > 1 && 'morph' === event.detail.renderMethod) {
            event.preventDefault();
        }
    }

    // a fresh render (an own move's confirmation, or another player's morph
    // arriving via Mercure) must not cancel a selection that is still valid -
    // otherwise a fast follow-up click (pick the next card, drop it) can
    // straddle the exact instant the morph lands and get silently eaten,
    // making rapid consecutive actions feel randomly "blocked"
    movesValueChanged() {
        if (null !== this.selected && !this.movesValue[this.selected]) {
            this.selected = null;
        }
        this.autoSelectSingleOrigin();
        this.paint();
    }

    // click on a pickable piece
    pick(event) {
        const key = event.currentTarget.dataset.source;
        if (!this.movesValue[key]) {
            return;
        }
        event.stopPropagation();
        this.selected = this.selected === key ? null : key;
        this.paint();
    }

    // click on a zone: choice-pick, completing a pending move, or own-piece toggle
    pickZone(event) {
        const el = event.currentTarget;
        const zone = el.dataset.zone;
        const sourceKey = el.dataset.source ?? this.sourceKeyInside(el);

        if (this.hasChoicesValue && zone && this.choicesValue.includes(zone)) {
            this.submitChoice(zone);
            return;
        }

        // a pending selection that can legally land here wins over re-picking
        // this zone's own single piece - otherwise a zone that is both a
        // one-piece source and a legal destination (Durak: dropping a card
        // onto the stock, discard or another single-card hand) could never
        // be dropped onto by click, only by drag
        if (null !== this.selected && sourceKey !== this.selected && this.isLegalTarget(zone)) {
            this.submitMove(zone);
            return;
        }

        if (sourceKey && this.movesValue[sourceKey]) {
            this.selected = this.selected === sourceKey ? null : sourceKey;
            this.paint();
            return;
        }

        if (this.selected === null || !this.isLegalTarget(zone)) {
            return;
        }
        this.submitMove(zone);
    }

    dragStart(event) {
        const key = event.currentTarget.dataset.source ?? this.sourceKeyInside(event.currentTarget);
        if (!key || (!this.movesValue[key] && !this.freeDragValue)) {
            event.preventDefault();
            return;
        }
        this.selected = key;
        this.dragging = true;
        this.paint();
        event.dataTransfer.setData('text/plain', key);
        event.dataTransfer.effectAllowed = 'move';
    }

    dragOver(event) {
        if (this.selected !== null && this.isLegalTarget(event.currentTarget.dataset.zone)) {
            event.preventDefault();
            event.dataTransfer.dropEffect = 'move';
        }
    }

    drop(event) {
        const zone = event.currentTarget.dataset.zone;
        if (this.selected === null || !this.isLegalTarget(zone)) {
            return;
        }
        event.preventDefault();
        this.submitMove(zone);
    }

    dragEnd() {
        this.dragging = false;
        if (this.selected !== null && !this.movesValue[this.selected]) {
            this.selected = null;
        }
        this.paint();
    }

    submitMove(toZone) {
        const from = this.selected;
        this.animateMove(from, toZone);

        this.selected = null;
        this.dragging = false;
        this.paint();

        this.enqueueSubmit(() => {
            this.fromTarget.value = from;
            this.toTarget.value = toZone;
        });
    }

    submitChoice(key) {
        this.pieceFor(key)?.classList.add('ghost-fade');

        this.enqueueSubmit(() => {
            this.choiceTarget.value = key;
        });
    }

    // rapid consecutive moves must reach the server in the order they were
    // made: two fetches fired back to back can arrive out of order, getting
    // the second rejected as "physically impossible" (someone else was
    // faster) purely because it landed first - even though nothing else was
    // actually involved. The optimistic move already happened instantly
    // above; only the network request itself waits its turn.
    //
    // a submission can come back with no real fetchResponse at all (an
    // overloaded dev server, a dropped connection) rather than a clean
    // success/failure - and there is no way to tell from here whether the
    // server actually executed the move before answering. Retrying would
    // risk running a non-idempotent action twice (drawing two cards
    // instead of one); silently accepting it would leave the optimistic
    // move stranded if it in fact never landed. Either way the only safe
    // response is to stop guessing and resync with whatever the server
    // actually has.
    enqueueSubmit(fillForm) {
        this.pendingSubmits++;
        this.submitQueue = this.submitQueue.then(() => new Promise((resolve) => {
            const done = (event) => {
                this.formTarget.removeEventListener('turbo:submit-end', done);
                clearTimeout(timeout);
                this.pendingSubmits--;
                if (!event?.detail?.success) {
                    Turbo.visit(window.location.href, { action: 'replace' });
                }
                resolve();
            };
            const timeout = setTimeout(() => done(null), 5000);
            this.formTarget.addEventListener('turbo:submit-end', done, { once: true });
            fillForm();
            this.formTarget.requestSubmit();
        }));
    }

    animateMove(fromKey, toZone) {
        const piece = this.pieceFor(fromKey);
        const target = this.zoneTargets.find((el) => el.dataset.zone === toZone);
        if (!piece || !target || target.dataset.mode === 'none') {
            return;
        }

        // a piece's immediate parent can opt in/out of runs itself
        // (data-runs="true"/"false"), overriding the controller-wide default -
        // Durak mixes both a runs zone (a stacked pile in the open middle,
        // taking a card also takes whatever is stacked on it) and plain
        // single-card zones (a hand, a stock pile) in the same controller
        const runs = undefined !== piece.parentElement.dataset.runs
            ? 'true' === piece.parentElement.dataset.runs
            : this.runsValue;
        const moving = runs
            ? [piece, ...Array.from(piece.parentElement.children).slice(
                Array.from(piece.parentElement.children).indexOf(piece) + 1,
            )]
            : [piece];

        if (target.dataset.mode === 'replace') {
            target.replaceChildren();
        }

        moving.forEach((el) => {
            const before = el.getBoundingClientRect();
            target.appendChild(el);
            glideFrom(el, before);
        });

        this.fadeCapturedPiece(fromKey, toZone);
    }

    // capture-by-jump: fade whatever sits on the midpoint square
    fadeCapturedPiece(fromKey, toZone) {
        if (!this.hasCaptureDistanceValue) {
            return;
        }
        const from = this.coordsOf(fromKey);
        const to = this.coordsOf(toZone);
        if (!from || !to || Math.abs(to[0] - from[0]) !== this.captureDistanceValue) {
            return;
        }
        const prefix = toZone.split(':').slice(0, -2).join(':');
        const midKey = `${prefix}:${(from[0] + to[0]) / 2}:${(from[1] + to[1]) / 2}`;
        const captured = this.pieceFor(midKey);
        if (captured) {
            captured.classList.add('ghost-fade');
            setTimeout(() => captured.remove(), 400);
        }
    }

    isLegalTarget(zone) {
        return !!zone && (this.movesValue[this.selected] || []).includes(zone);
    }

    // the piece element for a source key: an explicit source target, or the
    // flip-enabled piece inside the matching zone (grid cells)
    pieceFor(key) {
        const source = this.sourceTargets.find((el) => el.dataset.source === key);
        if (source) {
            return source;
        }

        return this.zoneTargets
            .find((el) => el.dataset.zone === key)
            ?.querySelector('[data-flip-id]') ?? null;
    }

    // a zone speaks for its piece only when it holds exactly one (a grid cell);
    // multi-source zones (a tableau column) never pick on background clicks
    sourceKeyInside(zoneEl) {
        const sources = zoneEl.querySelectorAll('[data-source]');

        return 1 === sources.length ? sources[0].dataset.source : null;
    }

    // trailing ":x:y" of a key, if numeric
    coordsOf(key) {
        const parts = key.split(':');
        if (parts.length < 2) {
            return null;
        }
        const x = Number(parts[parts.length - 2]);
        const y = Number(parts[parts.length - 1]);

        return Number.isInteger(x) && Number.isInteger(y) ? [x, y] : null;
    }

    autoSelectSingleOrigin() {
        if (!this.autoSelectValue) {
            return;
        }
        const origins = Object.keys(this.movesValue);
        if (origins.length === 1) {
            this.selected = origins[0];
        }
    }

    paint() {
        let targets = this.selected ? this.movesValue[this.selected] || [] : [];
        // the dragged piece follows the cursor - no selection box, and only
        // the configured zones (e.g. foundations) light up as targets
        const showSelected = !this.dragging;
        if (this.dragging && this.dragHighlightValue) {
            targets = targets.filter((zone) => zone.startsWith(this.dragHighlightValue));
        }

        this.sourceTargets.forEach((el) => {
            el.classList.toggle(CELL_SELECTED_CLASS, showSelected && el.dataset.source === this.selected);
        });
        this.zoneTargets.forEach((el) => {
            const ownSource = el.dataset.source ?? this.sourceKeyInside(el);
            el.classList.toggle(CELL_SELECTED_CLASS, showSelected && !!ownSource && ownSource === this.selected);
            el.classList.toggle(CELL_TARGET_CLASS, targets.includes(el.dataset.zone));
        });
    }
}
