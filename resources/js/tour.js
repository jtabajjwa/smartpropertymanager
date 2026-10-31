/*
 * Guided tours (App\Support\Tours, <x-tour id="..." />): a dimmed page with
 * one element at a time highlighted and a speech bubble beside it.
 * Driver.js is loaded only when a tour starts.
 *
 *   tour(config) — config from the <x-tour> component:
 *     { id, title, intro, steps: [{ element, title, text, side, sidebar }],
 *       offer, autostart, seenUrl, offUrl }
 *
 * Steps whose element isn't on the page (no rows yet, no checklist) are left
 * out. Sidebar steps open the menu first; on phones it closes again for the
 * rest of the tour, and it's put back as it was at the end.
 */

let driverPromise = null;

function loadDriver() {
    driverPromise ??= Promise.all([import("driver.js"), import("driver.js/dist/driver.css")]).then(([module]) => module.driver);
    return driverPromise;
}

const visible = (el) => !!(el.offsetWidth || el.offsetHeight || el.getClientRects().length);
const pause = (ms) => new Promise((resolve) => setTimeout(resolve, ms));
const SIDEBAR_TRANSITION = 260;

function post(url) {
    const token = document.querySelector('meta[name="csrf-token"]')?.content;
    return fetch(url, {
        method: "POST",
        headers: { "X-CSRF-TOKEN": token ?? "", Accept: "application/json", "X-Requested-With": "XMLHttpRequest" },
        credentials: "same-origin",
    }).catch(() => {});
}

export function registerTour(Alpine) {
    Alpine.data("tour", (config) => ({
        prompt: false,
        running: false,

        init() {
            window.primoraTour = this;

            if (config.autostart) {
                // Started from "Take a tour": drop ?tour=1 so a refresh doesn't start it again.
                const url = new URL(window.location.href);
                url.searchParams.delete("tour");
                window.history.replaceState(window.history.state, "", url);
                setTimeout(() => this.start(), 300);
            } else if (config.offer) {
                setTimeout(() => (this.prompt = true), 900);
            }
        },

        destroy() {
            if (window.primoraTour === this) window.primoraTour = null;
        },

        // "No thanks": don't offer this tour again.
        decline() {
            this.prompt = false;
            post(config.seenUrl);
        },

        // "Turn off tours": no more prompts on any page.
        turnOff() {
            this.prompt = false;
            post(config.offUrl);
        },

        async start() {
            if (this.running) return;
            this.prompt = false;
            this.running = true;

            const driver = await loadDriver();
            const body = Alpine.$data(document.body);
            const hasSidebar = body && "sidebarOpen" in body;
            const sidebarWas = hasSidebar ? body.sidebarOpen : null;
            const isPhone = () => window.innerWidth < 1024;

            const setSidebar = async (open) => {
                if (!hasSidebar || body.sidebarOpen === open) return;
                body.sidebarOpen = open;
                await pause(SIDEBAR_TRANSITION);
            };

            // Resolve each step to the first matching element that is (or, in the menu, can be) shown.
            const steps = config.steps
                .map((step) => {
                    const matches = [...document.querySelectorAll(`[data-tour="${step.element}"]`)];
                    const el = step.sidebar ? matches[0] : matches.find(visible);
                    return el ? { step, el } : null;
                })
                .filter(Boolean);

            if (!steps.length) {
                this.running = false;
                return;
            }

            // Menu steps need the menu open; on phones the menu covers the page, so close it for the rest.
            const prepare = async (i) => {
                const { step } = steps[i];
                if (step.sidebar) await setSidebar(true);
                else if (isPhone()) await setSidebar(false);
            };

            const tour = driver({
                steps: steps.map(({ step, el }) => ({
                    element: el,
                    popover: { title: step.title, description: step.text, side: isPhone() && step.sidebar ? "bottom" : step.side ?? "bottom", align: "start" },
                })),
                showProgress: true,
                progressText: "{{current}} of {{total}}",
                nextBtnText: "Next",
                prevBtnText: "Back",
                doneBtnText: "Done",
                popoverClass: "primora-tour",
                overlayOpacity: 0.55,
                overlayClickBehavior: "none",
                stagePadding: 6,
                stageRadius: 12,
                smoothScroll: true,
                disableActiveInteraction: true,

                onNextClick: async () => {
                    const next = tour.getActiveIndex() + 1;
                    if (next >= steps.length) return tour.destroy();
                    await prepare(next);
                    tour.moveTo(next);
                },
                onPrevClick: async () => {
                    const prev = tour.getActiveIndex() - 1;
                    if (prev < 0) return;
                    await prepare(prev);
                    tour.moveTo(prev);
                },

                // A "Skip tour" link beside the progress, on every step but the last.
                onPopoverRender: (popover) => {
                    if (tour.isLastStep()) return;
                    const skip = document.createElement("button");
                    skip.type = "button";
                    skip.className = "primora-tour-skip";
                    skip.textContent = "Skip tour";
                    skip.addEventListener("click", () => tour.destroy());
                    popover.footer.insertBefore(skip, popover.footerButtons);
                },

                // Finished, skipped, closed or Esc: remember it and put the menu back.
                onDestroyed: () => {
                    this.running = false;
                    post(config.seenUrl);
                    if (hasSidebar) body.sidebarOpen = isPhone() ? false : sidebarWas;
                },
            });

            await prepare(0);
            tour.drive(0);
        },
    }));
}
