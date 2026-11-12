/*
 * Bulk SMS composer (resources/views/sms-broadcasts/create.blade.php): filters
 * recipients, fills placeholders for the preview, and counts SMS parts the way
 * App\Services\SmsSegments does.
 */
export function registerBulkSms(Alpine) {
    Alpine.data("bulkSms", (o) => {
            const GSM = "@£$¥èéùìòÇ\nØø\rÅåΔ_ΦΓΛΩΠΨΣΘΞÆæßÉ !\"#¤%&'()*+,-./0123456789:;<=>?¡ABCDEFGHIJKLMNOPQRSTUVWXYZÄÖÑÜ§¿abcdefghijklmnopqrstuvwxyzäöñüà";
            const EXT = "^{}\\[~]|€\f";
            return {
                ...o,
                scope: o.estates.length ? 'estates' : 'all',
                at: 0,
                get list() {
                    return this.recipients.filter(r =>
                        (this.scope === 'all' || r.estate_ids.some(id => this.estates.includes(id))) && (!this.owingOnly || r.balance > 0));
                },
                get sample() { return this.list[Math.min(this.at, this.list.length - 1)] || null },
                get credits() { return this.message.trim() ? this.list.reduce((n, r) => n + this.parts(this.render(r)), 0) : 0 },
                get canSend() {
                    return this.message.trim().length >= 5 && this.list.length > 0 && this.credits <= this.balance
                        && (this.scope === 'all' || this.estates.length > 0);
                },
                full(r) {
                    return this.message
                        .replaceAll('{first_name}', r.first_name).replaceAll('{name}', r.name)
                        .replaceAll('{unit}', r.unit).replaceAll('{estate}', r.estate)
                        .replaceAll('{balance}', 'UGX ' + this.fmt(Math.round(r.balance)))
                        .replaceAll('{landlord}', this.landlord)
                        .replace(/[ \t]+/g, ' ').trim();
                },
                render(r) { return Array.from(this.full(r)).slice(0, this.max).join('') },
                parts(text) {
                    if (!text) return 1;
                    let len = 0;
                    for (const ch of text) {
                        if (GSM.includes(ch)) len += 1;
                        else if (EXT.includes(ch)) len += 2;
                        else { const u = text.length; return u <= 70 ? 1 : Math.ceil(u / 67); }
                    }
                    return len <= 160 ? 1 : Math.ceil(len / 153);
                },
                insert(tag) {
                    const el = this.$refs.message, s = el.selectionStart ?? this.message.length, e = el.selectionEnd ?? s;
                    this.message = this.message.slice(0, s) + tag + this.message.slice(e);
                    this.$nextTick(() => { el.focus(); el.setSelectionRange(s + tag.length, s + tag.length); });
                },
                useTemplate(text) {
                    if (this.message.trim() && !confirm('Replace your message with this template?')) return;
                    this.message = text;
                    this.$nextTick(() => this.$refs.message.focus());
                },
                fmt(n) { return Number(n).toLocaleString('en-US') },
            };
    });
}
