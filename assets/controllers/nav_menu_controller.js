import { Controller } from '@hotwired/stimulus'

/**
 * Collapses the navbar links behind a hamburger button on narrow viewports.
 *
 * The breakpoint lives in CSS, not here: the panel is a normal flex row above it and an
 * absolutely-positioned dropdown below it, so this controller only ever toggles one class and
 * never needs to know which layout is in effect.
 *
 * Collapsing deliberately does not depend on this controller having run. Gating it on a class
 * added here meant the menu painted expanded and snapped shut once the module loaded, which is
 * a flash on every single page load. Scripting's only job is opening and closing; the collapsed
 * layout is the CSS default, and the no-JS fallback is a <noscript> override.
 */
export default class extends Controller {
    static targets = ['panel', 'toggle']

    connect() {
        this.close()
    }

    toggle() {
        this.element.classList.contains('nav-open') ? this.close() : this.open()
    }

    open() {
        this.element.classList.add('nav-open')
        this.toggleTarget.setAttribute('aria-expanded', 'true')
    }

    close() {
        this.element.classList.remove('nav-open')
        this.toggleTarget.setAttribute('aria-expanded', 'false')
    }

    /**
     * Tapping a link navigates, but Turbo may replace only the body and leave this navbar in
     * place, so the panel would otherwise still be hanging open over the new page.
     */
    closeOnNavigate(event) {
        if (event.target.closest('a')) {
            this.close()
        }
    }

    closeOnOutsideClick(event) {
        if (!this.element.contains(event.target)) {
            this.close()
        }
    }

    closeOnEscape() {
        if (this.element.classList.contains('nav-open')) {
            this.close()
            this.toggleTarget.focus()
        }
    }
}
