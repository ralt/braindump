import { Controller } from '@hotwired/stimulus'

/** How long the button stays on its confirmation label before reverting. */
const CONFIRM_MS = 2000

/**
 * Copies text to the clipboard: the raw value when one is set, otherwise the text content of
 * the source element.
 *
 * The raw value exists because the source element is sometimes a *rendering* of the text rather
 * than the text — the AI chat parses markdown into HTML in place, so its textContent is prose
 * with the syntax stripped out, which is not what you want to paste into anything that
 * understands markdown.
 *
 * The button doubles as the feedback: a copy that silently succeeds is indistinguishable from
 * one that silently failed, and the clipboard is not something the user can glance at to check.
 * Failure is reported on the button too rather than thrown away — navigator.clipboard is
 * unavailable on insecure origins and can be refused by permission policy, and "nothing
 * happened" is the worst possible answer in those cases.
 */
export default class extends Controller {
    static targets = ['source', 'button']
    static values = {
        raw: String,
    }

    confirmTimeout = null

    disconnect() {
        clearTimeout(this.confirmTimeout)
    }

    async copy() {
        const text = this.rawValue !== '' ? this.rawValue : this.sourceTarget.textContent

        try {
            await navigator.clipboard.writeText(text)
            this.flash('Copied')
        } catch {
            this.flash('Press Ctrl+C')
            // Leaving it selected turns the failure into a one-keystroke manual copy rather
            // than a dead end. It selects what's on screen, so for a rendered source this
            // fallback gives the rendering rather than the raw text — still better than
            // nothing on the insecure origins where it's the only path available.
            this.selectSource()
        }
    }

    flash(label) {
        clearTimeout(this.confirmTimeout)

        const original = this.buttonTarget.dataset.label ?? this.buttonTarget.textContent
        this.buttonTarget.dataset.label = original
        this.buttonTarget.textContent = label

        this.confirmTimeout = setTimeout(() => {
            this.buttonTarget.textContent = original
        }, CONFIRM_MS)
    }

    selectSource() {
        const range = document.createRange()
        range.selectNodeContents(this.sourceTarget)

        const selection = window.getSelection()
        selection.removeAllRanges()
        selection.addRange(range)
    }
}
