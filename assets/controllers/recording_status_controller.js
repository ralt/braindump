import { Controller } from '@hotwired/stimulus'

export default class extends Controller {
    static targets = ['badge', 'transcriptionArea']
    static values = {
        statusUrl: String,
        status: String,
    }

    connect() {
        if (this.statusValue === 'pending' || this.statusValue === 'transcribing') {
            this.poll()
        }
    }

    poll() {
        this.interval = setInterval(async () => {
            try {
                const response = await fetch(this.statusUrlValue)
                const data = await response.json()

                if (data.status !== this.statusValue) {
                    this.statusValue = data.status
                    this.badgeTarget.textContent = data.status
                    this.badgeTarget.className = `badge badge-${data.status}`

                    if (data.status === 'completed' || data.status === 'failed') {
                        clearInterval(this.interval)
                        // Reload the page to show the transcription
                        window.location.reload()
                    }
                }
            } catch (err) {
                // silently retry
            }
        }, 3000)
    }

    disconnect() {
        if (this.interval) {
            clearInterval(this.interval)
        }
    }
}
