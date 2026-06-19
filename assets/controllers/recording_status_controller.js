import { Controller } from '@hotwired/stimulus'

export default class extends Controller {
    static targets = ['badge', 'content', 'title']
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
            // Catch up immediately: a fast transcription can finish (or fail) between page
            // render and the SSE connecting, and Mercure doesn't replay missed events — so
            // without this the page can sit on "Transcribing…" forever after a quick failure.
            this.poll()
        }
    }

    subscribe() {
        if (!this.mercureUrlValue) return

        this.eventSource = new EventSource(this.mercureUrlValue)

        this.eventSource.onmessage = (event) => this.applyData(JSON.parse(event.data))

        this.eventSource.onerror = () => {
            // On SSE error, fall back to a single poll after a delay
            this.close()
            setTimeout(() => this.fallbackPoll(), 5000)
        }
    }

    applyData(data) {
        this.applyTitle(data)
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

    async poll() {
        try {
            const response = await fetch(this.statusUrlValue)
            if (response.ok) this.applyData(await response.json())
        } catch {
            // ignore — the SSE or a later poll will catch up
        }
    }

    applyTitle(data) {
        if (!this.hasTitleTarget) return
        const status = data.status ?? this.statusValue
        const display = this.computeDisplayTitle(data.title, status)
        if (this.titleTarget.textContent === display) return
        this.titleTarget.textContent = display
        if (document.title.endsWith(' - Braindump')) {
            document.title = `${display} - Braindump`
        }
    }

    computeDisplayTitle(title, status) {
        if (title && title.trim() !== '') return title
        if (status === 'pending' || status === 'transcribing') return 'Transcribing…'
        return 'Untitled'
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
        await this.poll()
        if (this.statusValue !== 'completed' && this.statusValue !== 'failed') {
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
