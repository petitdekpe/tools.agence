import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['list', 'thread', 'input', 'status', 'send', 'crumb'];
    static values = { api: String, token: String };

    items = [];
    current = null; // correction ouverte (détail complet) ou null
    busy = false;

    connect() {
        this.showEmpty();
        this.loadList();
    }

    // ---------- API ----------

    async request(url, method = 'GET', body = null) {
        const response = await fetch(url, {
            method,
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': this.tokenValue },
            body: body ? JSON.stringify(body) : undefined,
        });
        if (response.redirected) {
            window.location.reload(); // session expirée
            return null;
        }
        if (204 === response.status) {
            return null;
        }
        const data = await response.json().catch(() => ({}));
        if (!response.ok) {
            throw new Error(data.error || 'Erreur inattendue.');
        }

        return data;
    }

    async loadList() {
        try {
            this.items = await this.request(this.apiValue);
        } catch {
            this.items = [];
        }
        this.renderList();
    }

    // ---------- Actions ----------

    keydown(event) {
        if ('Enter' === event.key && (event.ctrlKey || event.metaKey)) {
            event.preventDefault();
            this.submit(event);
        }
    }

    newChat() {
        this.current = null;
        this.inputTarget.value = '';
        this.crumbTarget.textContent = '';
        this.showEmpty();
        this.renderList();
        this.inputTarget.focus();
    }

    async open(id) {
        if (this.busy) return;
        try {
            this.current = await this.request(`${this.apiValue}/${id}`);
            this.renderThread();
            this.renderList();
        } catch (e) {
            this.setStatus(e.message);
        }
    }

    async submit(event) {
        event.preventDefault();
        if (this.busy) return;
        const text = this.inputTarget.value.trim();
        if (!text) {
            this.setStatus('Collez un texte à relire.');
            return;
        }

        // Un texte saisi pendant qu'une correction est ouverte crée une nouvelle correction.
        this.current = null;
        this.renderPending(text);
        this.setBusy(true);
        try {
            this.current = await this.request(this.apiValue, 'POST', { text });
            this.inputTarget.value = '';
            await this.loadList();
            this.renderThread();
        } catch (e) {
            this.showEmpty();
            this.setStatus(e.message);
        } finally {
            this.setBusy(false);
        }
    }

    startEdit() {
        const bubble = this.threadTarget.querySelector('.bubble');
        if (!bubble || !this.current) return;
        bubble.classList.add('editing');
        bubble.innerHTML = '';

        const area = document.createElement('textarea');
        area.value = this.current.text;
        area.rows = Math.min(14, Math.max(4, this.current.text.split('\n').length + 1));
        area.maxLength = 20000;

        const row = document.createElement('div');
        row.className = 'edit-actions';
        const cancel = this.button('Annuler', 'ghost', () => this.renderThread());
        const save = this.button('Enregistrer et relire', 'btn-mini', () => this.saveEdit(area.value));
        row.append(cancel, save);
        bubble.append(area, row);
        area.focus();
    }

    async saveEdit(text) {
        if (this.busy || !this.current) return;
        const id = this.current.id;
        const previous = this.current;
        this.setBusy(true);
        this.renderPending(text.trim(), true);
        try {
            this.current = await this.request(`${this.apiValue}/${id}`, 'PUT', { text });
            await this.loadList();
        } catch (e) {
            this.current = previous;
            this.setStatus(e.message);
        } finally {
            this.setBusy(false);
            this.renderThread();
        }
    }

    async rename(id, event) {
        event.stopPropagation();
        const item = this.items.find((i) => i.id === id);
        const title = window.prompt('Nouveau titre :', item?.title ?? '');
        if (null === title || !title.trim()) return;
        try {
            const updated = await this.request(`${this.apiValue}/${id}`, 'PUT', { title });
            if (this.current?.id === id) this.current.title = updated.title;
            await this.loadList();
            this.renderThread();
        } catch (e) {
            this.setStatus(e.message);
        }
    }

    async remove(id, event) {
        event.stopPropagation();
        if (!window.confirm('Supprimer cette correction ?')) return;
        try {
            await this.request(`${this.apiValue}/${id}`, 'DELETE');
            if (this.current?.id === id) this.newChat();
            await this.loadList();
        } catch (e) {
            this.setStatus(e.message);
        }
    }

    async copy(button) {
        try {
            await navigator.clipboard.writeText(this.current.result);
            button.textContent = 'Copié ✓';
            setTimeout(() => (button.textContent = 'Copier'), 1500);
        } catch {
            /* presse-papiers indisponible */
        }
    }

    // ---------- Rendu ----------

    setStatus(message) {
        this.statusTarget.textContent = message || 'Ctrl + Entrée pour envoyer';
    }

    setBusy(busy) {
        this.busy = busy;
        this.sendTarget.disabled = busy;
        this.setStatus(busy ? 'Relecture en cours…' : '');
    }

    el(tag, className, text) {
        const node = document.createElement(tag);
        if (className) node.className = className;
        if (undefined !== text) node.textContent = text;

        return node;
    }

    button(label, className, onClick) {
        const b = this.el('button', className, label);
        b.type = 'button';
        b.addEventListener('click', onClick);

        return b;
    }

    formatDate(iso) {
        return new Date(iso).toLocaleString('fr-FR', { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' });
    }

    renderList() {
        this.listTarget.innerHTML = '';
        if (!this.items.length) {
            this.listTarget.append(this.el('li', 'history-empty', 'Aucune correction pour le moment.'));
            return;
        }
        for (const item of this.items) {
            const li = this.el('li', 'history-item' + (this.current?.id === item.id ? ' active' : ''));
            li.addEventListener('click', () => this.open(item.id));

            const text = this.el('div', 'history-text');
            text.append(this.el('span', 'history-title', item.title), this.el('small', null, this.formatDate(item.updatedAt)));

            const tools = this.el('div', 'history-tools');
            const edit = this.el('button', null, '✎');
            edit.title = 'Renommer';
            edit.addEventListener('click', (e) => this.rename(item.id, e));
            const del = this.el('button', null, '✕');
            del.title = 'Supprimer';
            del.addEventListener('click', (e) => this.remove(item.id, e));
            tools.append(edit, del);

            li.append(text, tools);
            this.listTarget.append(li);
        }
    }

    showEmpty() {
        this.threadTarget.innerHTML = '';
        const empty = this.el('div', 'empty');
        empty.append(
            this.el('div', 'empty-icon', '✍️'),
            this.el('h2', null, 'Que faut-il relire ?'),
            this.el('p', null, 'Collez un post, ou le texte d\'un visuel carte par carte. Vous obtenez un verdict et des remarques numérotées.'),
        );
        this.threadTarget.append(empty);
    }

    userRow(text, editable) {
        const row = this.el('div', 'row row-user');
        const bubble = this.el('div', 'bubble', text);
        const wrap = this.el('div', 'bubble-wrap');
        wrap.append(bubble);
        if (editable) {
            wrap.append(this.button('✎ Modifier', 'ghost small', () => this.startEdit()));
        }
        row.append(wrap, this.el('div', 'avatar', 'Vous'));

        return row;
    }

    renderPending(text, keepTitle = false) {
        this.threadTarget.innerHTML = '';
        const row = this.el('div', 'row row-bot');
        const card = this.el('div', 'answer');
        card.append(this.el('strong', null, 'Correcteur média'), this.el('p', 'typing', 'Relecture en cours…'));
        row.append(this.el('div', 'bot-icon', '✍️'), card);
        this.threadTarget.append(this.userRow(text, false), row);
        if (!keepTitle) this.crumbTarget.textContent = '';
    }

    renderThread() {
        const c = this.current;
        if (!c) return this.showEmpty();

        this.crumbTarget.textContent = '/ ' + c.title;
        this.threadTarget.innerHTML = '';

        const row = this.el('div', 'row row-bot');
        const card = this.el('div', 'answer');
        card.append(this.el('strong', null, 'Correcteur média'));
        const body = this.el('div', 'answer-body');
        body.innerHTML = this.markdown(c.result);
        const foot = this.el('div', 'answer-foot');
        foot.append(this.el('small', null, this.formatDate(c.updatedAt)));
        const copy = this.button('Copier', 'ghost small', () => this.copy(copy));
        foot.append(copy);
        card.append(body, foot);
        row.append(this.el('div', 'bot-icon', '✍️'), card);

        this.threadTarget.append(this.userRow(c.text, true), row);
        this.threadTarget.scrollTop = 0;
    }

    // Rendu minimal et sûr : échappement HTML, puis **gras** et sauts de ligne.
    markdown(source) {
        const escaped = source.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');

        return escaped
            .split(/\n{2,}/)
            .map((block) => (/^-{3,}$/.test(block.trim()) ? '<hr>' : `<p>${block.replace(/\*\*(.+?)\*\*/gs, '<strong>$1</strong>').replace(/\n/g, '<br>')}</p>`))
            .join('');
    }
}
