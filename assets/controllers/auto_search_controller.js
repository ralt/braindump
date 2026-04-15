import { Controller } from '@hotwired/stimulus'

export default class extends Controller {
    static targets = ['input']

    connect() {
        this._timeout = null
        this._lastSubmitted = this.inputTarget.value
    }

    search() {
        clearTimeout(this._timeout)

        this._timeout = setTimeout(() => {
            const value = this.inputTarget.value

            if (value === this._lastSubmitted) {
                return
            }

            this._lastSubmitted = value
            this.element.requestSubmit()
        }, 300)
    }

    disconnect() {
        clearTimeout(this._timeout)
    }
}
