import { Controller } from '@hotwired/stimulus'

export default class extends Controller {
    static targets = ['toggle', 'panel', 'count', 'checkbox']
    static values = { skillsUrl: String }

    pendingSave = null

    connect() {
        // Close on a click anywhere outside the toggle/panel, and on Escape.
        // Document-level listeners work regardless of where focus is (clicking a
        // <button> doesn't focus it in Firefox/Safari, so focusout is unreliable).
        this.closeOnOutsideClick = (event) => {
            if (this.panelTarget.classList.contains('hidden')) return
            if (this.toggleTarget.contains(event.target)) return
            if (this.panelTarget.contains(event.target)) return
            this.panelTarget.classList.add('hidden')
        }
        this.closeOnEscape = (event) => {
            if (event.key !== 'Escape' || this.panelTarget.classList.contains('hidden')) return
            this.panelTarget.classList.add('hidden')
            this.toggleTarget.focus()
        }
        document.addEventListener('click', this.closeOnOutsideClick)
        document.addEventListener('keydown', this.closeOnEscape)
    }

    disconnect() {
        document.removeEventListener('click', this.closeOnOutsideClick)
        document.removeEventListener('keydown', this.closeOnEscape)
    }

    togglePanel(event) {
        event.preventDefault()
        this.panelTarget.classList.toggle('hidden')
    }

    change() {
        this.refreshCount()
        // Debounce so toggling several boxes in a row only fires one POST.
        clearTimeout(this.pendingSave)
        this.pendingSave = setTimeout(() => this.save(), 200)
    }

    refreshCount() {
        if (!this.hasCountTarget) return
        const n = this.checkedIds().length
        this.countTarget.textContent = n > 0 ? `(${n})` : ''
    }

    checkedIds() {
        return this.checkboxTargets.filter((c) => c.checked).map((c) => c.value)
    }

    async save() {
        try {
            await fetch(this.skillsUrlValue, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ skillIds: this.checkedIds() }),
            })
        } catch {
            // best-effort — next user message will simply fall back to whatever the server already has
        }
    }
}
