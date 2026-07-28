import { Controller } from '@hotwired/stimulus';

/*
 * Shows an "Install" button when the browser offers PWA installation.
 *
 * The beforeinstallprompt event is captured globally in base.html.twig
 * (window.deferredInstallPrompt) because it fires once per page load and
 * Turbo navigation means this controller may connect long after that.
 */
export default class extends Controller {
    static targets = ['button', 'hint'];

    connect() {
        if (window.matchMedia('(display-mode: standalone)').matches || navigator.standalone === true) {
            this.hintTarget.textContent = 'Braindump is already installed and running as an app.';
            return;
        }

        if (/iphone|ipad|ipod/i.test(navigator.userAgent)) {
            this.hintTarget.textContent = 'On iPhone/iPad: open Braindump in Safari, tap the Share button, then "Add to Home Screen".';
            return;
        }

        this.onInstallable = () => this.showButton();
        window.addEventListener('pwa:installable', this.onInstallable);

        if (window.deferredInstallPrompt) {
            this.showButton();
        }
    }

    disconnect() {
        if (this.onInstallable) {
            window.removeEventListener('pwa:installable', this.onInstallable);
        }
    }

    showButton() {
        this.buttonTarget.classList.remove('hidden');
        this.hintTarget.classList.add('hidden');
    }

    async install() {
        const prompt = window.deferredInstallPrompt;
        if (!prompt) {
            return;
        }

        prompt.prompt();
        const { outcome } = await prompt.userChoice;
        window.deferredInstallPrompt = null;

        this.buttonTarget.classList.add('hidden');
        this.hintTarget.textContent = outcome === 'accepted'
            ? 'Installing… look for Braindump on your home screen or in your app list.'
            : 'Install dismissed. You can still install anytime from your browser menu ("Install app" / "Add to Home Screen").';
        this.hintTarget.classList.remove('hidden');
    }
}
