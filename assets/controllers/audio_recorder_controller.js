import { Controller } from '@hotwired/stimulus'

export default class extends Controller {
    static targets = ['title', 'microphone', 'startBtn', 'stopBtn', 'status', 'timer', 'fileSize', 'uploadStatus', 'sizeWarning', 'sizeWarningUrgent', 'waveform']
    static values = {
        uploadUrl: String,
        maxFileSize: Number,
    }

    mediaRecorder = null
    chunks = []
    timerInterval = null
    startTime = null
    totalSize = 0
    audioContext = null
    analyser = null
    animationId = null

    async connect() {
        await this.loadMicrophones()
    }

    async loadMicrophones() {
        try {
            await navigator.mediaDevices.getUserMedia({ audio: true })
            const devices = await navigator.mediaDevices.enumerateDevices()
            const audioInputs = devices.filter(d => d.kind === 'audioinput')

            this.microphoneTarget.innerHTML = ''
            audioInputs.forEach(device => {
                const option = document.createElement('option')
                option.value = device.deviceId
                option.textContent = device.label || `Microphone ${audioInputs.indexOf(device) + 1}`
                this.microphoneTarget.appendChild(option)
            })
        } catch (err) {
            this.microphoneTarget.innerHTML = '<option>Microphone access denied</option>'
        }
    }

    async start() {
        const deviceId = this.microphoneTarget.value
        if (!deviceId) return

        try {
            const stream = await navigator.mediaDevices.getUserMedia({
                audio: { deviceId: { exact: deviceId } }
            })

            this.chunks = []
            this.totalSize = 0

            this.mediaRecorder = new MediaRecorder(stream, {
                mimeType: this.getSupportedMimeType(),
            })

            this.mediaRecorder.ondataavailable = (e) => {
                if (e.data.size > 0) {
                    this.chunks.push(e.data)
                    this.totalSize += e.data.size

                    this.fileSizeTarget.textContent = ` - ${(this.totalSize / 1024 / 1024).toFixed(1)} MB`

                    // Size warnings
                    if (this.totalSize >= this.maxFileSizeValue * 0.9) {
                        this.showTarget('sizeWarningUrgent')
                        this.hideTarget('sizeWarning')
                    } else if (this.totalSize >= this.maxFileSizeValue * 0.75) {
                        this.showTarget('sizeWarning')
                    }

                    if (this.totalSize >= this.maxFileSizeValue) {
                        this.stop()
                    }
                }
            }

            this.mediaRecorder.onstop = () => this.handleStop()

            this.mediaRecorder.start(1000)

            this.startTime = Date.now()
            this.timerInterval = setInterval(() => this.updateTimer(), 1000)

            this.startBtnTarget.disabled = true
            this.stopBtnTarget.disabled = false
            this.showTarget('status')
            this.statusTarget.className = 'alert alert-warning'

            // Start waveform visualization
            this.startWaveform(stream)
        } catch (err) {
            alert('Could not start recording: ' + err.message)
        }
    }

    stop() {
        if (this.mediaRecorder && this.mediaRecorder.state !== 'inactive') {
            this.mediaRecorder.stop()
            this.mediaRecorder.stream.getTracks().forEach(t => t.stop())
        }
        clearInterval(this.timerInterval)
        this.startBtnTarget.disabled = false
        this.stopBtnTarget.disabled = true
        this.stopWaveform()
        this.hideTarget('sizeWarning')
        this.hideTarget('sizeWarningUrgent')
    }

    async handleStop() {
        const mimeType = this.mediaRecorder.mimeType
        const blob = new Blob(this.chunks, { type: mimeType })
        const title = this.titleTarget.value || 'Untitled'

        this.statusTarget.textContent = 'Uploading...'

        const formData = new FormData()
        formData.append('audio', blob, 'recording.webm')
        formData.append('title', title)

        try {
            const response = await fetch(this.uploadUrlValue, {
                method: 'POST',
                body: formData,
            })

            const data = await response.json()

            if (response.ok) {
                this.showTarget('uploadStatus')
                this.uploadStatusTarget.textContent = 'Upload successful! Redirecting...'
                this.uploadStatusTarget.className = 'alert alert-success'
                this.hideTarget('status')
                window.location.href = data.redirect
            } else {
                this.showTarget('uploadStatus')
                this.uploadStatusTarget.className = 'alert alert-error'
                this.uploadStatusTarget.textContent = 'Upload failed: ' + data.error
            }
        } catch (err) {
            this.showTarget('uploadStatus')
            this.uploadStatusTarget.className = 'alert alert-error'
            this.uploadStatusTarget.textContent = 'Upload failed: ' + err.message
        }
    }

    updateTimer() {
        const elapsed = Math.floor((Date.now() - this.startTime) / 1000)
        const minutes = String(Math.floor(elapsed / 60)).padStart(2, '0')
        const seconds = String(elapsed % 60).padStart(2, '0')
        this.timerTarget.textContent = `${minutes}:${seconds}`
    }

    getSupportedMimeType() {
        const types = ['audio/webm;codecs=opus', 'audio/webm', 'audio/ogg;codecs=opus', 'audio/mp4']
        for (const type of types) {
            if (MediaRecorder.isTypeSupported(type)) return type
        }
        return ''
    }

    // --- Waveform visualization ---

    startWaveform(stream) {
        if (!this.hasWaveformTarget) return

        this.audioContext = new (window.AudioContext || window.webkitAudioContext)()
        this.analyser = this.audioContext.createAnalyser()
        this.analyser.fftSize = 2048

        const source = this.audioContext.createMediaStreamSource(stream)
        source.connect(this.analyser)

        this.showTarget('waveform')
        this.drawWaveform()
    }

    drawWaveform() {
        const canvas = this.waveformTarget
        const ctx = canvas.getContext('2d')
        const bufferLength = this.analyser.frequencyBinCount
        const dataArray = new Uint8Array(bufferLength)

        // Match canvas resolution to display size
        canvas.width = canvas.offsetWidth * window.devicePixelRatio
        canvas.height = canvas.offsetHeight * window.devicePixelRatio
        ctx.scale(window.devicePixelRatio, window.devicePixelRatio)

        const width = canvas.offsetWidth
        const height = canvas.offsetHeight

        const draw = () => {
            this.animationId = requestAnimationFrame(draw)
            this.analyser.getByteTimeDomainData(dataArray)

            const styles = getComputedStyle(document.documentElement)
            ctx.fillStyle = styles.getPropertyValue('--waveform-bg').trim() || '#f0f0f4'
            ctx.fillRect(0, 0, width, height)

            ctx.lineWidth = 2
            ctx.strokeStyle = styles.getPropertyValue('--accent').trim() || '#4361ee'
            ctx.beginPath()

            const sliceWidth = width / bufferLength
            let x = 0

            for (let i = 0; i < bufferLength; i++) {
                const v = dataArray[i] / 128.0
                const y = (v * height) / 2

                if (i === 0) {
                    ctx.moveTo(x, y)
                } else {
                    ctx.lineTo(x, y)
                }
                x += sliceWidth
            }

            ctx.lineTo(width, height / 2)
            ctx.stroke()
        }

        draw()
    }

    stopWaveform() {
        if (this.animationId) {
            cancelAnimationFrame(this.animationId)
            this.animationId = null
        }
        if (this.audioContext) {
            this.audioContext.close()
            this.audioContext = null
        }
        if (this.hasWaveformTarget) {
            this.hideTarget('waveform')
        }
    }

    // --- Helper methods for show/hide using CSS classes ---

    showTarget(name) {
        const target = this[`${name}Target`]
        if (target) target.classList.remove('hidden')
    }

    hideTarget(name) {
        const target = this[`${name}Target`]
        if (target) target.classList.add('hidden')
    }
}
