(function () {
    'use strict';

    // ---- Header: dark over the banner, light (with border) once past it ----
    var header = document.querySelector('.site-header');
    var banner = document.querySelector('.banner');
    function onScroll() {
        var overBanner = banner && banner.getBoundingClientRect().bottom > header.offsetHeight + 260;
        var menuOpen = header.classList.contains('nav-open');
        header.classList.toggle('is-dark', overBanner && !menuOpen);
        header.classList.toggle('is-scrolled', !overBanner && window.scrollY > 8);
    }
    window.addEventListener('scroll', onScroll, { passive: true });
    window.addEventListener('resize', onScroll);
    onScroll();

    // ---- Scroll reveal ----
    var reduceMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    if ('IntersectionObserver' in window && !reduceMotion) {
        var groups = ['.section-head', '.feature', '.step', '.split > *', '.price-card', '.faq-aside', '.faq-list', '.cta-box', '.contact-grid > *', '.strip li'];
        var io = new IntersectionObserver(function (entries) {
            entries.forEach(function (entry) {
                if (!entry.isIntersecting) return;
                var el = entry.target;
                el.classList.add('in');
                io.unobserve(el);
                // hand transitions back to the element (e.g. card hover lift) once revealed
                setTimeout(function () { el.classList.remove('reveal', 'in'); el.style.removeProperty('--d'); }, 1400);
            });
        }, { rootMargin: '0px 0px -8% 0px', threshold: 0.08 });

        groups.forEach(function (selector) {
            document.querySelectorAll(selector).forEach(function (el, i) {
                if (el.getBoundingClientRect().top < window.innerHeight) return; // already on screen: leave as is
                el.classList.add('reveal');
                if (/feature|step|strip/.test(selector)) el.style.setProperty('--d', (i % 3) * 90 + 'ms');
                io.observe(el);
            });
        });
    }

    // ---- Mobile menu ----
    var toggle = document.querySelector('.nav-toggle');
    function setMenu(open) {
        header.classList.toggle('nav-open', open);
        onScroll();
        toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
        toggle.setAttribute('aria-label', open ? 'Close menu' : 'Open menu');
    }
    toggle.addEventListener('click', function () {
        setMenu(!header.classList.contains('nav-open'));
    });
    document.querySelectorAll('.mobile-menu a').forEach(function (link) {
        link.addEventListener('click', function () { setMenu(false); });
    });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') setMenu(false);
    });
    window.addEventListener('resize', function () {
        if (window.innerWidth > 900) setMenu(false);
    });

    // ---- Pricing calculator ----
    var card = document.getElementById('price-card');
    if (card) {
        var tiers = JSON.parse(card.getAttribute('data-tiers'));
        var max = parseInt(card.getAttribute('data-max'), 10);
        var range = document.getElementById('units');
        var out = document.getElementById('units-out');
        var tierLabel = document.getElementById('price-tier');
        var value = document.getElementById('price-value');
        var period = document.getElementById('price-period');
        var note = document.getElementById('price-note');
        var tierPeriod = document.getElementById('tier-period');
        var rows = document.querySelectorAll('#tier-list li');
        var currency = card.getAttribute('data-currency') || 'UGX';
        var fmt = function (n) { return Number(n).toLocaleString('en-US'); };

        // Tier columns: [min, max, monthly, quarterly, annual]
        var cycles = {
            monthly:   { col: 2, period: '/ month',   every: 'per month',   note: 'Billed monthly' },
            quarterly: { col: 3, period: '/ quarter', every: 'per quarter', note: 'Billed every 3 months' },
            annual:    { col: 4, period: '/ year',    every: 'per year',    note: 'Billed once a year' }
        };
        var cycle = 'monthly';

        document.querySelectorAll('input[name="cycle"]').forEach(function (radio) {
            radio.addEventListener('change', function () {
                cycle = radio.value;
                update();
            });
        });

        function update() {
            var units = parseInt(range.value, 10);
            var index = tiers.findIndex(function (t) {
                return units >= t[0] && (t[1] === null || units <= t[1]);
            });
            if (index < 0) index = tiers.length - 1;
            var t = tiers[index];

            out.textContent = units >= max ? max + '+' : units;
            tierLabel.textContent = t[1] === null ? t[0] + '+ units' : t[0] + ' – ' + t[1] + ' units';
            var c = cycles[cycle];
            value.textContent = fmt(t[c.col]);
            period.textContent = c.period;
            note.textContent = cycle === 'monthly'
                ? c.note
                : c.note + ' · works out to ' + currency + ' ' + fmt(Math.round(t[c.col] / (cycle === 'annual' ? 12 : 3))) + ' / month';
            tierPeriod.textContent = c.every;
            range.style.setProperty('--fill', ((units - 1) / (max - 1) * 100) + '%');

            rows.forEach(function (row, i) {
                row.classList.toggle('is-active', i === index);
                var price = row.querySelector('b');
                price.textContent = price.getAttribute('data-' + cycle);
            });
        }

        var plans = JSON.parse(card.getAttribute('data-plans') || '[]');
        var list = document.getElementById('tier-list');
        document.querySelectorAll('input[name="plan"]').forEach(function (radio) {
            radio.addEventListener('change', function () {
                var plan = plans[parseInt(radio.value, 10)];
                if (!plan) return;
                tiers = plan.tiers;
                list.innerHTML = '';
                tiers.forEach(function (t, i) {
                    var li = document.createElement('li');
                    li.setAttribute('data-tier', i);
                    var label = document.createElement('span');
                    label.textContent = t[1] === null ? t[0] + '+ units' : t[0] + ' – ' + t[1] + ' units';
                    var price = document.createElement('b');
                    price.setAttribute('data-monthly', fmt(t[2]));
                    price.setAttribute('data-quarterly', fmt(t[3]));
                    price.setAttribute('data-annual', fmt(t[4]));
                    li.appendChild(label);
                    li.appendChild(price);
                    list.appendChild(li);
                });
                rows = list.querySelectorAll('li');
                update();
            });
        });

        range.addEventListener('input', update);
        update();
    }
})();
