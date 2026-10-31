/*
 * Phone number with a country-code picker (<x-phone-input>). The visible
 * field holds the national number; a hidden input carries the full
 * international number ("+256772123456") under the field's name.
 *
 *   phoneInput(countries, iso, local) — countries from App\Support\PhoneCountries::forJs()
 */

const DEFAULT_ISO = "UG";

export function registerPhoneInput(Alpine) {
    Alpine.data("phoneInput", (countries, iso, local) => ({
        countries,
        iso,
        local,
        touched: false,

        get country() {
            return this.countries.find((c) => c.iso === this.iso) ?? this.countries[0];
        },

        get pattern() {
            return new RegExp(`^(?:${this.country.pattern})$`);
        },

        // Forgive a typed country code or the national leading 0.
        get digits() {
            let d = this.local.replace(/\D/g, "");
            const dial = this.country.dial;
            if (d.startsWith(dial) && !this.pattern.test(d) && this.pattern.test(d.slice(dial.length))) d = d.slice(dial.length);
            if (d.startsWith("0") && this.pattern.test(d.slice(1))) d = d.slice(1);
            return d;
        },

        get full() {
            return this.local.trim() ? `+${this.country.dial}${this.digits}` : "";
        },

        get error() {
            if (!this.touched || !this.local.trim() || this.pattern.test(this.digits)) return "";
            return `That isn’t a valid ${this.country.name} number. Enter the digits after +${this.country.dial}, e.g. ${this.country.example}.`;
        },

        // Show a stored number (e.g. when an edit form opens): pick its country, longest dial code first.
        set(value) {
            const raw = (value ?? "").toString().trim();
            const digits = raw.replace(/\D/g, "");
            const match = raw.startsWith("+")
                ? [...this.countries].sort((a, b) => b.dial.length - a.dial.length).find((c) => digits.startsWith(c.dial))
                : null;

            this.iso = match?.iso ?? DEFAULT_ISO;
            this.local = match ? digits.slice(match.dial.length) : raw;
            this.touched = false;
        },
    }));
}
