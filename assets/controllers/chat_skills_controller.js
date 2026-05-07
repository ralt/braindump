import { Controller } from '@hotwired/stimulus'

export default class extends Controller {
    static targets = ['toggle', 'panel', 'count', 'checkbox']
    static values = { skillsUrl: String }

    pendingSave = null

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
