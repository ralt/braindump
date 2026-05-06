import { Controller } from '@hotwired/stimulus'

const STORAGE_KEY = 'chat-mic-device'

export default class extends Controller {
    static targets = ['button', 'output', 'device']
    static values = {
        transcribeUrl: String,
        maxSeconds: Number,
    }

    mediaRecorder = null
    chunks = []
    autoStopTimer = null

    async connect() {
        await this.populateDevices()
        if (navigator.mediaDevices?.addEventListener) {
            this.deviceChangeHandler = () => this.populateDevices()
            navigator.mediaDevices.addEventListener('devicechange', this.deviceChangeHandler)
        }
    }

    disconnect() {
        if (this.deviceChangeHandler && navigator.mediaDevices?.removeEventListener) {
            navigator.mediaDevices.removeEventListener('devicechange', this.deviceChangeHandler)
        }
    }

    async populateDevices() {
        if (!this.hasDeviceTarget || !navigator.mediaDevices?.enumerateDevices) return
        try {
            const devices = await navigator.mediaDevices.enumerateDevices()
            const inputs = devices.filter((d) => d.kind === 'audioinput')
            const previous = this.deviceTarget.value || localStorage.getItem(STORAGE_KEY) || ''
            this.deviceTarget.replaceChildren()
            inputs.forEach((d, i) => {
                const opt = document.createElement('option')
                opt.value = d.deviceId
                opt.textContent = d.label || `Microphone ${i + 1}`
                this.deviceTarget.appendChild(opt)
            })
            if (previous && [...this.deviceTarget.options].some((o) => o.value === previous)) {
                this.deviceTarget.value = previous
            }
        } catch {
            // ignore — labels will be empty until permission granted
        }
    }

    onDeviceChange() {
        if (this.hasDeviceTarget && this.deviceTarget.value) {
            localStorage.setItem(STORAGE_KEY, this.deviceTarget.value)
        }
    }

    async toggle() {
        if (this.mediaRecorder && this.mediaRecorder.state === 'recording') {
            this.stop()
        } else {
            await this.start()
        }
    }

    async start() {
        try {
            const deviceId = this.hasDeviceTarget ? this.deviceTarget.value : null
            const audio = deviceId ? { deviceId: { exact: deviceId } } : true
            const stream = await navigator.mediaDevices.getUserMedia({ audio })
            // After permission is granted we get device labels — refresh the picker.
            this.populateDevices()
            this.chunks = []
            this.mediaRecorder = new MediaRecorder(stream, { mimeType: this.pickMimeType() })

            this.mediaRecorder.ondataavailable = (e) => {
                if (e.data.size > 0) this.chunks.push(e.data)
            }
            this.mediaRecorder.onstop = () => this.onStop(stream)

            this.mediaRecorder.start()
            this.buttonTarget.classList.add('recording')
            this.buttonTarget.textContent = '⏺'

            const cap = (this.maxSecondsValue || 30) * 1000
            this.autoStopTimer = setTimeout(() => this.stop(), cap)
        } catch (err) {
            alert('Microphone access denied: ' + err.message)
        }
    }

    stop() {
        if (this.autoStopTimer) {
            clearTimeout(this.autoStopTimer)
            this.autoStopTimer = null
        }
        if (this.mediaRecorder && this.mediaRecorder.state === 'recording') {
            this.mediaRecorder.stop()
        }
    }

    async onStop(stream) {
        stream.getTracks().forEach((t) => t.stop())
        this.buttonTarget.classList.remove('recording')
        this.buttonTarget.textContent = '⏳'
        this.buttonTarget.disabled = true

        const blob = new Blob(this.chunks, { type: this.mediaRecorder.mimeType })
        const formData = new FormData()
        formData.append('audio', blob, 'voice.webm')

        try {
            const response = await fetch(this.transcribeUrlValue, {
                method: 'POST',
                body: formData,
            })
            if (response.ok) {
                const data = await response.json()
                this.appendToOutput(data.text || '')
            } else {
                let msg = `HTTP ${response.status}`
                try {
                    const data = await response.json()
                    msg = data.error || msg
                } catch {}
                alert('Transcription failed: ' + msg)
            }
        } catch (err) {
            alert('Transcription failed: ' + err.message)
        } finally {
            this.buttonTarget.disabled = false
            this.buttonTarget.textContent = '🎤'
        }
    }

    appendToOutput(text) {
        if (!this.hasOutputTarget) return
        const target = this.outputTarget
        if (target.value.trim() === '') {
            target.value = text
        } else {
            target.value = target.value.trimEnd() + ' ' + text
        }
        target.focus()
        target.setSelectionRange(target.value.length, target.value.length)
    }

    pickMimeType() {
        const types = ['audio/webm;codecs=opus', 'audio/webm', 'audio/ogg;codecs=opus', 'audio/mp4']
        for (const type of types) {
            if (MediaRecorder.isTypeSupported(type)) return type
        }
        return ''
    }
}
