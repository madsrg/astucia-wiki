// Astucia Wiki — Copyright (C) 2026 Mads Rotwitt
// Free software under the GNU GPL v3 or later. See LICENSE for the full notice,
// or <https://www.gnu.org/licenses/>. Distributed WITHOUT ANY WARRANTY.
//
// A native <select> dressed as the Space Selector: a bordered box with the chosen value in
// bold and a chevron, opening a white menu of rows. Introduced for the Metadata panel,
// where a native select beside a hint and a datalist input beside a placeholder were
// mistaken for one another.
//
// The <select> stays in the DOM, hidden, and remains the source of truth: whoever reads
// `.value` / `.selectedOptions` / listens for `change` keeps working unchanged, and a
// script that sets `.value` directly is followed (`sync()`). The menu is position: fixed,
// so a scrolling dialog cannot clip it.

const CHEVRON = '<svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="6 9 12 15 18 9"/></svg>';
const CHECK = '<svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="20 6 9 17 4 12"/></svg>';

let _open = null;   // the one menu open at a time

const closeOpen = () => { if (_open) _open(); };
document.addEventListener('click', (e) => {
    if (_open && !e.target.closest('.dd-menu, .dd-btn')) closeOpen();
}, true);
window.addEventListener('resize', closeOpen);
// Escape closes an open menu and goes no further — it must not also close the dialog the
// menu belongs to. With no menu open it is left alone for whoever else wants it.
document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape' && _open) { e.preventDefault(); e.stopPropagation(); closeOpen(); }
}, true);

/**
 * Dress `select` (once). `placeholder` is what the box says with nothing chosen.
 * Returns the wrapper, which also carries `sync()`.
 */
export const enhanceSelect = (select, { placeholder = '' } = {}) => {
    if (!select || select.dataset.dd) return select?.closest('.dd');
    select.dataset.dd = '1';
    const wrap = document.createElement('span');
    wrap.className = 'dd';
    select.parentNode.insertBefore(wrap, select);
    wrap.appendChild(select);
    select.classList.add('dd-native');
    select.tabIndex = -1;
    select.setAttribute('aria-hidden', 'true');

    const btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'dd-btn';
    btn.setAttribute('aria-haspopup', 'listbox');
    btn.setAttribute('aria-expanded', 'false');
    if (select.dataset.key) btn.dataset.key = select.dataset.key;
    const label = document.createElement('span');
    label.className = 'dd-label';
    const chev = document.createElement('span');
    chev.className = 'dd-chevron';
    chev.innerHTML = CHEVRON;
    btn.append(label, chev);
    wrap.appendChild(btn);

    const sync = () => {
        const chosen = [...select.options].filter(o => o.selected && o.value !== '');
        label.textContent = chosen.length ? chosen.map(o => o.textContent).join(', ') : placeholder || (select.options[0]?.textContent ?? '');
        label.classList.toggle('is-placeholder', !chosen.length);
        btn.disabled = select.disabled;
    };
    wrap.sync = sync;
    select.addEventListener('change', sync);
    sync();

    let menu = null, active = -1;
    const rows = () => [...(menu?.querySelectorAll('.dd-item') || [])];
    const highlight = (i) => {
        const r = rows();
        active = Math.max(0, Math.min(r.length - 1, i));
        r.forEach((x, k) => x.classList.toggle('is-active', k === active));
        r[active]?.scrollIntoView({ block: 'nearest' });
    };
    const pick = (opt) => {
        if (select.multiple) {
            opt.selected = !opt.selected;
        } else {
            select.value = opt.value;
        }
        select.dispatchEvent(new Event('change', { bubbles: true }));
        if (!select.multiple) close();
        else render();
    };
    const render = () => {
        menu.innerHTML = '';
        [...select.options].forEach((opt) => {
            // The empty option is "nothing chosen": a row for clearing a value, but not
            // in a multi-select, and not where it is only a prompt (data-dd-prompt).
            if (opt.value === '' && (select.multiple || 'ddPrompt' in select.dataset)) return;
            const it = document.createElement('div');
            it.className = 'dd-item' + (opt.selected ? ' is-selected' : '') + (opt.dataset.note ? ' is-note' : '');
            it.setAttribute('role', 'option');
            it.setAttribute('aria-selected', opt.selected ? 'true' : 'false');
            it.dataset.value = opt.value;
            const mark = document.createElement('span');
            mark.className = 'dd-check';
            if (opt.selected) mark.innerHTML = CHECK;
            const txt = document.createElement('span');
            txt.textContent = opt.textContent;
            it.append(mark, txt);
            it.addEventListener('mousedown', (e) => e.preventDefault());
            it.addEventListener('click', () => pick(opt));
            menu.appendChild(it);
        });
    };
    const place = () => {
        const r = btn.getBoundingClientRect();
        menu.style.minWidth = r.width + 'px';
        menu.style.left = r.left + 'px';
        const below = window.innerHeight - r.bottom;
        if (below < 220 && r.top > below) {
            menu.style.top = 'auto';
            menu.style.bottom = (window.innerHeight - r.top + 4) + 'px';
        } else {
            menu.style.bottom = 'auto';
            menu.style.top = (r.bottom + 4) + 'px';
        }
    };
    const open = () => {
        closeOpen();
        menu = document.createElement('div');
        menu.className = 'dd-menu';
        menu.setAttribute('role', 'listbox');
        if (select.multiple) menu.setAttribute('aria-multiselectable', 'true');
        render();
        document.body.appendChild(menu);
        place();
        btn.setAttribute('aria-expanded', 'true');
        wrap.classList.add('is-open');
        highlight(Math.max(0, rows().findIndex(r => r.classList.contains('is-selected'))));
        _open = close;
    };
    function close() {
        menu?.remove();
        menu = null;
        active = -1;
        btn.setAttribute('aria-expanded', 'false');
        wrap.classList.remove('is-open');
        if (_open === close) _open = null;
    }
    btn.addEventListener('click', () => (menu ? close() : open()));
    btn.addEventListener('keydown', (e) => {
        const key = e.key;
        if (!menu && (key === 'ArrowDown' || key === 'ArrowUp' || key === 'Enter' || key === ' ')) {
            e.preventDefault(); e.stopPropagation(); open(); return;
        }
        if (!menu) return;
        // The dialog this sits in listens for Enter and Escape on the document; they are
        // the menu's while it is open.
        if (key === 'ArrowDown') { e.preventDefault(); e.stopPropagation(); highlight(active + 1); }
        else if (key === 'ArrowUp') { e.preventDefault(); e.stopPropagation(); highlight(active - 1); }
        else if (key === 'Enter' || key === ' ') {
            e.preventDefault(); e.stopPropagation();
            const v = rows()[active]?.dataset.value;
            const opt = [...select.options].find(o => o.value === v);
            if (opt) pick(opt);
        }
        else if (key === 'Escape') { e.preventDefault(); e.stopPropagation(); close(); }
        else if (key === 'Tab') close();
    });
    return wrap;
};
