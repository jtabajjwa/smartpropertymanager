// Polls a payment's status until it is final (succeeded / failed).
// Used as x-data="paymentStatus(url, initialStatus)" on payment pages.
export function paymentStatus(url, initial) {
    return {
        status: initial, message: null, receipt: null, periodEnd: null, smsBalance: null, tries: 0, timer: null,
        get open() { return this.status === 'pending' || this.status === 'indeterminate'; },
        init() { this.poll(); },
        destroy() { clearTimeout(this.timer); },
        async poll() {
            if (!this.open || this.tries++ > 150) return; // stop after ~10 minutes
            try {
                const r = await fetch(url, { headers: { 'Accept': 'application/json' } });
                if (r.ok) {
                    const d = await r.json();
                    this.status = d.status; this.message = d.message; this.receipt = d.receipt;
                    this.periodEnd = d.period_end; this.smsBalance = d.sms_balance;
                }
            } catch (e) { /* network blip: try again */ }
            if (this.open) this.timer = setTimeout(() => this.poll(), 4000);
        },
    };
}
