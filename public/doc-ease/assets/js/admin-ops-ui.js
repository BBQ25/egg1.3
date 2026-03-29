(function () {
    'use strict';

    function prefersReducedMotion() {
        return !!(window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches);
    }

    function clamp(value, min, max) {
        if (value < min) return min;
        if (value > max) return max;
        return value;
    }

    function cssNumberVar(name, fallback) {
        var root = document.documentElement;
        if (!root || !window.getComputedStyle) return fallback;
        var raw = window.getComputedStyle(root).getPropertyValue(name);
        var parsed = parseFloat(String(raw || '').trim());
        return Number.isFinite(parsed) ? parsed : fallback;
    }

    function markPageReady() {
        requestAnimationFrame(function () {
            document.body.classList.add('ops-page-ready');
        });
    }

    function initParallax() {
        if (prefersReducedMotion()) return;
        var heroes = document.querySelectorAll('[data-ops-parallax]');
        if (!heroes.length) return;

        var ticking = false;
        var amplitude = cssNumberVar('--ops-parallax-amplitude', 26);
        var scale = cssNumberVar('--ops-parallax-scale', 1.06);

        function update() {
            ticking = false;
            var viewportHeight = window.innerHeight || 1;

            for (var i = 0; i < heroes.length; i++) {
                var hero = heroes[i];
                var layer = hero.querySelector('[data-ops-parallax-layer]');
                if (!layer) continue;

                var rect = hero.getBoundingClientRect();
                var progress = (viewportHeight - rect.top) / (viewportHeight + rect.height);
                progress = clamp(progress, 0, 1);

                var offset = (progress - 0.5) * amplitude;
                layer.style.transform = 'translate3d(0,' + offset.toFixed(2) + 'px,0) scale(' + scale.toFixed(3) + ')';
            }
        }

        function onChange() {
            if (ticking) return;
            ticking = true;
            window.requestAnimationFrame(update);
        }

        window.addEventListener('scroll', onChange, { passive: true });
        window.addEventListener('resize', onChange);
        update();
    }

    document.addEventListener('DOMContentLoaded', function () {
        markPageReady();
        initParallax();
    });
})();
