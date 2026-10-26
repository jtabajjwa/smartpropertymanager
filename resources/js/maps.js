/*
 * Estate location maps (Leaflet + OpenStreetMap, no API key needed).
 * Leaflet is loaded on demand, so pages without a map don't download it.
 *
 *   mapPicker(lat, lng) — click / drag / search / "use my location" to pick coordinates
 *   mapView(lat, lng)   — small read-only map with a pin
 */

const KAMPALA = [0.3476, 32.5825];

let leafletPromise = null;

function loadLeaflet() {
    leafletPromise ??= Promise.all([import("leaflet"), import("leaflet/dist/leaflet.css")]).then(
        ([module]) => module.default ?? module,
    );
    return leafletPromise;
}

// Brand-coloured pin drawn as an inline SVG (avoids Leaflet's image-path issues under Vite).
function pinIcon(L) {
    return L.divIcon({
        className: "",
        iconSize: [30, 40],
        iconAnchor: [15, 39],
        html: `<svg width="30" height="40" viewBox="0 0 30 40" xmlns="http://www.w3.org/2000/svg">
                 <path d="M15 1C7.3 1 1 7.2 1 14.9 1 25.3 15 39 15 39s14-13.7 14-24.1C29 7.2 22.7 1 15 1z" fill="#c1704a" stroke="#fff" stroke-width="2"/>
                 <circle cx="15" cy="15" r="5.5" fill="#fff"/>
               </svg>`,
    });
}

function tiles(L) {
    return L.tileLayer("https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png", {
        maxZoom: 19,
        attribution: '&copy; <a href="https://www.openstreetmap.org/copyright" target="_blank" rel="noopener">OpenStreetMap</a>',
    });
}

function toNumber(value) {
    if (value === null || value === undefined || value === "") return null;
    const n = Number(value);
    return Number.isFinite(n) ? n : null;
}

function validPair(lat, lng) {
    return lat !== null && lng !== null && lat >= -90 && lat <= 90 && lng >= -180 && lng <= 180;
}

// Leaflet measures its container once; modals animate in, so re-measure a few times.
function settle(map) {
    [50, 250, 500].forEach((ms) => setTimeout(() => map.invalidateSize(), ms));
}

export function registerMaps(Alpine) {
    Alpine.data("mapPicker", (initialLat = null, initialLng = null) => ({
        lat: toNumber(initialLat) ?? "",
        lng: toNumber(initialLng) ?? "",
        query: "",
        searching: false,
        locating: false,
        message: "",
        ready: false,
        map: null,
        marker: null,
        L: null,

        async init() {
            const L = (this.L = await loadLeaflet());
            const lat = toNumber(this.lat);
            const lng = toNumber(this.lng);
            const has = validPair(lat, lng);

            this.map = L.map(this.$refs.map, { scrollWheelZoom: false }).setView(has ? [lat, lng] : KAMPALA, has ? 16 : 12);
            tiles(L).addTo(this.map);
            this.map.on("click", (e) => this.set(e.latlng.lat, e.latlng.lng));
            if (has) this.placeMarker(lat, lng);

            this.ready = true;
            settle(this.map);
        },

        get hasPin() {
            return validPair(toNumber(this.lat), toNumber(this.lng));
        },

        set(lat, lng, zoom = null) {
            this.lat = Number(lat.toFixed(7));
            this.lng = Number(lng.toFixed(7));
            this.message = "";
            this.placeMarker(this.lat, this.lng);
            if (zoom) this.map.setView([this.lat, this.lng], zoom);
        },

        placeMarker(lat, lng) {
            if (!this.map) return;
            if (this.marker) {
                this.marker.setLatLng([lat, lng]);
                return;
            }
            this.marker = this.L.marker([lat, lng], { draggable: true, icon: pinIcon(this.L) }).addTo(this.map);
            this.marker.on("dragend", () => {
                const p = this.marker.getLatLng();
                this.set(p.lat, p.lng);
            });
        },

        // Typed or pasted coordinates. Accepts "0.3476, 32.5825" pasted into either box.
        typed(field, value) {
            const parts = String(value).split(/[,\s]+/).filter(Boolean);
            if (parts.length === 2 && toNumber(parts[0]) !== null && toNumber(parts[1]) !== null) {
                this.lat = toNumber(parts[0]);
                this.lng = toNumber(parts[1]);
            } else {
                this[field] = value;
            }

            const lat = toNumber(this.lat);
            const lng = toNumber(this.lng);
            if (validPair(lat, lng)) {
                this.placeMarker(lat, lng);
                this.map?.setView([lat, lng], Math.max(this.map.getZoom(), 15));
            }
        },

        clear() {
            this.lat = "";
            this.lng = "";
            this.message = "";
            if (this.marker) {
                this.marker.remove();
                this.marker = null;
            }
        },

        // OpenStreetMap's free geocoder; one request per click, as its usage policy asks.
        async search() {
            const q = this.query.trim();
            if (!q || this.searching) return;
            this.searching = true;
            this.message = "";
            try {
                const url = `https://nominatim.openstreetmap.org/search?format=json&limit=1&countrycodes=ug&q=${encodeURIComponent(q)}`;
                const results = await (await fetch(url, { headers: { Accept: "application/json" } })).json();
                if (results.length) {
                    this.set(Number(results[0].lat), Number(results[0].lon), 16);
                    this.message = "Pin placed. Drag it to the exact spot if needed.";
                } else {
                    this.message = "No match found. Try a nearby landmark or area name, or click on the map.";
                }
            } catch (e) {
                this.message = "Search is unavailable right now. Click on the map to place the pin instead.";
            }
            this.searching = false;
        },

        locate() {
            if (!navigator.geolocation) {
                this.message = "This browser can't share its location.";
                return;
            }
            this.locating = true;
            this.message = "";
            navigator.geolocation.getCurrentPosition(
                (pos) => {
                    this.set(pos.coords.latitude, pos.coords.longitude, 17);
                    this.locating = false;
                },
                () => {
                    this.message = "Couldn't get your location. Check the browser's location permission.";
                    this.locating = false;
                },
                { enableHighAccuracy: true, timeout: 10000 },
            );
        },
    }));

    Alpine.data("mapView", (lat, lng) => ({
        async init() {
            const la = toNumber(lat);
            const ln = toNumber(lng);
            if (!validPair(la, ln)) return;

            const L = await loadLeaflet();
            const map = L.map(this.$refs.map, { scrollWheelZoom: false, dragging: !L.Browser.mobile }).setView([la, ln], 16);
            tiles(L).addTo(map);
            L.marker([la, ln], { icon: pinIcon(L) }).addTo(map);
            settle(map);
        },
    }));
}
