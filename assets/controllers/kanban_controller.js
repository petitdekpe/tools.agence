import { Controller } from '@hotwired/stimulus';

// Tableau à colonnes (type Trello) : on fait glisser une carte d'une colonne à l'autre, ou à un autre rang de sa colonne.
// Chaque carte porte son adresse de déplacement (data-move-url) ; le serveur renvoie 200 quand le déplacement est enregistré.
// En cas d'échec (droits, session expirée, réseau), la page se recharge pour montrer l'état réel.
export default class extends Controller {
    static targets = ['column'];
    static values = { token: String };

    dragged = null;
    dropped = false;

    start(event) {
        this.dragged = event.currentTarget;
        this.dropped = false;
        this.dragged.classList.add('dragging');
        event.dataTransfer.effectAllowed = 'move';
        event.dataTransfer.setData('text/plain', this.dragged.dataset.id || '');
    }

    end() {
        this.dragged?.classList.remove('dragging');
        this.columnTargets.forEach((column) => column.classList.remove('over'));
        const cancelled = this.dragged && !this.dropped;
        this.dragged = null;
        if (cancelled) {
            window.location.reload(); // glissement annulé : on remet la carte à sa place
        }
    }

    over(event) {
        if (!this.dragged) return;
        event.preventDefault();
        event.dataTransfer.dropEffect = 'move';
        const column = event.currentTarget;
        column.classList.add('over');
        const after = this.cardAfter(column, event.clientY);
        if (after) {
            column.insertBefore(this.dragged, after);
        } else {
            column.appendChild(this.dragged);
        }
    }

    leave(event) {
        if (!event.currentTarget.contains(event.relatedTarget)) {
            event.currentTarget.classList.remove('over');
        }
    }

    async drop(event) {
        event.preventDefault();
        const card = this.dragged;
        if (!card) return;
        this.dropped = true;
        const column = event.currentTarget;
        column.classList.remove('over');
        const index = [...column.querySelectorAll('.kb-card')].indexOf(card);
        await this.save(card, column.dataset.status, index);
    }

    // La première carte (hors celle qu'on déplace) dont le milieu est sous le curseur : la carte se place avant elle.
    cardAfter(column, y) {
        const cards = [...column.querySelectorAll('.kb-card:not(.dragging)')];

        return cards.find((card) => {
            const box = card.getBoundingClientRect();
            return y < box.top + box.height / 2;
        }) ?? null;
    }

    async save(card, statut, index) {
        try {
            const response = await fetch(card.dataset.moveUrl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': this.tokenValue },
                body: JSON.stringify({ statut, index }),
            });
            if (!response.ok) throw new Error(String(response.status));
            const data = await response.json();
            card.dataset.status = statut;
            this.refreshCounts();
            if (undefined !== data.progress) {
                document.querySelectorAll('[data-kanban-progress]').forEach((bar) => {
                    bar.style.width = `${data.progress}%`;
                });
                document.querySelectorAll('[data-kanban-progress-label]').forEach((label) => {
                    label.textContent = `${data.progress} %`;
                });
            }
        } catch {
            window.location.reload();
        }
    }

    refreshCounts() {
        this.element.querySelectorAll('.kb-col').forEach((column) => {
            const counter = column.querySelector('.kb-count');
            if (counter) counter.textContent = String(column.querySelectorAll('.kb-card').length);
        });
    }
}
