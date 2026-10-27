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
