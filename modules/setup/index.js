// Astucia Wiki — Copyright (C) 2026 Mads Rotwitt
// Free software under the GNU GPL v3 or later. See LICENSE for the full notice,
// or <https://www.gnu.org/licenses/>. Distributed WITHOUT ANY WARRANTY.
/**
 * The setup checklist — administrators only, behind its own sidebar icon (#setup-btn), and
 * opened by itself on the first page of a browser session while "Show at startup" is on and
 * something is left to do (setup.php decides; index.php says so in WIKI_SETUP_AUTOSHOW).
 *
 * One lightbox, three views:
 *   list     every item with a green check or what configuring it brings
 *   detail   one item: what it is, a "Set it up" button into the right place, and Read more
 *   llm      the LLM provider wizard: pick a service → API key → Test and save → done
 *
 * Nothing is recorded as "done" here: every opening asks the server, which works each item
 * out from the configuration (admin_setup_status). The wizard saves through the same two
 * actions as Admin → LLM Providers (admin_test_llm_connection, admin_save_llm_connection) —
 * tested first, so a provider that cannot answer is never saved.
 */
import { api } from '../core/api.js';
import { t } from '../i18n/index.js';
import { showToast, confirmModal } from '../core/utils.js';

const DOCS = 'https://astucia.wiki/docs/';
const DOCS_FOR = {
    llm: 'features/ai/#providers', ai: 'features/ai/', email: 'install/#email-setup',
    taxonomy: 'features/taxonomy/', workflows: 'features/workflows/',
    cron: 'install/#agent-jobs', auth: 'features/auth/',
};
// Checks rather than steps: undone is a warning, not a gap.
const CHECKS = ['cron', 'auth'];
const SHOWN_KEY = 'setup_autoshown';

let _overlay = null;
let _status = null;          // the last admin_setup_status answer

const el = (tag, cls, text) => {
    const n = document.createElement(tag);
    if (cls) n.className = cls;
    if (text !== undefined) n.textContent = text;
    return n;
};
const ICON_DONE = '<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="m8 12 3 3 5-6"/></svg>';
const ICON_TODO = '<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="12" cy="12" r="10" stroke-dasharray="3 3"/></svg>';
const ICON_WARN = '<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0z"/><path d="M12 9v4M12 17h.01"/></svg>';
const ICON_NEXT = '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m9 18 6-6-6-6"/></svg>';

const item = (id) => _status?.items.find(i => i.id === id) || { id, done: false };
const stateIcon = (it) => it.done ? ICON_DONE : (CHECKS.includes(it.id) ? ICON_WARN : ICON_TODO);
const stateClass = (it) => it.done ? 'is-done' : (CHECKS.includes(it.id) ? 'is-warn' : 'is-todo');

/** One line under an item's title: what it amounts to when done, what it brings when not. */
const summary = (it) => {
    if (it.done) {
        if (it.id === 'email') return t(it.source === 'config' ? 'setup.email.done-config' : 'setup.email.done-wiki', { provider: it.provider || '' });
        if (it.id === 'cron' || it.id === 'auth') return t(`setup.${it.id}.done`);
        return t(`setup.${it.id}.done`, { n: it.count ?? 0 });
    }
    if (it.id === 'cron') return t(it.state === 'never' ? 'setup.cron.never' : 'setup.cron.stalled');
    if (it.id === 'ai' && it.total > 0) return t('setup.ai.partial', { n: it.total });
    return t(`setup.${it.id}.benefit`);
};

const close = () => _overlay?.classList.add('hidden');

const openAdmin = (tab, action) => {
    close();
    window.dispatchEvent(new CustomEvent('wiki:open-admin', { detail: { tab, action } }));
};

// ── The views ─────────────────────────────────────────────────────────────────

const body = () => _overlay.querySelector('.setup-body');
const setFooter = (back) => {
    _overlay.querySelector('[data-act="back"]').classList.toggle('hidden', !back);
    _overlay.querySelector('.setup-startup').classList.toggle('hidden', !!back);
};

const showList = () => {
    const b = body();
    b.innerHTML = '';
    setFooter(false);
    b.appendChild(el('p', 'setup-intro', t(_status.complete ? 'setup.all-done' : 'setup.intro')));
    const list = el('div', 'setup-list');
    for (const it of _status.items) {
        const row = el('button', `setup-row ${stateClass(it)}`);
        row.type = 'button';
        row.dataset.item = it.id;
        const icon = el('span', 'setup-state');
        icon.innerHTML = stateIcon(it);
        const text = el('span', 'setup-row-text');
        text.append(el('span', 'setup-row-title', t(`setup.${it.id}.title`)), el('span', 'setup-row-summary', summary(it)));
        const next = el('span', 'setup-row-next');
        next.innerHTML = ICON_NEXT;
        row.append(icon, text, next);
        row.addEventListener('click', () => showDetail(it.id));
        list.appendChild(row);
    }
    b.appendChild(list);
};

/** The "Set it up" button for an item: [label, handler], or null where the wiki cannot. */
const action = (it) => {
    switch (it.id) {
        case 'llm':       return [t(it.done ? 'setup.llm.add-another' : 'setup.go'), () => showLlmPick()];
        case 'ai':        return [t(it.total > 0 ? 'setup.open-admin' : 'setup.go'), () => openAdmin('ai', it.total > 0 ? null : 'new')];
        case 'taxonomy':  return [t(it.done ? 'setup.open-admin' : 'setup.go'), () => openAdmin('taxonomy')];
        case 'workflows': return [t(it.total > 0 ? 'setup.open-admin' : 'setup.go'), () => openAdmin('workflows', it.total > 0 ? null : 'new')];
        // Set in config.php: changed there, so a test email is what the wiki can offer.
        case 'email':     return it.source === 'config' ? [t('setup.email.test'), () => openAdmin('diagnostics')]
                                                        : [t(it.done ? 'setup.email.change' : 'setup.go'), () => showMailPick()];
        default:          return null;
    }
};

const showDetail = (id) => {
    const it = item(id);
    const b = body();
    b.innerHTML = '';
    setFooter(true);
    const head = el('div', `setup-detail-head ${stateClass(it)}`);
    const icon = el('span', 'setup-state');
    icon.innerHTML = stateIcon(it);
    head.append(icon, el('h4', 'setup-detail-title', t(`setup.${id}.title`)));
    b.appendChild(head);
    b.appendChild(el('p', 'setup-detail-status', summary(it)));
    b.appendChild(el('p', 'setup-detail-body', t(`setup.${id}.body`)));
    if (id === 'cron') {
        // The crontab line for this install, as it is — the one thing to copy.
        const pre = el('pre', 'setup-code', it.line || '');
        const copy = el('button', 'btn btn-sm btn-secondary setup-copy', t('setup.copy'));
        copy.type = 'button';
        copy.addEventListener('click', async () => {
            try { await navigator.clipboard.writeText(it.line || ''); showToast(t('setup.copied'), 'success'); }
            catch { showToast(t('setup.copy-failed'), 'error'); }
        });
        const wrap = el('div', 'setup-code-wrap');
        wrap.append(pre, copy);
        b.appendChild(wrap);
    }
    const bar = el('div', 'setup-actions');
    const act = action(it);
    if (act) {
        const go = el('button', 'btn btn-blue', act[0]);
        go.type = 'button';
        go.dataset.act = 'go';
        go.addEventListener('click', act[1]);
        bar.appendChild(go);
    }
    if (id === 'email' && it.source === 'wiki') {
        const test = el('button', 'btn btn-secondary', t('setup.email.test'));
        test.type = 'button';
        test.addEventListener('click', () => openAdmin('diagnostics'));
        const remove = el('button', 'btn btn-secondary', t('setup.email.remove'));
        remove.type = 'button';
        remove.dataset.act = 'mail-remove';
        remove.addEventListener('click', async () => {
            if (!await confirmModal(t('setup.email.remove-confirm'), { confirmLabel: t('setup.email.remove'), dangerous: true })) return;
            const res = await api.call('admin_mail_remove', {}, 'POST');
            if (!res.success) { showToast(res.message || t('setup.load-failed'), 'error'); return; }
            await refresh();
            showDetail('email');
        });
        bar.append(test, remove);
    }
    const more = el('a', 'btn btn-secondary setup-more', t('setup.read-more'));
    more.href = DOCS + DOCS_FOR[id];
    more.target = '_blank';
    more.rel = 'noopener';
    bar.appendChild(more);
    b.appendChild(bar);
};

// ── The LLM provider wizard ───────────────────────────────────────────────────

const showLlmPick = () => {
    const b = body();
    b.innerHTML = '';
    setFooter(true);
    b.appendChild(el('h4', 'setup-detail-title', t('setup.llm.pick-title')));
    b.appendChild(el('p', 'setup-detail-body', t('setup.llm.pick-intro')));
    const grid = el('div', 'setup-cards');
    for (const p of _status.presets || []) {
        const card = el('button', 'setup-card');
        card.type = 'button';
        card.dataset.preset = p.id;
        card.append(el('span', 'setup-card-name', p.label),
                    el('span', 'setup-card-note', p.no_key ? t('setup.llm.local') : p.model));
        card.addEventListener('click', () => showLlmForm(p));
        grid.appendChild(card);
    }
    const custom = el('button', 'setup-card setup-card-custom');
    custom.type = 'button';
    custom.dataset.preset = 'custom';
    custom.append(el('span', 'setup-card-name', t('setup.llm.custom')), el('span', 'setup-card-note', t('setup.llm.custom-note')));
    custom.addEventListener('click', () => openAdmin('llm', 'new'));
    grid.appendChild(custom);
    b.appendChild(grid);
};

const field = (label, input, hint) => {
    const g = el('div', 'form-group setup-field');
    const l = el('label', '', label);
    if (input.id) l.htmlFor = input.id;
    g.append(l, input);
    if (hint) g.appendChild(hint);
    return g;
};

const showLlmForm = (p) => {
    const b = body();
    b.innerHTML = '';
    setFooter(true);
    b.appendChild(el('h4', 'setup-detail-title', t('setup.llm.form-title', { name: p.label })));

    const name = Object.assign(el('input', 'form-control'), { id: 'setup-llm-name', value: p.label });
    const key = Object.assign(el('input', 'form-control'), { id: 'setup-llm-key', type: 'password', autocomplete: 'off',
        placeholder: p.no_key ? t('setup.llm.key-none') : '' });
    const model = Object.assign(el('input', 'form-control'), { id: 'setup-llm-model', value: p.model, spellcheck: false });
    const url = Object.assign(el('input', 'form-control'), { id: 'setup-llm-url', value: p.url, spellcheck: false });

    b.appendChild(field(t('setup.llm.name'), name));
    if (!p.no_key) {
        const get = el('a', 'setup-key-link', t('setup.llm.get-key', { name: p.label }));
        get.href = p.key_url;
        get.target = '_blank';
        get.rel = 'noopener';
        const hint = el('p', 'form-hint');
        hint.appendChild(get);
        b.appendChild(field(t('setup.llm.key'), key, hint));
    }
    b.appendChild(field(t('setup.llm.model'), model, el('p', 'form-hint', t('setup.llm.model-hint'))));
    // The address is right for nearly everyone; Ollama on another machine is the exception,
    // so it is shown there and folded away elsewhere.
    const urlField = field(t('setup.llm.url'), url, el('p', 'form-hint', t(p.no_key ? 'setup.llm.url-hint-local' : 'setup.llm.url-hint')));
    if (p.no_key) b.appendChild(urlField);
    else {
        const adv = el('details', 'setup-advanced');
        adv.appendChild(el('summary', '', t('setup.llm.advanced')));
        adv.appendChild(urlField);
        b.appendChild(adv);
    }

    const result = el('div', 'setup-result');
    b.appendChild(result);
    const bar = el('div', 'setup-actions');
    const save = el('button', 'btn btn-blue', t('setup.llm.save'));
    save.type = 'button';
    save.dataset.act = 'llm-save';
    bar.appendChild(save);
    b.appendChild(bar);

    save.addEventListener('click', async () => {
        // A local server takes no key, but the connection stores one: any word will do.
        const apiKey = p.no_key ? (key.value.trim() || 'ollama') : key.value.trim();
        if (!apiKey) { result.className = 'setup-result is-error'; result.textContent = t('setup.llm.key-required'); key.focus(); return; }
        if (!name.value.trim() || !model.value.trim()) { result.className = 'setup-result is-error'; result.textContent = t('setup.llm.fields-required'); return; }
        save.disabled = true;
        result.className = 'setup-result';
        result.textContent = t('setup.llm.testing');
        const fields = { provider: p.provider, api_url: url.value.trim(), api_key: apiKey, extra_headers: '[]' };
        const test = await api.call('admin_test_llm_connection', { ...fields, model: model.value.trim() }, 'POST');
        if (!test.success) {
            save.disabled = false;
            result.className = 'setup-result is-error';
            result.textContent = t('setup.llm.test-failed', { error: test.message || '?' });
            return;
        }
        const saved = await api.call('admin_save_llm_connection', { ...fields, name: name.value.trim() }, 'POST');
        save.disabled = false;
        if (!saved.success) {
            result.className = 'setup-result is-error';
            result.textContent = saved.message || t('setup.llm.save-failed');
            return;
        }
        window.dispatchEvent(new CustomEvent('wiki:llm-connections-changed'));
        await refresh();
        showLlmDone(name.value.trim(), test.reply || '');
    });
    (p.no_key ? model : key).focus();
};

const showLlmDone = (name, reply) => {
    const b = body();
    b.innerHTML = '';
    setFooter(true);
    const head = el('div', 'setup-detail-head is-done');
    const icon = el('span', 'setup-state');
    icon.innerHTML = ICON_DONE;
    head.append(icon, el('h4', 'setup-detail-title', t('setup.llm.done-title', { name })));
    b.appendChild(head);
    if (reply) b.appendChild(el('p', 'setup-reply', reply));
    b.appendChild(el('p', 'setup-detail-body', t('setup.llm.done-body')));
    const bar = el('div', 'setup-actions');
    const next = el('button', 'btn btn-blue', t('setup.llm.next-ai'));
    next.type = 'button';
    next.dataset.act = 'next-ai';
    next.addEventListener('click', () => openAdmin('ai', 'new'));
    const list = el('button', 'btn btn-secondary', t('setup.back-list'));
    list.type = 'button';
    list.addEventListener('click', showList);
    bar.append(next, list);
    b.appendChild(bar);
};

// ── The email wizard ──────────────────────────────────────────────────────────
//
// Saved only after a test email was accepted, like the LLM wizard: what is saved can send.
// The settings go to WIKI_SYSTEM_DATA/mail_settings.json (mailer.php); config.php, when it
// configures a provider, takes precedence and the wizard is not offered.

const MAIL_PROVIDERS = [
    { id: 'sendgrid', label: 'SendGrid', key_url: 'https://app.sendgrid.com/settings/api_keys', regions: ['global', 'eu'] },
    { id: 'mailgun',  label: 'Mailgun',  key_url: 'https://app.mailgun.com/settings/api_security', regions: ['us', 'eu'] },
    { id: 'postmark', label: 'Postmark', key_url: 'https://account.postmarkapp.com/servers', regions: [] },
    { id: 'brevo',    label: 'Brevo',    key_url: 'https://app.brevo.com/settings/keys/api', regions: [] },
    { id: 'resend',   label: 'Resend',   key_url: 'https://resend.com/api-keys', regions: [] },
];
let _mail = null;            // the last admin_mail_settings answer

const showMailPick = async () => {
    const b = body();
    setFooter(true);
    const res = await api.call('admin_mail_settings');
    if (!res.success) { showToast(res.message || t('setup.load-failed'), 'error'); return; }
    _mail = res;
    b.innerHTML = '';
    b.appendChild(el('h4', 'setup-detail-title', t('setup.email.pick-title')));
    b.appendChild(el('p', 'setup-detail-body', t('setup.email.pick-intro')));
    if (res.config_partial) b.appendChild(el('p', 'setup-note', t('setup.email.config-partial')));
    const grid = el('div', 'setup-cards');
    for (const p of MAIL_PROVIDERS) {
        const card = el('button', 'setup-card');
        card.type = 'button';
        card.dataset.mail = p.id;
        card.append(el('span', 'setup-card-name', p.label), el('span', 'setup-card-note', t(`setup.email.${p.id}-note`)));
        card.addEventListener('click', () => showMailForm(p));
        grid.appendChild(card);
    }
    b.appendChild(grid);
};

const showMailForm = (p) => {
    const b = body();
    b.innerHTML = '';
    setFooter(true);
    // What is stored already, when it is this provider: its fields filled in, its key kept.
    const prev = _mail?.stored?.provider === p.id ? _mail.stored : null;
    b.appendChild(el('h4', 'setup-detail-title', t('setup.email.form-title', { name: p.label })));

    const key = Object.assign(el('input', 'form-control'), { id: 'setup-mail-key', type: 'password', autocomplete: 'off',
        placeholder: prev?.key_set ? t('setup.email.key-kept', { hint: prev.key_hint }) : '' });
    const from = Object.assign(el('input', 'form-control'), { id: 'setup-mail-from', type: 'email', value: prev?.from_email || '' });
    const name = Object.assign(el('input', 'form-control'), { id: 'setup-mail-name', value: prev?.from_name || _mail?.app_title || '' });
    const domain = Object.assign(el('input', 'form-control'), { id: 'setup-mail-domain', value: prev?.domain || '', spellcheck: false,
        placeholder: 'mail.example.com' });
    const region = Object.assign(el('select', 'form-control'), { id: 'setup-mail-region' });
    for (const r of p.regions) region.appendChild(Object.assign(el('option', '', t(`setup.email.region-${r}`)), { value: r }));
    region.value = prev?.region || p.regions[0] || '';
    const to = Object.assign(el('input', 'form-control'), { id: 'setup-mail-to', type: 'email', value: _mail?.test_to || '' });

    const getKey = el('a', 'setup-key-link', t('setup.llm.get-key', { name: p.label }));
    getKey.href = p.key_url;
    getKey.target = '_blank';
    getKey.rel = 'noopener';
    const keyHint = el('p', 'form-hint');
    keyHint.appendChild(getKey);
    b.appendChild(field(t('setup.llm.key'), key, keyHint));
    if (p.id === 'mailgun') b.appendChild(field(t('setup.email.domain'), domain, el('p', 'form-hint', t('setup.email.domain-hint'))));
    b.appendChild(field(t('setup.email.from'), from, el('p', 'form-hint', t(`setup.email.from-hint-${p.id}`))));
    b.appendChild(field(t('setup.email.from-name'), name));
    // Only SendGrid and Mailgun have regions; the others have one endpoint.
    if (p.regions.length) b.appendChild(field(t('setup.email.region'), region, el('p', 'form-hint', t(`setup.email.region-hint-${p.id}`))));
    b.appendChild(field(t('setup.email.to'), to, el('p', 'form-hint', t('setup.email.to-hint'))));

    const result = el('div', 'setup-result');
    b.appendChild(result);
    const bar = el('div', 'setup-actions');
    const save = el('button', 'btn btn-blue', t('setup.email.save'));
    save.type = 'button';
    save.dataset.act = 'mail-save';
    bar.appendChild(save);
    b.appendChild(bar);

    const fail = (msg, focus) => { result.className = 'setup-result is-error'; result.textContent = msg; focus?.focus(); };
    save.addEventListener('click', async () => {
        if (!key.value.trim() && !prev?.key_set) return fail(t('setup.llm.key-required'), key);
        if (p.id === 'mailgun' && !domain.value.trim()) return fail(t('setup.email.domain-required'), domain);
        if (!from.checkValidity() || !from.value.trim()) return fail(t('setup.email.from-required'), from);
        if (!to.checkValidity() || !to.value.trim()) return fail(t('setup.email.to-required'), to);
        const fields = { provider: p.id, api_key: key.value.trim(), from_email: from.value.trim(),
                         from_name: name.value.trim(), domain: domain.value.trim(), region: region.value };
        save.disabled = true;
        result.className = 'setup-result';
        result.textContent = t('setup.email.sending', { to: to.value.trim() });
        const test = await api.call('admin_mail_test', { ...fields, to: to.value.trim() }, 'POST');
        if (!test.success) { save.disabled = false; return fail(t('setup.email.test-failed', { error: test.message || '?' })); }
        const saved = await api.call('admin_mail_save', fields, 'POST');
        save.disabled = false;
        if (!saved.success) return fail(saved.message || t('setup.llm.save-failed'));
        await refresh();
        showMailDone(p.label, to.value.trim());
    });
    key.focus();
};

const showMailDone = (provider, to) => {
    const b = body();
    b.innerHTML = '';
    setFooter(true);
    const head = el('div', 'setup-detail-head is-done');
    const icon = el('span', 'setup-state');
    icon.innerHTML = ICON_DONE;
    head.append(icon, el('h4', 'setup-detail-title', t('setup.email.done-title', { provider })));
    b.appendChild(head);
    b.appendChild(el('p', 'setup-detail-body', t('setup.email.done-body', { to })));
    const bar = el('div', 'setup-actions');
    const list = el('button', 'btn btn-blue', t('setup.back-list'));
    list.type = 'button';
    list.dataset.act = 'to-list';
    list.addEventListener('click', showList);
    bar.appendChild(list);
    b.appendChild(bar);
};

// ── The shell ─────────────────────────────────────────────────────────────────

const refresh = async () => {
    const res = await api.call('admin_setup_status');
    if (!res.success) { showToast(res.message || t('setup.load-failed'), 'error'); return false; }
    _status = res;
    _overlay.querySelector('#setup-startup').checked = !!res.startup;
    return true;
};

const build = () => {
    _overlay = el('div', 'lightbox-overlay hidden');
    _overlay.id = 'setup-modal';
    _overlay.innerHTML = `
        <div class="lightbox-content setup-content" role="dialog" aria-modal="true" aria-labelledby="setup-title">
            <button class="lightbox-close" data-act="close">&times;</button>
            <h3 id="setup-title"></h3>
            <div class="setup-body"></div>
            <div class="lightbox-footer setup-footer">
                <button class="btn btn-secondary hidden" data-act="back"></button>
                <label class="setup-startup"><input type="checkbox" id="setup-startup"> <span></span></label>
                <button class="btn btn-blue" data-act="close"></button>
            </div>
        </div>`;
    document.body.appendChild(_overlay);
    _overlay.querySelector('#setup-title').textContent = t('setup.title');
    _overlay.querySelector('[data-act="back"]').textContent = t('btn.back');
    _overlay.querySelector('.setup-startup span').textContent = t('setup.startup');
    _overlay.querySelector('.lightbox-footer [data-act="close"]').textContent = t('btn.close');
    _overlay.querySelectorAll('[data-act="close"]').forEach(b => b.addEventListener('click', close));
    _overlay.querySelector('[data-act="back"]').addEventListener('click', showList);
    _overlay.addEventListener('click', (e) => { if (e.target === _overlay) close(); });
    _overlay.addEventListener('keydown', (e) => { if (e.key === 'Escape') close(); });
    _overlay.querySelector('#setup-startup').addEventListener('change', async (e) => {
        const on = e.target.checked;
        const res = await api.call('admin_setup_startup', { on: on ? '1' : '0' }, 'POST');
        if (!res.success) { e.target.checked = !on; showToast(res.message || t('setup.load-failed'), 'error'); }
    });
};

/** Open the checklist — on the list, or straight into the provider wizard. */
export const openSetup = async (view = 'list') => {
    if (!_overlay) build();
    _overlay.classList.remove('hidden');
    body().innerHTML = `<p class="setup-intro">${t('setup.loading')}</p>`;
    if (!await refresh()) { close(); return; }
    if (view === 'llm') showLlmPick(); else showList();
    _overlay.querySelector('.setup-content').focus?.();
};

export const init = () => {
    const btn = document.getElementById('setup-btn');
    if (!btn) return;
    btn.addEventListener('click', () => openSetup());
    // Admin → LLM Providers → Quick setup: the wizard on its own, over the admin panel.
    document.getElementById('admin-llm-quick-btn')?.addEventListener('click', () => openSetup('llm'));
    // Once per browser session: "at startup" is opening the wiki, not every page load.
    if (window.WIKI_SETUP_AUTOSHOW && !sessionStorage.getItem(SHOWN_KEY)) {
        sessionStorage.setItem(SHOWN_KEY, '1');
        setTimeout(() => openSetup(), 800);
    }
};
