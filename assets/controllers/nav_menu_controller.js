import { Controller } from '@hotwired/stimulus'

/**
 * Collapses the navbar links behind a hamburger button on narrow viewports.
 *
 * The breakpoint lives in CSS, not here: the panel is a normal flex row above it and an
 * absolutely-positioned dropdown below it, so this controller only ever toggles classes and
 * never needs to know which layout is in effect.
 *
 * It fails open. Collapsing is gated on a nav-collapsed class that this controller adds on
 * connect, so if scripting never starts the links stay visible as the wrapping row they were
 * before — a navbar whose only control is a button nothing listens to would be worse than an
 * ugly one.
 */
export default class extends Controller {
    static targets = ['panel', 'toggle']

    connect() {
        this.element.classList.add('nav-collapsed')
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
