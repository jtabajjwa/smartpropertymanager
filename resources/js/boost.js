// App-like navigation for a portal: links and forms load in the background
// and only the page body is swapped (htmx "boost"), so there are no full page
// reloads. Pages stay ordinary server-rendered Blade.
//
// Each portal layout declares <meta name="app-shell" content="…">. A response
// from a different shell (e.g. the login page after signing out, or the staff
// portal when leaving staff mode) or a non-HTML response (CSV export, PDF) is
// opened with a normal page load instead of being swapped in.
import htmx from 'htmx.org';

export function startBoost({ onNavigate } = {}) {
    window.htmx = htmx;

    // Alpine changes the DOM after load, so don't restore pages from htmx's
    // snapshot cache on Back: fetch them fresh (in the background) instead.
    htmx.config.historyCacheSize = 0;
    htmx.config.refreshOnHistoryMiss = false;
    htmx.config.scrollIntoViewOnBoost = false;

    const shell = document.querySelector('meta[name="app-shell"]')?.content;
    const progress = () => document.getElementById('app-progress');
    const shellOf = (html) => (html.match(/<meta name="app-shell" content="([^"]*)"/) || [])[1];

    document.addEventListener('htmx:beforeRequest', () => progress()?.classList.add('is-loading'));
    document.addEventListener('htmx:afterRequest', () => progress()?.classList.remove('is-loading'));

    // htmx sends a boosted form even when another handler has called
    // preventDefault(), so a form with @submit.prevent (e.g. one that opens the
    // confirm dialog first) would be posted at once, and again on confirming.
    // Leave those forms to their own handler.
    document.addEventListener('htmx:confirm', (e) => {
        const { elt, triggeringEvent } = e.detail;
        if (triggeringEvent?.type === 'submit' && elt.getAttributeNames?.().some((n) => /^(@|x-on:)submit(\..+)?\.prevent(\.|$)/.test(n))) {
            e.preventDefault();
        }
    });

    // One submit per form post: a second click (or Enter) while the first is on
    // its way would otherwise be queued by htmx, or re-posted by the browser,
    // and create the record twice. The form's submit buttons are disabled until
    // the response is in. GET forms (filters, exports) are left alone.
    const isPost = (form) => (form.getAttribute('method') || 'get').toLowerCase() === 'post'
        && (!form.target || form.target === '_self');   // a post into another tab never comes back to unlock
    const submitButtons = (form) => [
        ...form.querySelectorAll('button:not([type]), button[type="submit"], input[type="submit"]'),
        ...(form.id ? document.querySelectorAll(`[form="${CSS.escape(form.id)}"]`) : []),
    ];
    const lock = (form) => {
        form.dataset.submitting = '1';
        // After this tick, so the clicked button's name/value is still sent.
        setTimeout(() => submitButtons(form).forEach((b) => {
            if (!b.disabled) {
                b.disabled = true;
                b.dataset.submitLocked = '1';
                b.setAttribute('aria-busy', 'true');
            }
        }));
    };
    const unlock = (form) => {
        delete form.dataset.submitting;
        submitButtons(form).forEach((b) => {
            if (b.dataset.submitLocked) {
                b.disabled = false;
                delete b.dataset.submitLocked;
                b.removeAttribute('aria-busy');
            }
        });
    };

    // Capture phase: runs before htmx's own listener on the form, so a repeat never reaches it.
    document.addEventListener('submit', (e) => {
        if (e.target instanceof HTMLFormElement && e.target.dataset.submitting) {
            e.preventDefault();
            e.stopImmediatePropagation();
        }
    }, true);
    // A plain (non-boosted) post: bubble phase, and only if nothing cancelled it
    // (htmx cancels boosted ones, as does the confirm dialog's @submit.prevent).
    document.addEventListener('submit', (e) => {
        if (e.target instanceof HTMLFormElement && !e.defaultPrevented && isPost(e.target)) lock(e.target);
    });
    // A boosted post: once htmx has read the form and is sending it.
    document.addEventListener('htmx:beforeRequest', (e) => {
        if (e.detail.elt instanceof HTMLFormElement && isPost(e.detail.elt)) lock(e.detail.elt);
    });
    document.addEventListener('htmx:afterRequest', (e) => {
        if (e.detail.elt instanceof HTMLFormElement) unlock(e.detail.elt);
    });
    // Back to a page the browser kept in memory: its forms can be used again.
    window.addEventListener('pageshow', () => document.querySelectorAll('form[data-submitting]').forEach(unlock));

    document.addEventListener('htmx:beforeSwap', (e) => {
        const { xhr, requestConfig } = e.detail;
        const isGet = requestConfig?.verb === 'get';
        const url = xhr.responseURL || e.detail.pathInfo?.requestPath;

        if (xhr.status === 419) {
            // Session expired (CSRF): a normal reload gets a fresh page and token.
            e.detail.shouldSwap = false;
            window.location.reload();
            return;
        }

        if (xhr.status >= 400) {
            if (isGet) {
                // Show the full error page (403, 404…) as a normal page.
                e.detail.shouldSwap = false;
                window.location.href = url;
            } else {
                // Don't silently ignore a failed form post: show what came back.
                e.detail.shouldSwap = true;
                e.detail.isError = false;
            }
            return;
        }

        const type = xhr.getResponseHeader('Content-Type') || '';
        if (!type.includes('text/html') || shellOf(xhr.responseText) !== shell) {
            // A download, or a page from another part of the app: open it normally.
            // (For a form post the action has already happened; this just shows where it led.)
            e.detail.shouldSwap = false;
            window.location.href = url;
        }
    });

    document.addEventListener('htmx:afterSwap', () => {
        window.scrollTo({ top: 0 });
        onNavigate?.();
    });

    // Network trouble: fall back to a normal page load.
    document.addEventListener('htmx:sendError', (e) => {
        const path = e.detail.pathInfo?.requestPath;
        if (path && e.detail.requestConfig?.verb === 'get') window.location.href = path;
    });
}
