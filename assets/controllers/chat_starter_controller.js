import { Controller } from '@hotwired/stimulus'

export default class extends Controller {
    static targets = ['button']
    static values = { startUrl: String }

    async start(event) {
        event.preventDefault()
        if (!this.hasButtonTarget) return
        this.buttonTarget.disabled = true
        this.buttonTarget.textContent = 'Starting…'

        let data
        try {
            const response = await fetch(this.startUrlValue, { method: 'POST' })
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
