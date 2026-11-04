import { Controller } from '@hotwired/stimulus';

// Une remarque de la réponse : « **N. 🔴 … .** » (même expression que RemarkParser côté serveur).
const REMARK = /^\*\*\s*(?:Carte\s*\d+\s*[—–-]\s*)?\d+\.\s/u;
const SUGGEST_MIN = 40; // caractères saisis avant de chercher un dossier
const SUGGEST_DELAY = 700;

export default class extends Controller {
    static targets = [
        'dossierList', 'memoryList', 'memoryScope', 'thread', 'input', 'status', 'send', 'crumb',
        'dossier', 'datalist', 'suggestion', 'banner', 'bannerText',
    ];
    static values = { corrections: String, dossiers: String, suggest: String, memory: String, admin: Boolean, token: String };

    items = []; // relectures affichées, de la plus ancienne à la plus récente
    hasMore = false;
    dossiers = [];
    filter = { mode: 'all', name: '' }; // all | none | dossier
    revision = null; // relecture dont le texte saisi est une version révisée
    suggested = null;
    suggestTimer = null;
    suggestSeq = 0;
    busy = false;

    async connect() {
        await Promise.all([this.loadDossiers(), this.loadThread()]);
        this.loadMemory();
    }

    disconnect() {
        clearTimeout(this.suggestTimer);
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

    threadQuery(before = null) {
        const params = new URLSearchParams();
        if ('none' === this.filter.mode) params.set('none', '1');
        if ('dossier' === this.filter.mode) params.set('dossier', this.filter.name);
        if (before) params.set('before', before);
        const query = params.toString();

        return this.correctionsValue + (query ? '?' + query : '');
    }

    async loadThread() {
        try {
            const page = await this.request(this.threadQuery());
            this.items = page.items;
            this.hasMore = page.hasMore;
        } catch (e) {
            this.items = [];
            this.hasMore = false;
            this.setStatus(e.message);
        }
        this.renderThread({ bottom: true });
        this.renderCrumb();
    }

    async loadMore() {
        if (!this.items.length) return;
        try {
            const page = await this.request(this.threadQuery(this.items[0].id));
            const before = this.threadTarget.scrollHeight;
            this.items = [...page.items, ...this.items];
            this.hasMore = page.hasMore;
            this.renderThread();
            this.threadTarget.scrollTop += this.threadTarget.scrollHeight - before; // reste sur ce qu'on lisait
        } catch (e) {
            this.setStatus(e.message);
        }
    }

    async loadDossiers() {
        try {
            this.dossiers = await this.request(this.dossiersValue);
        } catch {
            this.dossiers = [];
        }
        this.renderDossiers();
    }

    async loadMemory() {
        try {
            const dossier = this.currentDossier();
            this.memoryScopeTarget.textContent = dossier ? '· ' + dossier : '';
            this.renderMemory(await this.request(this.memoryValue + '?dossier=' + encodeURIComponent(dossier)));
        } catch {
            this.renderMemory([]);
        }
    }

    // Le dossier auquel s'applique la mémoire affichée : celui de la zone de saisie, sinon celui qu'on consulte.
    currentDossier() {
        return this.dossierTarget.value.trim() || ('dossier' === this.filter.mode ? this.filter.name : '');
    }

    // ---------- Actions ----------

    keydown(event) {
        if ('Enter' === event.key && (event.ctrlKey || event.metaKey)) {
            event.preventDefault();
            this.submit(event);
        }
    }

    selectAll() {
        this.setFilter({ mode: 'all', name: '' });
    }

    selectNone() {
        this.setFilter({ mode: 'none', name: '' });
    }

    selectDossier(name) {
        this.setFilter({ mode: 'dossier', name });
    }

    async setFilter(filter) {
        this.filter = filter;
        if ('dossier' === filter.mode && !this.revision) this.dossierTarget.value = filter.name;
        if ('none' === filter.mode && !this.revision) this.dossierTarget.value = '';
        this.renderDossiers();
        await this.loadThread();
        this.loadMemory();
    }

    async submit(event) {
        event.preventDefault();
        if (this.busy) return;
        const text = this.inputTarget.value.trim();
        if (!text) {
            this.setStatus('Collez un texte à relire.');
            return;
        }

        const body = { text, dossier: this.dossierTarget.value.trim() };
        if (this.revision) body.revisionOf = this.revision.id;

        this.renderPending(text);
        this.setBusy(true);
        let error = null;
        try {
            const item = await this.request(this.correctionsValue, 'POST', body);
            this.inputTarget.value = '';
            this.cancelRevision();
            this.hideSuggestion();
            // Une relecture qui n'entre pas dans le filtre affiché : on passe au filtre qui la contient.
            if ('dossier' === this.filter.mode && item.dossier !== this.filter.name) {
                this.filter = item.dossier ? { mode: 'dossier', name: item.dossier } : { mode: 'all', name: '' };
            } else if ('none' === this.filter.mode && item.dossier) {
                this.filter = { mode: 'dossier', name: item.dossier };
            }
            await Promise.all([this.loadThread(), this.loadDossiers()]);
            this.loadMemory();
        } catch (e) {
            error = e.message;
            this.renderThread({ bottom: true });
        } finally {
            this.setBusy(false);
        }
        // Après setBusy, qui remet le message d'aide : sinon l'erreur disparaîtrait aussitôt.
        if (error) this.setStatus(error);
    }

    revise(id) {
        const item = this.items.find((i) => i.id === id);
        if (!item || this.busy) return;
        this.revision = item;
        this.inputTarget.value = item.text;
        this.dossierTarget.value = item.dossier || '';
        this.bannerTextTarget.textContent = '↻ Version révisée de « ' + item.title + ' »';
        this.bannerTarget.hidden = false;
        this.hideSuggestion();
        this.inputTarget.focus();
        this.loadMemory();
    }

    cancelRevision() {
        this.revision = null;
        this.bannerTarget.hidden = true;
    }

    async remove(id) {
        if (!window.confirm('Supprimer cette relecture ? La mémoire qui en vient est conservée.')) return;
        try {
            await this.request(`${this.correctionsValue}/${id}`, 'DELETE');
            if (this.revision?.id === id) this.cancelRevision();
            this.items = this.items.filter((i) => i.id !== id);
            this.renderThread();
            await this.loadDossiers();
            this.loadMemory();
        } catch (e) {
            this.setStatus(e.message);
        }
    }

    async moveToDossier(id) {
        const item = this.items.find((i) => i.id === id);
        const name = window.prompt('Dossier (laisser vide pour aucun) :', item?.dossier ?? '');
        if (null === name) return;
        try {
            await this.request(`${this.correctionsValue}/${id}`, 'PUT', { dossier: name });
            await Promise.all([this.loadThread(), this.loadDossiers()]);
            this.loadMemory();
        } catch (e) {
            this.setStatus(e.message);
        }
    }

    async memorize(item, n, action) {
        try {
            const entry = await this.request(`${this.correctionsValue}/${item.id}/remarks/${n}/memory`, 'POST', { action });
            item.memo = { ...item.memo, [n]: { id: entry.id, action } };
            this.rerender();
            this.loadDossiers();
            this.loadMemory();
        } catch (e) {
            this.setStatus(e.message);
        }
    }

    async unmemorize(item, n) {
        const memo = item.memo[n];
        if (!memo) return;
        try {
            await this.request(`${this.memoryValue}/${memo.id}`, 'DELETE');
            const rest = { ...item.memo };
            delete rest[n];
            item.memo = rest;
            this.rerender();
            this.loadMemory();
        } catch (e) {
            this.setStatus(e.message);
        }
    }

    async forget(entry) {
        try {
            await this.request(`${this.memoryValue}/${entry.id}`, 'DELETE');
            // Les boutons « Retenu » des remarques d'où venait l'entrée redeviennent actifs.
            for (const item of this.items) {
                item.memo = Object.fromEntries(Object.entries(item.memo).filter(([, m]) => m.id !== entry.id));
            }
            this.rerender();
            this.loadMemory();
        } catch (e) {
            this.setStatus(e.message);
        }
    }

    async share(entry) {
        if (!window.confirm('Partager cette règle avec toute l\'équipe ? Elle vaudra pour tous les dossiers de tous les utilisateurs.')) return;
        try {
            await this.request(`${this.memoryValue}/${entry.id}/share`, 'POST');
            this.loadMemory();
        } catch (e) {
            this.setStatus(e.message);
        }
    }

    async copy(item, button) {
        try {
            await navigator.clipboard.writeText(item.result);
            button.textContent = 'Copié ✓';
            setTimeout(() => (button.textContent = 'Copier'), 1500);
        } catch {
            /* presse-papiers indisponible */
        }
    }

    // ---------- Dossier suggéré ----------

    textChanged() {
        clearTimeout(this.suggestTimer);
        if (this.dossierTarget.value.trim() || this.inputTarget.value.trim().length < SUGGEST_MIN) {
            this.hideSuggestion();
            return;
        }
        this.suggestTimer = setTimeout(() => this.suggestDossier(), SUGGEST_DELAY);
    }

    dossierChanged() {
        if (this.dossierTarget.value.trim()) this.hideSuggestion();
        else this.textChanged();
        clearTimeout(this.memoryTimer);
        this.memoryTimer = setTimeout(() => this.loadMemory(), 300);
    }

    async suggestDossier() {
        const seq = ++this.suggestSeq;
        try {
            const { dossier } = await this.request(this.suggestValue, 'POST', { text: this.inputTarget.value });
            if (seq !== this.suggestSeq || this.dossierTarget.value.trim()) return;
            if (dossier) {
                this.suggested = dossier;
                this.suggestionTarget.textContent = 'Dossier suggéré : ' + dossier + ' — utiliser';
                this.suggestionTarget.hidden = false;
            } else {
                this.hideSuggestion();
            }
        } catch {
            this.hideSuggestion();
        }
    }

    useSuggestion() {
        if (!this.suggested) return;
        this.dossierTarget.value = this.suggested;
        this.hideSuggestion();
        this.loadMemory();
    }

    hideSuggestion() {
        this.suggestSeq++;
        this.suggested = null;
        this.suggestionTarget.hidden = true;
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

    renderCrumb() {
        const label = { all: '', none: '/ Sans dossier', dossier: '/ ' + this.filter.name }[this.filter.mode];
        this.crumbTarget.textContent = label;
    }

    renderDossiers() {
        const list = this.dossierListTarget;
        list.innerHTML = '';
        this.datalistTarget.innerHTML = '';

        const entry = (label, count, active, onClick) => {
            const li = this.el('li', 'history-item' + (active ? ' active' : ''));
            li.addEventListener('click', onClick);
            const text = this.el('div', 'history-text');
            text.append(this.el('span', 'history-title', label));
            li.append(text, this.el('small', 'history-count', null === count ? '' : String(count)));
            list.append(li);
        };

        entry('Tous les posts', null, 'all' === this.filter.mode, () => this.selectAll());
        for (const d of this.dossiers) {
            entry(d.name, d.count, 'dossier' === this.filter.mode && this.filter.name.toLowerCase() === d.name.toLowerCase(), () => this.selectDossier(d.name));
            const option = document.createElement('option');
            option.value = d.name;
            this.datalistTarget.append(option);
        }
        entry('Sans dossier', null, 'none' === this.filter.mode, () => this.selectNone());
    }

    renderMemory(entries) {
        const list = this.memoryListTarget;
        list.innerHTML = '';
        if (!entries.length) {
            list.append(this.el('li', 'history-empty', 'Rien de retenu. Utilisez « Retenir » ou « Ignorer » sur une remarque.'));
            return;
        }
        for (const entry of entries) {
            const li = this.el('li', 'memory-item' + (entry.shared ? ' shared' : '') + ('exclusion' === entry.kind ? ' exclusion' : ''));
            const text = this.el('span', 'memory-text', entry.content);
            if (entry.shared) text.prepend(this.el('em', 'memory-tag', 'Équipe'));
            li.append(text);

            const tools = this.el('div', 'memory-tools');
            if (this.adminValue && !entry.shared) {
                const share = this.button('⇪', null, () => this.share(entry));
                share.title = 'Partager avec l\'équipe';
                tools.append(share);
            }
            if (entry.canManage) {
                const del = this.button('✕', null, () => this.forget(entry));
                del.title = 'Retirer de la mémoire';
                tools.append(del);
            }
            li.append(tools);
            list.append(li);
        }
    }

    showEmpty() {
        this.threadTarget.innerHTML = '';
        const empty = this.el('div', 'empty');
        empty.append(
            this.el('div', 'empty-icon', '✎'),
            this.el('h2', null, 'Que faut-il relire ?'),
            this.el('p', null, 'Collez un post, ou le texte d\'un visuel carte par carte. Vos relectures s\'enchaînent ici, et le correcteur se souvient de ce que vous retenez.'),
        );
        this.threadTarget.append(empty);
    }

    // Redessine le fil sans bouger la lecture.
    rerender() {
        const top = this.threadTarget.scrollTop;
        this.renderThread();
        this.threadTarget.scrollTop = top;
    }

    renderThread({ bottom = false } = {}) {
        this.threadTarget.innerHTML = '';
        if (!this.items.length) {
            this.showEmpty();
            return;
        }
        if (this.hasMore) {
            this.threadTarget.append(this.button('Voir les relectures précédentes', 'more-btn', () => this.loadMore()));
        }
        for (const item of this.items) {
            this.threadTarget.append(this.userRow(item), this.botRow(item));
        }
        if (bottom) this.threadTarget.scrollTop = this.threadTarget.scrollHeight;
    }

    renderPending(text) {
        const row = this.el('div', 'row row-bot');
        const card = this.el('div', 'answer');
        card.append(this.el('strong', null, 'Correcteur média'), this.el('p', 'typing', 'Relecture en cours…'));
        row.append(this.el('div', 'bot-icon', 'IA'), card);

        const user = this.el('div', 'row row-user');
        const wrap = this.el('div', 'bubble-wrap');
        wrap.append(this.el('div', 'bubble', text));
        user.append(wrap, this.el('div', 'avatar', 'Vous'));

        if (!this.items.length) this.threadTarget.innerHTML = '';
        this.threadTarget.append(user, row);
        this.threadTarget.scrollTop = this.threadTarget.scrollHeight;
    }

    userRow(item) {
        const row = this.el('div', 'row row-user');
        const wrap = this.el('div', 'bubble-wrap');
        wrap.append(this.el('div', 'bubble', item.text));

        const meta = this.el('div', 'row-meta');
        const chip = this.button(item.dossier || 'Sans dossier', 'dossier-chip' + (item.dossier ? '' : ' none'), () => this.moveToDossier(item.id));
        chip.title = 'Changer de dossier';
        meta.append(chip, this.el('small', null, this.formatDate(item.createdAt)));
        meta.append(this.button('↻ Version révisée', 'ghost small', () => this.revise(item.id)));
        const del = this.button('✕', 'ghost small', () => this.remove(item.id));
        del.title = 'Supprimer';
        meta.append(del);
        wrap.append(meta);

        row.append(wrap, this.el('div', 'avatar', 'Vous'));

        return row;
    }

    botRow(item) {
        const row = this.el('div', 'row row-bot');
        const card = this.el('div', 'answer');
        card.append(this.el('strong', null, 'Correcteur média'));

        const body = this.el('div', 'answer-body');
        let n = 0;
        for (const block of item.result.split(/\n{2,}/)) {
            const node = this.el('div', 'answer-block');
            if (/^-{3,}$/.test(block.trim())) {
                node.append(document.createElement('hr'));
            } else {
                const p = this.el('p');
                p.innerHTML = this.inline(block);
                node.append(p);
                if (REMARK.test(block.trim())) node.append(this.remarkActions(item, ++n));
            }
            body.append(node);
        }

        const foot = this.el('div', 'answer-foot');
        foot.append(this.el('small', null, this.formatDate(item.updatedAt)));
        const copy = this.button('Copier', 'ghost small', () => this.copy(item, copy));
        foot.append(copy);

        card.append(body, foot);
        row.append(this.el('div', 'bot-icon', 'IA'), card);

        return row;
    }

    // Retenir ou ignorer une remarque ; une fois fait, l'état s'affiche et peut être annulé.
    remarkActions(item, n) {
        const bar = this.el('div', 'memo-actions');
        const memo = item.memo[n];
        if (memo) {
            bar.append(this.el('span', 'memo-state', 'retain' === memo.action ? 'Retenu ✓' : 'Ne sera plus signalé ✓'));
            bar.append(this.button('Annuler', 'ghost small', () => this.unmemorize(item, n)));

            return bar;
        }
        const retain = this.button('Retenir', 'ghost small', () => this.memorize(item, n, 'retain'));
        retain.title = 'Appliquer ce choix aux prochains textes du dossier';
        const ignore = this.button('Ignorer', 'ghost small', () => this.memorize(item, n, 'ignore'));
        ignore.title = 'Ne plus signaler ce point';
        bar.append(retain, ignore);

        return bar;
    }

    // Rendu minimal et sûr : échappement HTML, puis **gras** et sauts de ligne.
    inline(source) {
        const escaped = source.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');

        return escaped.replace(/\*\*(.+?)\*\*/gs, '<strong>$1</strong>').replace(/\n/g, '<br>');
    }
}
