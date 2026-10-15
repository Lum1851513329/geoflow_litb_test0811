(function () {
    function initLucideIcons() {
        if (window.lucide && typeof window.lucide.createIcons === 'function') {
            window.lucide.createIcons();
        }
    }

    function initActivityCarousel() {
        document.querySelectorAll('[data-activity-carousel]').forEach(function (carousel) {
            const slides = Array.from(carousel.querySelectorAll('[data-activity-slide]'));
            const dots = Array.from(carousel.querySelectorAll('[data-activity-dot]'));

            if (slides.length <= 1) {
                return;
            }

            let activeIndex = Math.max(0, slides.findIndex(function (slide) {
                return slide.classList.contains('is-active');
            }));
            let timer = null;

            const activate = function (index) {
                activeIndex = index;
                slides.forEach(function (slide, slideIndex) {
                    slide.classList.toggle('is-active', slideIndex === index);
                });
                dots.forEach(function (dot, dotIndex) {
                    dot.classList.toggle('is-active', dotIndex === index);
                });
            };

            const start = function () {
                timer = window.setInterval(function () {
                    activate((activeIndex + 1) % slides.length);
                }, 5000);
            };
            const stop = function () {
                if (timer) {
                    window.clearInterval(timer);
                    timer = null;
                }
            };

            dots.forEach(function (dot, index) {
                dot.addEventListener('click', function () {
                    activate(index);
                });
            });

            carousel.addEventListener('mouseenter', stop);
            carousel.addEventListener('mouseleave', start);
            carousel.addEventListener('focusin', stop);
            carousel.addEventListener('focusout', start);

            start();
        });
    }

    document.addEventListener('DOMContentLoaded', function () {
        initLucideIcons();
        initActivityCarousel();
    });
})();
