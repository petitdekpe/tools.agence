import { Controller } from '@hotwired/stimulus';

const WARM_KEY = 'procom-warm-at';
const WARM_EVERY = 6 * 3600 * 1000;

// Application installable et mode hors ligne : enregistre le service worker (public/sw.js), signale la perte de réseau,
// bloque l'envoi des formulaires hors ligne, propose l'installation et efface les copies de pages à la déconnexion.
export default class extends Controller {
    static targets = ['banner', 'install', 'ios'];

    connect() {
        this.register();

        this.onNetwork = () => this.syncNetwork();
        window.addEventListener('online', this.onNetwork);
        window.addEventListener('offline', this.onNetwork);
        this.syncNetwork();

        // Phase de capture : le formulaire est arrêté avant que Turbo ne tente de l'envoyer.
        this.onSubmit = (event) => this.blockSubmit(event);
        document.addEventListener('submit', this.onSubmit, true);

        this.onPrompt = (event) => {
            event.preventDefault();
            window.__installPrompt = event; // l'événement ne part qu'une fois par chargement : on le garde d'une page Turbo à l'autre
            this.refreshInstall();
        };
        this.onInstalled = () => {
            window.__installPrompt = null;
            this.refreshInstall();
        };
        window.addEventListener('beforeinstallprompt', this.onPrompt);
        window.addEventListener('appinstalled', this.onInstalled);
        this.refreshInstall();
    }

    disconnect() {
        window.removeEventListener('online', this.onNetwork);
        window.removeEventListener('offline', this.onNetwork);
        document.removeEventListener('submit', this.onSubmit, true);
        window.removeEventListener('beforeinstallprompt', this.onPrompt);
        window.removeEventListener('appinstalled', this.onInstalled);
    }

    async register() {
        if (!('serviceWorker' in navigator)) return;
        try {
            const registration = await navigator.serviceWorker.register('/sw.js', { scope: '/' });
            await navigator.serviceWorker.ready;
            // Page de connexion : plus personne n'est connecté, les copies de pages de la session précédente doivent partir.
            if (location.pathname.startsWith('/login')) {
                this.send(registration, { type: 'clear-pages' });
            } else {
                this.warm(registration);
            }
        } catch (error) {
            // Pas de service worker (HTTP non sécurisé, navigation privée...) : l'application fonctionne comme avant.
        }
    }

    // Une fois connecté, charge à l'avance les pages du menu pour qu'elles s'ouvrent hors ligne.
    warm(registration) {
        if (!navigator.onLine || !document.querySelector('.nav')) return;
        try {
            // Les pages visitées ensuite sont gardées par le service worker lui-même : le préchargement n'a lieu qu'une fois par période.
            if (Date.now() - Number(localStorage.getItem(WARM_KEY) || 0) < WARM_EVERY) return;
            localStorage.setItem(WARM_KEY, String(Date.now()));
        } catch (error) {
            // localStorage indisponible : on précharge à chaque fois.
        }
        const urls = [...document.querySelectorAll('.nav a[href]')]
            .filter((link) => link.origin === location.origin && !link.target)
            .map((link) => link.href);
        setTimeout(() => this.send(registration, { type: 'precache', urls: [location.href, ...new Set(urls)] }), 2500);
    }

    send(registration, message) {
        (navigator.serviceWorker.controller || registration?.active)?.postMessage(message);
    }

    // Déconnexion : on efface les pages gardées pour que le prochain utilisateur de l'appareil n'y ait pas accès.
    logout() {
        navigator.serviceWorker?.controller?.postMessage({ type: 'clear-pages' });
    }

    syncNetwork() {
        const offline = !navigator.onLine;
        this.element.classList.toggle('is-offline', offline);
        if (this.hasBannerTarget) this.bannerTarget.hidden = !offline;
    }

    blockSubmit(event) {
        const method = (event.target.getAttribute('method') || 'get').toLowerCase();
        if (navigator.onLine || 'get' === method) return;
        event.preventDefault();
        event.stopPropagation();
        if (this.hasBannerTarget) {
            this.bannerTarget.hidden = false;
            this.bannerTarget.classList.remove('shake');
            void this.bannerTarget.offsetWidth; // relance l'animation
            this.bannerTarget.classList.add('shake');
        }
    }

    get standalone() {
        return window.matchMedia('(display-mode: standalone)').matches || true === navigator.standalone;
    }

    refreshInstall() {
        const canPrompt = !this.standalone && !!window.__installPrompt;
        const ios = !this.standalone && !canPrompt && /iphone|ipad|ipod/i.test(navigator.userAgent);
        this.installTargets.forEach((element) => { element.hidden = !canPrompt; });
        this.iosTargets.forEach((element) => { element.hidden = !ios; });
    }

    async install() {
        const prompt = window.__installPrompt;
        if (!prompt) return;
        prompt.prompt();
        await prompt.userChoice;
        window.__installPrompt = null;
        this.refreshInstall();
    }

    // Safari sur iPhone ne propose pas d'installation par script : on explique le geste.
    iosHelp() {
        window.alert('Pour installer l\'application : touchez le bouton Partager de Safari, puis « Sur l\'écran d\'accueil ».');
    }
}
