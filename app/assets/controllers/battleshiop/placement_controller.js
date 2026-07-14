import { Controller } from '@hotwired/stimulus';

// Battleships's shape placement: arm a shape from the tray, rotate/mirror
// it through its deduped orientation list, preview its footprint while
// hovering (or press-and-slide on touch), tap/click to commit.
//
// This is deliberately its own controller rather than an extension of
// dragdrop--piece-move: that controller's model is "pick a single-cell
// source, submit a from/to zone pair" for an existing piece - placing a
// new, rotatable, multi-cell shape onto empty cells with a live ghost
// footprint is a different interaction shape entirely.
const GHOST_VALID_CLASS = '!bg-sage-300';
const GHOST_INVALID_CLASS = 'bg-terracotta-200';

export default class extends Controller {
    static targets = ['cell', 'tray', 'form', 'index', 'x', 'y', 'orientation'];
    static values = {
        shapes: Object, // shape key => list of orientations, each a list of [x, y]
        pool: Array, // [{index, shape, placed}, ...]
        occupied: Array, // ["x:y", ...] already-filled own cells
        gridWidth: Number,
        gridHeight: Number,
    };

    connect() {
        this.rearm();
    }

    // Fires on connect (with the initial value) and again after every
    // placement, since a successful `place` re-renders the pool/board in
    // place via Turbo morph rather than a full page reload - without this,
    // the controller would keep the just-placed (now-occupied) shape armed
    // instead of moving on to the next unplaced one. Self-contained (reads
    // nothing set by connect()) so it's correct regardless of which of the
    // two fires first.
    poolValueChanged() {
        this.rearm();
    }

    rearm() {
        this.painted = [];
        this.lastAnchor = null;
        this.cellByKey = new Map();
        this.cellTargets.forEach((el) => this.cellByKey.set(`${el.dataset.x}:${el.dataset.y}`, el));

        if (this.isPlaced(this.armedIndex)) {
            this.armedIndex = this.firstUnplacedIndex();
            this.orientationIndex = 0;
        }
        this.paintTray();
    }

    isPlaced(index) {
        const entry = this.poolValue.find((e) => e.index === index);
        return !entry || entry.placed;
    }

    armFromTray(event) {
        this.armedIndex = Number(event.currentTarget.dataset.index);
        this.orientationIndex = 0;
        this.clearGhost();
        this.paintTray();
    }

    rotate() {
        const orientations = this.currentOrientations();
        if (!orientations) {
            return;
        }
        this.orientationIndex = (this.orientationIndex + 1) % orientations.length;
        if (this.lastAnchor) {
            this.paintGhost(this.lastAnchor);
        }
    }

    wheel(event) {
        if (null === this.armedIndex) {
            return;
        }
        event.preventDefault();
        this.rotate();
    }

    hover(event) {
        const anchor = this.anchorOf(event.currentTarget);
        if (!anchor) {
            return;
        }
        this.lastAnchor = anchor;
        this.paintGhost(anchor);
    }

    leave() {
        this.lastAnchor = null;
        this.clearGhost();
    }

    place(event) {
        const anchor = this.anchorOf(event.currentTarget);
        if (!anchor) {
            return;
        }
        const cells = this.footprint(anchor);
        if (!cells || !this.isValid(cells)) {
            return;
        }

        this.indexTarget.value = this.armedIndex;
        this.xTarget.value = anchor.x;
        this.yTarget.value = anchor.y;
        this.orientationTarget.value = this.orientationIndex;
        this.formTarget.requestSubmit();
    }

    anchorOf(el) {
        if (null === this.armedIndex || undefined === el.dataset.x) {
            return null;
        }
        return { x: Number(el.dataset.x), y: Number(el.dataset.y) };
    }

    currentShape() {
        const entry = this.poolValue.find((e) => e.index === this.armedIndex);
        return entry ? entry.shape : null;
    }

    currentOrientations() {
        const shape = this.currentShape();
        return shape ? this.shapesValue[shape] : null;
    }

    footprint(anchor) {
        const orientations = this.currentOrientations();
        if (!orientations) {
            return null;
        }
        const cells = orientations[this.orientationIndex] ?? orientations[0];
        return cells.map(([dx, dy]) => [anchor.x + dx, anchor.y + dy]);
    }

    isValid(cells) {
        return cells.every(([x, y]) => this.inBounds(x, y) && !this.occupiedValue.includes(`${x}:${y}`));
    }

    inBounds(x, y) {
        return x >= 0 && y >= 0 && x < this.gridWidthValue && y < this.gridHeightValue;
    }

    paintGhost(anchor) {
        this.clearGhost();
        const cells = this.footprint(anchor);
        if (!cells) {
            return;
        }
        const valid = this.isValid(cells);
        cells.forEach(([x, y]) => {
            const cell = this.cellByKey.get(`${x}:${y}`);
            if (!cell) {
                return;
            }
            cell.classList.add(valid ? GHOST_VALID_CLASS : GHOST_INVALID_CLASS);
            this.painted.push(cell);
        });
    }

    clearGhost() {
        this.painted.forEach((cell) => cell.classList.remove(GHOST_VALID_CLASS, GHOST_INVALID_CLASS));
        this.painted = [];
    }

    firstUnplacedIndex() {
        const entry = this.poolValue.find((e) => !e.placed);
        return entry ? entry.index : null;
    }

    paintTray() {
        this.trayTargets.forEach((el) => {
            const armed = Number(el.dataset.index) === this.armedIndex;
            el.classList.toggle('ring-2', armed);
            el.classList.toggle('ring-sage-400', armed);
        });
    }
}
