import { Controller } from '@hotwired/stimulus'

export default class extends Controller {
    static targets = ['badge', 'content']
    static values = {
        statusUrl: String,
        contentUrl: String,
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
                if (this.hasBadgeTarget) {
                    this.badgeTarget.textContent = data.status
                    this.badgeTarget.className = `badge badge-${data.status}`
                }
                this.refreshContent()
                if (data.status === 'completed' || data.status === 'failed') {
                    this.close()
                }
            }
        }

        this.eventSource.onerror = () => {
            // On SSE error, fall back to a single poll after a delay
            this.close()
            setTimeout(() => this.fallbackPoll(), 5000)
        }
    }

    async refreshContent() {
        if (!this.hasContentTarget || !this.contentUrlValue) return
        try {
            const response = await fetch(this.contentUrlValue)
            if (response.ok) {
                this.contentTarget.innerHTML = await response.text()
            }
        } catch {
            // ignore — user can refresh manually if needed
        }
    }

    async fallbackPoll() {
        try {
            const response = await fetch(this.statusUrlValue)
            const data = await response.json()

            if (data.status !== this.statusValue) {
                this.statusValue = data.status
                if (this.hasBadgeTarget) {
                    this.badgeTarget.textContent = data.status
                    this.badgeTarget.className = `badge badge-${data.status}`
                }
                this.refreshContent()
            }

            if (data.status !== 'completed' && data.status !== 'failed') {
                this.subscribe()
            }
        } catch {
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
