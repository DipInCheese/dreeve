// Counts the numbers in [data-count-up] elements up from zero when they come into view.
// Every number in the text counts on its own, so "7h 31m" and "8 972" keep their format.
const NUMBER = /\d{1,3}(?: \d{3})+|\d+(?:\.\d+)?/g;
const DURATION_MS = 1100;

const easeOutCubic = (t) => 1 - Math.pow(1 - t, 3);

const format = (value, template) => {
    const decimals = (template.split('.')[1] || '').length;
    const fixed = value.toFixed(decimals);
    // Thousands written with spaces, like the app does ("8 972").
    return template.includes(' ') ? fixed.replace(/\B(?=(\d{3})+(?!\d))/g, ' ') : fixed;
};

const countUp = (element) => {
    const original = element.textContent;
    const targets = original.match(NUMBER);
    if (!targets) {
        return;
    }
    const values = targets.map((target) => parseFloat(target.replace(/ /g, '')));
    const start = performance.now();

    const frame = (now) => {
        const progress = Math.min(1, (now - start) / DURATION_MS);
        let index = 0;
        element.textContent = original.replace(NUMBER, () => {
            const target = targets[index];
            const value = values[index++] * easeOutCubic(progress);
            return format(value, target);
        });
        if (progress < 1) {
            requestAnimationFrame(frame);
        } else {
            element.textContent = original;
        }
    };
    requestAnimationFrame(frame);
};

export default function initCountUp(rootNode) {
    const elements = rootNode.querySelectorAll('[data-count-up]');
    if (0 === elements.length || window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
        return;
    }

    if (!('IntersectionObserver' in window)) {
        elements.forEach(countUp);
        return;
    }

    const observer = new IntersectionObserver((entries) => {
        entries.forEach((entry) => {
            if (entry.isIntersecting) {
                observer.unobserve(entry.target);
                countUp(entry.target);
            }
        });
    }, {threshold: 0.4});
    elements.forEach((element) => observer.observe(element));
}
