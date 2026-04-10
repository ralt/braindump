import { Controller } from '@hotwired/stimulus'
import { Terminal } from 'xterm'
import { FitAddon } from '@xterm/addon-fit'

export default class extends Controller {
    static targets = ['terminal', 'status']
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

        this.terminal.writeln('Starting Claude session...')

        // Handle user input
        this.terminal.onData((data) => {
            this.sendInput(data)
        })

        // Start the session
        await this.startSession()
    }

    async startSession() {
        try {
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

            // Subscribe to Mercure for output
            const topic = encodeURIComponent(data.mercureTopic)
            const url = this.mercureUrlValue + topic
            this.eventSource = new EventSource(url)

            this.eventSource.onmessage = (event) => {
                const payload = JSON.parse(event.data)
                if (payload.output) {
                    this.terminal.write(payload.output)
                }
            }

            this.eventSource.onerror = () => {
                this.statusTarget.textContent = 'Disconnected'
            }

            this.statusTarget.textContent = 'Running'
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

            fetch(`/api/claude-sessions/${this.sessionId}/input`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ input }),
            }).catch(() => {})
        }, 50)
    }

    async close() {
        if (this.sessionId) {
            try {
                await fetch(`/api/claude-sessions/${this.sessionId}`, {
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
        this.statusTarget.textContent = 'Closed'
    }

    disconnect() {
        if (this.eventSource) {
            this.eventSource.close()
        }
        if (this.terminal) {
            this.terminal.dispose()
        }
    }
}
