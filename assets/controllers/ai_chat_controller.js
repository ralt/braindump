import { Controller } from '@hotwired/stimulus'
import { marked } from 'marked'
import DOMPurify from 'dompurify'

marked.setOptions({ breaks: true, gfm: true })

export default class extends Controller {
    static targets = ['messages', 'input', 'send']
    static values = {
        sessionId: String,
        messagesUrl: String,
        clearUrl: String,
        mercureUrl: String,
        autoFirstMessage: String,
    }

    eventSource = null
    // Track streaming assistant raw text by messageId so we can re-render markdown on each delta
    assistantRaw = new Map()
    awaitingReply = false

    connect() {
        this.openMercure()
        this.prepareHistory()
        this.scrollToBottom()
        this.inputTarget.addEventListener('keydown', this.onKeyDown.bind(this))

        if (this.autoFirstMessageValue && this.messagesTarget.children.length === 0) {
            this.fireWhenMercureReady(() => this.postMessage(this.autoFirstMessageValue))
        }
    }

    /**
     * Wait for the Mercure EventSource to be open before invoking `cb`. Without this,
     * the auto-first-message POST can race the subscription handshake and we'd miss
     * the server's "user" echo (and possibly early "delta" tokens) — leaving an empty
     * chat until the user reloads.
     */
    fireWhenMercureReady(cb) {
        if (!this.eventSource || this.eventSource.readyState === EventSource.OPEN) {
            cb()
            return
        }
        let fired = false
        const fire = () => {
            if (fired) return
            fired = true
            cb()
        }
        this.eventSource.addEventListener('open', fire, { once: true })
        // Fallback in case `open` never fires (Mercure unreachable, etc.) — proceed
        // after a short delay so we don't block the user's first message indefinitely.
        setTimeout(fire, 2000)
    }

    prepareHistory() {
        for (const bubble of this.messagesTarget.querySelectorAll('.chat-bubble')) {
            const body = bubble.querySelector('.chat-bubble-body')
            if (!body) continue

            // Read before rendering: parsing markdown into the element in place overwrites the
            // only copy of the source text in the DOM.
            const raw = body.textContent
            this.addCopyButton(bubble, raw)

            if (bubble.classList.contains('assistant')) {
                this.renderMarkdownInto(body, raw)
            }
        }
    }

    renderMarkdownInto(element, rawText) {
        const html = DOMPurify.sanitize(marked.parse(rawText))
        element.innerHTML = html
    }

    /**
     * Wires a bubble up to the clipboard controller and gives it a Copy button.
     *
     * Done from JS for server-rendered and streamed bubbles alike, rather than half here and
     * half in the Twig template. Two code paths producing the same markup drift, and there's
     * nothing to lose by owning it here: the chat is entirely JS-driven, down to the markdown
     * rendering, so a bubble without scripting has no copy button to miss.
     *
     * The raw markdown is handed over as a Stimulus value, because what ends up in the DOM is
     * the rendered HTML — copying that back out would paste prose with the formatting silently
     * dropped.
     */
    addCopyButton(bubble, raw) {
        const role = bubble.querySelector('.chat-bubble-role')
        if (!role || role.querySelector('.chat-bubble-copy')) return

        bubble.dataset.controller = 'clipboard'
        bubble.dataset.clipboardRawValue = raw
        bubble.querySelector('.chat-bubble-body').dataset.clipboardTarget = 'source'

        // The role label carries the opacity that dims it, so its own text is wrapped to keep
        // the button out of the dimming while both sit on the same row. Reusing the existing
        // text rather than deriving it, so whatever the server called this role survives.
        const label = document.createElement('span')
        label.className = 'chat-bubble-role-text'
        label.textContent = role.textContent.trim()
        role.textContent = ''

        const button = document.createElement('button')
        button.type = 'button'
        button.className = 'chat-bubble-copy'
        button.textContent = 'Copy'
        button.dataset.clipboardTarget = 'button'
        button.dataset.action = 'clipboard#copy'

        role.append(label, button)
    }

    disconnect() {
        if (this.eventSource) {
            this.eventSource.close()
            this.eventSource = null
        }
    }

    openMercure() {
        if (!this.mercureUrlValue) return
        this.eventSource = new EventSource(this.mercureUrlValue, { withCredentials: true })
        this.eventSource.onmessage = (event) => {
            let payload
            try {
                payload = JSON.parse(event.data)
            } catch {
                return
            }
            this.handleMercure(payload)
        }
    }

    handleMercure(payload) {
        if (payload.type === 'user') {
            // Avoid duplicate render if our own POST already echoed locally — we don't render
            // optimistically, so this is the canonical add.
            if (!this.findBubble(payload.messageId)) {
                this.appendBubble('user', payload.messageId, payload.content)
            }
        } else if (payload.type === 'delta') {
            let bubble = this.findBubble(payload.messageId)
            if (!bubble) {
                bubble = this.appendBubble('assistant', payload.messageId, '')
                this.assistantRaw.set(payload.messageId, '')
            }
            const body = bubble.querySelector('.chat-bubble-body')
            const newRaw = (this.assistantRaw.get(payload.messageId) || '') + payload.content
            this.assistantRaw.set(payload.messageId, newRaw)
            this.renderMarkdownInto(body, newRaw)
            // Kept in step with every delta so a copy mid-stream gets what's on screen. The
            // in-memory map is dropped on 'done', so the attribute is what outlives the stream.
            bubble.dataset.clipboardRawValue = newRaw
            this.scrollToBottom()
        } else if (payload.type === 'done') {
            this.assistantRaw.delete(payload.messageId)
            this.setSending(false)
        } else if (payload.type === 'error') {
            this.appendError(payload.message || 'AI request failed')
            this.setSending(false)
        }
    }

    findBubble(messageId) {
        return this.messagesTarget.querySelector(`[data-message-id="${messageId}"]`)
    }

    appendBubble(role, messageId, content) {
        const bubble = document.createElement('div')
        bubble.className = `chat-bubble ${role}`
        bubble.dataset.messageId = messageId

        const roleEl = document.createElement('div')
        roleEl.className = 'chat-bubble-role'
        roleEl.textContent = role

        const body = document.createElement('div')
        body.className = 'chat-bubble-body'
        body.textContent = content

        bubble.appendChild(roleEl)
        bubble.appendChild(body)
        this.addCopyButton(bubble, content)
        this.messagesTarget.appendChild(bubble)
        this.scrollToBottom()
        return bubble
    }

    appendError(message) {
        const bubble = document.createElement('div')
        bubble.className = 'chat-bubble error'
        bubble.textContent = message
        this.messagesTarget.appendChild(bubble)
        this.scrollToBottom()
    }

    onKeyDown(event) {
        if (event.key === 'Enter' && !event.shiftKey) {
            event.preventDefault()
            this.send(event)
        }
    }

    send(event) {
        if (event && typeof event.preventDefault === 'function') event.preventDefault()
        const content = this.inputTarget.value.trim()
        if (!content || this.awaitingReply) return
        this.inputTarget.value = ''
        this.postMessage(content)
    }

    async postMessage(content) {
        this.setSending(true)
        try {
            const response = await fetch(this.messagesUrlValue, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ content }),
                redirect: 'manual',
            })

            // Firewall redirected us to /login — the session expired since the
            // page was loaded. Show an actionable notice rather than a cryptic
            // "HTTP 0" error from a follow-up JSON parse.
            if (response.type === 'opaqueredirect' || response.status === 401) {
                this.appendError('Your session expired. Reload the page and log in again.')
                this.setSending(false)
                return
            }

            if (!response.ok) {
                let errorText = `HTTP ${response.status}`
                try {
                    const data = await response.json()
                    errorText = data.error || errorText
                } catch {}
                this.appendError(errorText)
                this.setSending(false)
            }
            // Otherwise we wait for Mercure 'done' / 'error' to flip the flag.
        } catch (err) {
            this.appendError('Network error: ' + err.message)
            this.setSending(false)
        }
    }

    setSending(sending) {
        this.awaitingReply = sending
        this.sendTarget.disabled = sending
        this.inputTarget.disabled = sending
        if (!sending) this.inputTarget.focus()
    }

    scrollToBottom() {
        this.messagesTarget.scrollTop = this.messagesTarget.scrollHeight
    }

    async clear(event) {
        // Confirmation is handled by the surrounding confirm-dialog: this only runs from the
        // button inside the open dialog.
        if (event && typeof event.preventDefault === 'function') event.preventDefault()

        const response = await fetch(this.clearUrlValue, { method: 'POST' })
        if (!response.ok) return

        this.messagesTarget.replaceChildren()
        this.assistantRaw.clear()
        this.setSending(false)

        // Reload so the controller re-fires the auto-first-message logic against a now-empty session.
        window.location.reload()
    }
}
