import { Controller } from '@hotwired/stimulus';

// Tableaux sur mobile : chaque cellule reçoit le titre de sa colonne (data-label), ce qui permet à la feuille de style
// d'afficher chaque ligne comme une carte (voir « .table » dans app.css, sous 700 px). Sans effet sur ordinateur.
export default class extends Controller {
    connect() {
        document.querySelectorAll('table.table').forEach((table) => this.label(table));
    }

    label(table) {
        const headers = [...table.querySelectorAll('thead tr:last-child th')].map((th) => th.textContent.trim());
        if (!headers.length) return;
        table.querySelectorAll('tbody tr, tfoot tr').forEach((row) => {
            const cells = [...row.children];
            // Une ligne à cellules fusionnées (« Aucun résultat ») ou de largeur différente garde sa présentation.
            if (cells.length !== headers.length || cells.some((cell) => cell.colSpan > 1)) {
                row.classList.add('row-wide');
                return;
            }
            cells.forEach((cell, index) => {
                const title = headers[index];
                if (title && 'Actions' !== title) cell.dataset.label = title;
            });
        });
    }
}
