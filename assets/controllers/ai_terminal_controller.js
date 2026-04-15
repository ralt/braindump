import { Controller } from '@hotwired/stimulus'
import { Terminal } from 'xterm'
import { FitAddon } from '@xterm/addon-fit'
import 'xterm/css/xterm.min.css'

export default class extends Controller {
    static targets = ['terminal', 'status', 'closeBtn']
    static values = {
        recordingId: String,
        startUrl: String,
        mercureUrl: String,
    }

    terminal = null
    fitAddon = null
    sessionId = null
    eventSource = null
    inputBuffer = ''
    inputTimeout = null

    async connect() {
        // Initialize xterm.js
        this.terminal = new Terminal({
            cursorBlink: true,
            fontSize: 14,
            fontFamily: 'Menlo, Monaco, "Courier New", monospace',
            theme: {
                background: '#1a1a2e',
                foreground: '#e0e0e0',
                cursor: '#4361ee',
            },
        })

        this.fitAddon = new FitAddon()
        this.terminal.loadAddon(this.fitAddon)
        this.terminal.open(this.terminalTarget)
        this.fitAddon.fit()

        window.addEventListener('resize', () => this.fitAddon.fit())

        this.terminal.writeln('Starting AI session...')

        // Handle user input
        this.terminal.onData((data) => {
            this.sendInput(data)
        })

        // Start the session
        await this.startSession()
    }

    async startSession() {
        if (this._starting) return
        this._starting = true

        try {
            // 1. Create the session (but don't dispatch to worker yet)
            const response = await fetch(this.startUrlValue, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
            })

            if (!response.ok) {
                const data = await response.json()
                this.terminal.writeln(`\r\nError: ${data.error || 'Failed to start session'}`)
                this.statusTarget.textContent = 'Error'
                return
            }

            const data = await response.json()
            this.sessionId = data.sessionId

            // 2. Subscribe to Mercure BEFORE triggering the worker
            const topic = encodeURIComponent(data.mercureTopic)
            const url = this.mercureUrlValue + topic
            this.eventSource = new EventSource(url, { withCredentials: true })

            this.eventSource.onmessage = (event) => {
                const payload = JSON.parse(event.data)
                if (payload.output) {
                    this.terminal.write(payload.output)
                }
            }

            this.eventSource.onerror = () => {
                this.statusTarget.textContent = 'Disconnected'
            }

            // 3. Wait for EventSource to connect, then tell the worker to start
            await new Promise((resolve, reject) => {
                this.eventSource.addEventListener('open', resolve, { once: true })
                setTimeout(reject, 5000)
            }).catch(() => {
                this.terminal.writeln('\r\nWarning: Mercure connection slow, proceeding anyway...')
            })

            // Skip dispatch if the session is already running (e.g., page reload)
            if (data.status === 'running') {
                this.terminal.writeln('Reconnected to existing session.\r\n')
            } else {
                await fetch(`/api/ai-sessions/${this.sessionId}/dispatch`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                })
            }

            this.statusTarget.textContent = 'Running'

            // Poll session status as fallback in case the worker crashes
            // before publishing anything to Mercure
            this.statusPoll = setInterval(() => this.checkSessionStatus(), 10000)
        } catch (err) {
            this.terminal.writeln(`\r\nConnection error: ${err.message}`)
            this.statusTarget.textContent = 'Error'
        }
    }

    sendInput(data) {
        if (!this.sessionId) return

        // Batch keystrokes with a small debounce
        this.inputBuffer += data
        clearTimeout(this.inputTimeout)

        this.inputTimeout = setTimeout(() => {
            const input = this.inputBuffer
            this.inputBuffer = ''

            fetch(`/api/ai-sessions/${this.sessionId}/input`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ input }),
            }).catch(() => {})
        }, 50)
    }

    async checkSessionStatus() {
        if (!this.sessionId) return
        try {
            const response = await fetch(`/api/ai-sessions/${this.sessionId}/status`)
            if (!response.ok) return
            const data = await response.json()
            if (data.status === 'closed') {
                this.terminal.writeln('\r\nSession closed (worker stopped).')
                this.markClosed()
                this.stopPolling()
                if (this.eventSource) {
                    this.eventSource.close()
                    this.eventSource = null
                }
            }
        } catch {
            // ignore fetch errors
        }
    }

    stopPolling() {
        if (this.statusPoll) {
            clearInterval(this.statusPoll)
            this.statusPoll = null
        }
    }

    async close() {
        if (this.sessionId) {
            try {
                await fetch(`/api/ai-sessions/${this.sessionId}`, {
                    method: 'DELETE',
                })
            } catch {
                // ignore
            }
        }

        if (this.eventSource) {
            this.eventSource.close()
        }

        this.terminal.writeln('\r\nSession closed.')
        this.markClosed()
    }

    markClosed() {
        this.statusTarget.textContent = 'Closed'
        this.closeBtnTarget.disabled = true
    }

    disconnect() {
        this.stopPolling()
        if (this.eventSource) {
            this.eventSource.close()
        }
        if (this.terminal) {
            this.terminal.dispose()
        }
    }
}
