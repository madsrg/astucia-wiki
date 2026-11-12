// Astucia Wiki — Copyright (C) 2026 Mads Rotwitt
// Free software under the GNU GPL v3 or later. See LICENSE for the full notice,
// or <https://www.gnu.org/licenses/>. Distributed WITHOUT ANY WARRANTY.
/**
 * Help — the sidebar's question-mark icon. A dialog with the keyboard shortcuts, in two
 * groups (reading, and editing Markdown), and a button to the online documentation.
 *
 * The lists below are what the code binds, and must follow it when a binding changes:
 *   S                    script.js            toggle the sidebar (not while editing or typing)
 *   E                    page_edit/hotkeys.js edit the open page
 *   Alt+1…9, Alt+W,      tabs/index.js        switch / close tabs
 *   Ctrl+Alt+←/→
 *   Alt+H                help/index.js        this dialog, from anywhere (it replaced the editor's Alt+A list)
 *   Alt+S/L/P/F          page_edit/hotkeys.js save, link, include, find & replace
 *   Alt+1–3/B/I/K/C/T/N  hotkeys.json         insertions (Alt+L is taken by the link dialog)
 *   Tab, Esc+Tab, Enter  page_edit/hotkeys.js indentation and the way out of the editor
 * Written out rather than generated from hotkeys.json, because it says what each key
 * *does* rather than which characters it inserts.
 */
import { t } from '../i18n/index.js';

const DOCS_URL = 'https://astucia.wiki/docs/';

const GROUPS = [
    { title: 'help.group-read', keys: [
        [['Alt', 'H'], 'help.key-help'],
        [['S'], 'help.key-sidebar'],
        [['E'], 'help.key-edit'],
        [['Alt', '1…9'], 'help.key-tab'],
        [['Alt', 'W'], 'help.key-tab-close'],
        [['Ctrl', 'Alt', '← / →'], 'help.key-tab-step'],
    ] },
    { title: 'help.group-edit', keys: [
        [['Alt', 'S'], 'help.key-save'],
        [['Alt', '1 / 2 / 3'], 'help.key-heading'],
        [['Alt', 'B'], 'help.key-bold'],
        [['Alt', 'I'], 'help.key-italic'],
        [['Alt', 'L'], 'help.key-link'],
        [['Alt', 'P'], 'help.key-include'],
        [['Alt', 'K'], 'help.key-comment'],
        [['Alt', 'C'], 'help.key-code'],
        [['Alt', 'T'], 'help.key-table'],
        [['Alt', 'N'], 'help.key-filename'],
        [['Alt', 'F'], 'help.key-find'],
        [['Tab', '/', 'Shift', 'Tab'], 'help.key-indent'],
        [['Enter'], 'help.key-enter'],
        [['Esc', 'Tab'], 'help.key-leave'],
    ] },
];

let _overlay = null;

const el = (tag, cls, text) => {
    const n = document.createElement(tag);
    if (cls) n.className = cls;
    if (text !== undefined) n.textContent = text;
    return n;
};

const close = () => _overlay?.classList.add('hidden');

/** One shortcut as <kbd> chips: "+" joins a chord, "then"/"or" read from the key list. */
const keyCell = (keys) => {
    const td = el('td', 'help-keys');
    keys.forEach((k, i) => {
        if (k === '/') { td.appendChild(el('span', 'help-key-sep', ' / ')); return; }
        if (i > 0 && keys[i - 1] !== '/') td.appendChild(el('span', 'help-key-sep', '+'));
        td.appendChild(el('kbd', '', k));
    });
    return td;
};

const render = () => {
    const body = _overlay.querySelector('.help-body');
    body.replaceChildren();
    for (const g of GROUPS) {
        body.appendChild(el('h4', 'help-group', t(g.title)));
        const table = el('table', 'help-table');
        for (const [keys, label] of g.keys) {
            const tr = el('tr');
            // Esc then Tab is a sequence, not a chord — the one row that says so in words.
            tr.append(label === 'help.key-leave' ? (() => {
                const td = el('td', 'help-keys');
                td.append(el('kbd', '', 'Esc'), el('span', 'help-key-sep', ` ${t('help.then')} `), el('kbd', '', 'Tab'));
                return td;
            })() : keyCell(keys), el('td', 'help-desc', t(label)));
            table.appendChild(tr);
        }
        body.appendChild(table);
    }
    _overlay.querySelector('#help-title').textContent = t('help.title');
    _overlay.querySelector('.help-note').textContent = t('help.mac-note');
    _overlay.querySelector('[data-act="docs"]').textContent = t('help.docs-btn');
    _overlay.querySelector('.lightbox-footer [data-act="close"]').textContent = t('btn.close');
};

const build = () => {
    _overlay = el('div', 'lightbox-overlay hidden');
    _overlay.id = 'help-modal';
    _overlay.innerHTML = `
        <div class="lightbox-content help-content" role="dialog" aria-modal="true" aria-labelledby="help-title">
            <button class="lightbox-close" data-act="close">&times;</button>
            <h3 id="help-title"></h3>
            <div class="help-body"></div>
            <p class="help-note"></p>
            <div class="lightbox-footer">
                <button class="btn btn-blue" data-act="docs"></button>
                <button class="btn btn-secondary" data-act="close"></button>
            </div>
        </div>`;
    document.body.appendChild(_overlay);
    _overlay.addEventListener('click', (e) => {
        if (e.target === _overlay) return close();
        const act = e.target.closest('[data-act]')?.dataset.act;
        if (act === 'close') close();
        else if (act === 'docs') window.open(DOCS_URL, '_blank', 'noopener');
    });
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape' && _overlay && !_overlay.classList.contains('hidden')) close();
    });
};

export const openHelp = () => {
    if (!_overlay) build();
    render();   // on every open, so a language switch since the last one is honoured
    _overlay.classList.remove('hidden');
    _overlay.querySelector('[data-act="docs"]').focus();
};

export const init = () => {
    document.getElementById('help-btn')?.addEventListener('click', openHelp);
    // Alt+H, from anywhere — reading or in the editor, where Alt+letter is the shortcut
    // vocabulary. Alt alone: AltGr arrives as Ctrl+Alt and types characters on many
    // layouts, and Cmd+Alt is the browser's (see "Alt is not AltGr").
    document.addEventListener('keydown', (e) => {
        if (!e.altKey || e.ctrlKey || e.metaKey || e.shiftKey) return;
        if (e.key.toLowerCase() !== 'h' && e.code !== 'KeyH') return;
        e.preventDefault();
        if (_overlay && !_overlay.classList.contains('hidden')) close(); else openHelp();
    });
};
