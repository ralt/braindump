import { Controller } from '@hotwired/stimulus'

export default class extends Controller {
    static targets = ['badge', 'transcriptionArea', 'aiSessionButton']
    static values = {
        statusUrl: String,
        status: String,
        mercureUrl: String,
    }

    eventSource = null

    connect() {
        if (this.statusValue === 'pending' || this.statusValue === 'transcribing') {
            this.subscribe()
        }
    }

    subscribe() {
        if (!this.mercureUrlValue) return

        this.eventSource = new EventSource(this.mercureUrlValue)

        this.eventSource.onmessage = (event) => {
            const data = JSON.parse(event.data)

            if (data.status && data.status !== this.statusValue) {
                this.statusValue = data.status
                this.badgeTarget.textContent = data.status
                this.badgeTarget.className = `badge badge-${data.status}`

                if (data.status === 'completed' && data.transcription) {
                    this.showTranscription(data.transcription)
                    this.enableAiSessionButton()
                    this.close()
                } else if (data.status === 'failed') {
                    window.location.reload()
                }
            }
        }

        this.eventSource.onerror = () => {
            // On SSE error, fall back to a single poll after a delay
            this.close()
            setTimeout(() => this.fallbackPoll(), 5000)
        }
    }

    showTranscription(text) {
        if (!this.hasTranscriptionAreaTarget) return

        this.transcriptionAreaTarget.innerHTML =
            '<h2 class="mb-1">Transcription</h2>' +
            '<div class="transcription-text">' + this.escapeHtml(text) + '</div>'
        this.transcriptionAreaTarget.className = 'card'
    }

    enableAiSessionButton() {
        if (!this.hasAiSessionButtonTarget) return

        this.aiSessionButtonTarget.classList.remove('btn-disabled')
        this.aiSessionButtonTarget.removeAttribute('aria-disabled')
    }

    escapeHtml(text) {
        const div = document.createElement('div')
        div.textContent = text
        return div.innerHTML
    }

    async fallbackPoll() {
        try {
            const response = await fetch(this.statusUrlValue)
            const data = await response.json()

            if (data.status === 'completed' || data.status === 'failed') {
                window.location.reload()
            } else {
                // Still in progress, try SSE again
                this.subscribe()
            }
        } catch {
            // Retry SSE
            this.subscribe()
        }
    }

    close() {
        if (this.eventSource) {
            this.eventSource.close()
            this.eventSource = null
        }
    }

    disconnect() {
        this.close()
    }
}
