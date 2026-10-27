// Landlord and staff portals: app-like navigation (see boost.js).
import { startBoost } from './boost';

startBoost({
    // On phones the menu is a drawer over the page: close it once a page has loaded.
    onNavigate() {
        if (window.innerWidth < 1024 && window.Alpine) {
            const body = window.Alpine.$data(document.body);
            if (body && 'sidebarOpen' in body) body.sidebarOpen = false;
        }
    },
});
