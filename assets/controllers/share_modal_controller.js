import { Controller } from '@hotwired/stimulus'

export default class extends Controller {
    static targets = ['email', 'permission', 'message']
    static values = {
        shareUrl: String,
    }

    open() {
        const modal = document.getElementById('share-modal')
        if (modal) {
            modal.style.display = modal.style.display === 'none' ? 'block' : 'none'
        }
    }

    async submit(event) {
        event.preventDefault()

        const email = this.emailTarget.value
        const permission = this.permissionTarget.value

        if (!email) return

        try {
            const response = await fetch(this.shareUrlValue, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ email, permission }),
            })

            const data = await response.json()

            if (response.ok) {
                this.messageTarget.innerHTML = '<div class="alert alert-success">Shared successfully!</div>'
                this.emailTarget.value = ''
                setTimeout(() => window.location.reload(), 1000)
            } else {
                this.messageTarget.innerHTML = `<div class="alert alert-error">${data.error}</div>`
            }
        } catch (err) {
            this.messageTarget.innerHTML = `<div class="alert alert-error">Failed: ${err.message}</div>`
        }
    }
}
