import * as Turbo from '@hotwired/turbo';

let busy = false;
let pending = false;
let watchdog = null;

document.addEventListener('turbo:before-stream-render', (event) => {
    const stream = event.detail?.newStream ?? event.target;
    if (!stream || stream.getAttribute('action') !== 'refresh') {
        return;
    }
    event.preventDefault();
    requestRefresh();
});

function requestRefresh() {
    if (busy) {
        pending = true;
        return;
    }
    busy = true;
    Turbo.session.refresh(document.baseURI);

    clearTimeout(watchdog);
    watchdog = setTimeout(settle, 4000);
}

function settle() {
    clearTimeout(watchdog);
    if (!busy) {
        return;
    }
    busy = false;
    if (pending) {
        pending = false;
        requestRefresh();
    }
}

document.addEventListener('turbo:load', settle);
