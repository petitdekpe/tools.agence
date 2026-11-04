import { Controller } from '@hotwired/stimulus';

// Coque de l'application : menu latéral (mobile), menu du compte et menu « Créer ».
export default class extends Controller {
    static targets = ['side', 'menuButton', 'menuPanel', 'createButton', 'createPanel'];

    connect() {
        this.closeMenu();
        this.closeCreate();
        this.closeNav();
    }

    toggleNav() {
        this.element.classList.toggle('nav-open');
    }

    closeNav() {
        this.element.classList.remove('nav-open');
    }

    toggleMenu() {
        const wasHidden = this.menuPanelTarget.hidden;
        this.closeCreate();
        this.menuPanelTarget.hidden = !wasHidden;
        this.menuButtonTarget.setAttribute('aria-expanded', String(wasHidden));
    }

    toggleCreate() {
        const wasHidden = this.createPanelTarget.hidden;
        this.closeMenu();
        this.createPanelTarget.hidden = !wasHidden;
        this.createButtonTarget.setAttribute('aria-expanded', String(wasHidden));
    }

    closeMenu() {
        if (!this.hasMenuPanelTarget) return;
        this.menuPanelTarget.hidden = true;
        this.menuButtonTarget.setAttribute('aria-expanded', 'false');
    }

    closeCreate() {
        if (!this.hasCreatePanelTarget) return;
        this.createPanelTarget.hidden = true;
        this.createButtonTarget.setAttribute('aria-expanded', 'false');
    }

    // Un clic en dehors d'un menu ouvert le referme.
    outside(event) {
        if (this.hasMenuPanelTarget && !this.menuPanelTarget.parentElement.contains(event.target)) this.closeMenu();
        if (this.hasCreatePanelTarget && !this.createPanelTarget.parentElement.contains(event.target)) this.closeCreate();
    }

    escape() {
        this.closeMenu();
        this.closeCreate();
        this.closeNav();
    }
}
