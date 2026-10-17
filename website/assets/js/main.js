(function () {
    'use strict';

    // ---- Header: shadow on scroll ----
    var header = document.querySelector('.site-header');
    function onScroll() {
        header.classList.toggle('is-scrolled', window.scrollY > 8);
    }
    window.addEventListener('scroll', onScroll, { passive: true });
    onScroll();

    // ---- Mobile menu ----
    var toggle = document.querySelector('.nav-toggle');
    function setMenu(open) {
        header.classList.toggle('nav-open', open);
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
        var monthly = document.getElementById('price-monthly');
        var annual = document.getElementById('price-annual');
        var rows = document.querySelectorAll('#tier-list li');
        var currency = 'UGX';
        var fmt = function (n) { return Number(n).toLocaleString('en-US'); };

        function update() {
            var units = parseInt(range.value, 10);
            var index = tiers.findIndex(function (t) {
                return units >= t[0] && (t[1] === null || units <= t[1]);
            });
            if (index < 0) index = tiers.length - 1;
            var t = tiers[index];

            out.textContent = units >= max ? max + '+' : units;
            tierLabel.textContent = t[1] === null ? t[0] + '+ units' : t[0] + ' – ' + t[1] + ' units';
            monthly.textContent = fmt(t[2]);
            annual.textContent = 'or ' + currency + ' ' + fmt(t[3]) + ' billed yearly';
            range.style.setProperty('--fill', ((units - 1) / (max - 1) * 100) + '%');

            rows.forEach(function (row, i) { row.classList.toggle('is-active', i === index); });
        }

        range.addEventListener('input', update);
        update();
    }
})();
