import { Controller } from '@hotwired/stimulus';

// Coque de l'application : menu latéral (mobile), menu du compte, menu « Créer » et feuille « Créer » du mobile.
export default class extends Controller {
    static targets = ['side', 'menuButton', 'menuPanel', 'createButton', 'createPanel', 'sheet'];

    connect() {
        this.closeMenu();
        this.closeCreate();
        this.closeNav();
        this.closeSheet();
    }

    disconnect() {
        document.body.classList.remove('no-scroll');
    }

    toggleNav() {
        this.closeSheet();
        this.element.classList.toggle('nav-open');
        document.body.classList.toggle('no-scroll', this.element.classList.contains('nav-open'));
    }

    closeNav() {
        this.element.classList.remove('nav-open');
        document.body.classList.remove('no-scroll');
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

    // Mobile : la feuille du bas qui liste les créations possibles.
    toggleSheet() {
        if (!this.hasSheetTarget) return;
        const open = this.sheetTarget.hidden;
        this.closeNav();
        this.sheetTarget.hidden = !open;
        document.body.classList.toggle('no-scroll', open);
    }

    closeSheet() {
        if (!this.hasSheetTarget) return;
        this.sheetTarget.hidden = true;
        document.body.classList.remove('no-scroll');
    }

    // Un toucher sur le fond sombre ferme la feuille, pas un toucher dans la feuille.
    closeSheetOutside(event) {
        if (event.target === event.currentTarget) this.closeSheet();
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
        this.closeSheet();
    }
}
