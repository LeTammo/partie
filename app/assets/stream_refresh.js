import * as Turbo from '@hotwired/turbo';

let busy = false;
let pending = false;
let watchdog = null;

// Any Turbo Drive visit counts as "busy" – not just our own refreshes – so a
// Mercure "refresh" broadcast never races the user's OWN in-flight
// navigation (e.g. the redirect after their own form submission). Without
// this, a refresh triggered by the user's own broadcast could call
// Turbo.session.refresh() while that same action's redirect is still being
// followed, and Turbo would cancel one visit in favor of the other.
document.addEventListener('turbo:before-visit', () => {
    busy = true;
    clearTimeout(watchdog);
    watchdog = setTimeout(settle, 15000);
});

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
    watchdog = setTimeout(settle, 15000);
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
