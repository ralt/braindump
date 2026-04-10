import { Controller } from '@hotwired/stimulus'

export default class extends Controller {
    static targets = ['title', 'microphone', 'startBtn', 'stopBtn', 'status', 'timer', 'fileSize', 'uploadStatus']
    static values = {
        uploadUrl: String,
        maxFileSize: Number,
    }

    mediaRecorder = null
    chunks = []
    timerInterval = null
    startTime = null
    totalSize = 0

    async connect() {
        await this.loadMicrophones()
    }

    async loadMicrophones() {
        try {
            // Request permission first to get device labels
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

                    if (this.totalSize >= this.maxFileSizeValue) {
                        this.stop()
                    }
                }
            }

            this.mediaRecorder.onstop = () => this.handleStop()

            this.mediaRecorder.start(1000) // collect data every second

            this.startTime = Date.now()
            this.timerInterval = setInterval(() => this.updateTimer(), 1000)

            this.startBtnTarget.disabled = true
            this.stopBtnTarget.disabled = false
            this.statusTarget.style.display = 'block'
            this.statusTarget.className = 'alert'
            this.statusTarget.style.background = '#fff3cd'
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
                this.uploadStatusTarget.style.display = 'block'
                this.uploadStatusTarget.textContent = 'Upload successful! Redirecting...'
                this.statusTarget.style.display = 'none'
                window.location.href = data.redirect
            } else {
                this.uploadStatusTarget.style.display = 'block'
                this.uploadStatusTarget.className = 'alert alert-error'
                this.uploadStatusTarget.textContent = 'Upload failed: ' + data.error
            }
        } catch (err) {
            this.uploadStatusTarget.style.display = 'block'
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
}
