const mounted = new WeakSet();

export function mountFutureCarousels(root = document) {
    root.querySelectorAll('[data-future-carousel]').forEach(carousel => {
        if (mounted.has(carousel)) return;
        const viewport = carousel.querySelector('[data-carousel-viewport]');
        const track = carousel.querySelector('[data-carousel-track]');
        const cards = [...track.children];
        if (!cards.length) return;
        mounted.add(carousel);
        carousel.classList.add('is-enhanced');
        const controls = carousel.querySelector('[data-carousel-controls]');
        const prev = controls.querySelector('[data-carousel-prev]');
        const next = controls.querySelector('[data-carousel-next]');
        const dots = [...controls.querySelectorAll('[data-carousel-index]')];
        let active = cards.length > 2 ? 1 : 0;
        let frame;
        const center = i => cards[i].getBoundingClientRect().left - viewport.getBoundingClientRect().left + viewport.scrollLeft + cards[i].offsetWidth / 2 - viewport.clientWidth / 2;
        const sync = () => {
            active = cards.reduce((best, _, i) => Math.abs(center(i) - viewport.scrollLeft) < Math.abs(center(best) - viewport.scrollLeft) ? i : best, 0);
            cards.forEach((card, i) => { card.dataset.active = String(i === active); });
            dots.forEach((dot, i) => {
                if (i === active) dot.setAttribute('aria-current', 'true');
                else dot.removeAttribute('aria-current');
            });
            prev.disabled = active === 0;
            next.disabled = active === cards.length - 1;
            controls.querySelector('[data-carousel-status]').textContent = `${cards.length}枚中${active + 1}枚目`;
        };
        const go = index => viewport.scrollTo({left:center(Math.max(0, Math.min(cards.length - 1, index))),
            behavior:matchMedia('(prefers-reduced-motion: reduce)').matches ? 'instant' : 'smooth'});
        prev.addEventListener('click', () => go(active - 1));
        next.addEventListener('click', () => go(active + 1));
        dots.forEach((dot, i) => dot.addEventListener('click', () => go(i)));
        viewport.addEventListener('keydown', event => {
            if (event.target !== viewport || event.altKey || event.ctrlKey || event.metaKey) return;
            const index = {ArrowLeft:active - 1, ArrowRight:active + 1, Home:0, End:cards.length - 1}[event.key];
            if (index === undefined) return;
            event.preventDefault(); go(index);
        });
        track.addEventListener('focusin', event => {
            const i = cards.findIndex(card => card.contains(event.target));
            if (i >= 0 && i !== active) go(i);
        });
        viewport.addEventListener('scroll', () => {
            cancelAnimationFrame(frame); frame = requestAnimationFrame(sync);
        }, {passive:true});
        // CSS pads against the actual container, preserving centered end cards.
        const resize = () => {
            track.style.paddingInline = `${Math.max(12, (viewport.clientWidth - cards[0].offsetWidth) / 2)}px`;
            viewport.scrollTo({left:center(active),behavior:'instant'}); sync();
        };
        const observer = new ResizeObserver(resize);
        observer.observe(viewport);
        document.addEventListener('canovia:before-page-replace', () => {
            observer.disconnect(); cancelAnimationFrame(frame);
        }, {once:true});
        const imageFailure = img => {
            if (img.matches?.('[data-future-art]')) {
                img.removeAttribute('src'); img.removeAttribute('srcset');
                img.alt = '機能イラストを表示できません。カード本文で内容を確認できます。';
            }
        };
        carousel.addEventListener('error', event => imageFailure(event.target), true);
        // A cached/fast failure can precede module mounting (notably WebKit).
        // Reconcile broken completed requests without treating a pending lazy
        // request, an image with no source, or a valid image as a failure.
        carousel.querySelectorAll('[data-future-art]').forEach(img => {
            if (img.getAttribute('src') && img.complete && img.naturalWidth === 0) imageFailure(img);
        });
        controls.hidden = false;
        carousel.querySelector('[data-carousel-keyboard-help]').hidden = false;
        resize();
    });
}

if (typeof document !== 'undefined') {
    document.addEventListener('DOMContentLoaded', () => mountFutureCarousels());
    document.addEventListener('canovia:page-ready', () => mountFutureCarousels());
    mountFutureCarousels();
}
