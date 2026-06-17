import { Controller } from '@hotwired/stimulus'

export default class extends Controller {
    static targets = ['button', 'skill', 'panel', 'count', 'toggle']
    static values = { startUrl: String }

    connect() {
        // Close on a click outside the toggle/panel, and on Escape. Document-level
        // listeners work regardless of focus (clicking a <button> doesn't focus it
        // in Firefox/Safari, so focusout is unreliable for mouse users).
        this.closeOnOutsideClick = (event) => {
            if (!this.hasPanelTarget || this.panelTarget.classList.contains('hidden')) return
            if (this.hasToggleTarget && this.toggleTarget.contains(event.target)) return
            if (this.panelTarget.contains(event.target)) return
            this.panelTarget.classList.add('hidden')
        }
        this.closeOnEscape = (event) => {
            if (event.key !== 'Escape' || !this.hasPanelTarget) return
            if (this.panelTarget.classList.contains('hidden')) return
            this.panelTarget.classList.add('hidden')
            if (this.hasToggleTarget) this.toggleTarget.focus()
        }
        document.addEventListener('click', this.closeOnOutsideClick)
        document.addEventListener('keydown', this.closeOnEscape)
    }

    disconnect() {
        document.removeEventListener('click', this.closeOnOutsideClick)
        document.removeEventListener('keydown', this.closeOnEscape)
    }

    toggleSkills(event) {
        event.preventDefault()
        if (this.hasPanelTarget) this.panelTarget.classList.toggle('hidden')
    }

    refreshCount() {
        if (!this.hasCountTarget) return
        const n = this.skillTargets.filter((c) => c.checked).length
        this.countTarget.textContent = n > 0 ? ` (${n})` : ''
    }

    async start(event) {
        event.preventDefault()
        if (!this.hasButtonTarget) return
        this.buttonTarget.disabled = true
        this.buttonTarget.textContent = 'Starting…'

        const skillIds = this.skillTargets.filter((c) => c.checked).map((c) => c.value)

        let data
        try {
            const response = await fetch(this.startUrlValue, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ skillIds }),
            })
            if (!response.ok) {
                const err = await response.json().catch(() => ({}))
                this.fail(err.error || `HTTP ${response.status}`)
                return
            }
            data = await response.json()
        } catch (err) {
            this.fail('Network error: ' + err.message)
            return
        }

        // Drop the standalone transcript card — once the chat card mounts, the
        // transcript becomes the first user bubble inside it.
        const transcriptCard = document.querySelector('.transcript-card')
        if (transcriptCard) transcriptCard.remove()

        // Swap the start card with the server-rendered chat card — Stimulus picks
        // up the new data-controller="ai-chat" attribute and connects automatically.
        this.element.outerHTML = data.html
    }

    fail(message) {
        if (this.hasButtonTarget) {
            this.buttonTarget.disabled = false
            this.buttonTarget.textContent = 'Start AI chat'
        }
        alert(message)
    }
}
