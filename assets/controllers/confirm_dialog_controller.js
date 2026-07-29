import { Controller } from '@hotwired/stimulus'

/**
 * Confirmation via the native <dialog> element instead of window.confirm(). The browser's
 * own dialog is a window-manager-level popup, which some window managers mishandle for
 * focus; this is ordinary in-page DOM, and showModal() still gives us a backdrop, focus
 * trapping and Escape-to-close for free.
 *
 * The destructive action lives inside the dialog rather than on the trigger, so if scripting
 * never starts the dialog simply can't open and nothing is submitted — it fails closed.
 */
export default class extends Controller {
    static targets = ['dialog']

    open() {
        this.dialogTarget.showModal()
    }

    close() {
        this.dialogTarget.close()
    }

    /**
     * showModal() centres the dialog over a full-viewport backdrop, so a click that lands on
     * the dialog element itself — rather than on its content — is a click outside the box.
     */
    closeOnBackdrop(event) {
        if (event.target === this.dialogTarget) {
            this.dialogTarget.close()
        }
    }
}
