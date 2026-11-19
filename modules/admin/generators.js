// Astucia Wiki — Copyright (C) 2026 Mads Rotwitt
// Free software under the GNU GPL v3 or later. See LICENSE for the full notice,
// or <https://www.gnu.org/licenses/>. Distributed WITHOUT ANY WARRANTY.

// Admin → Content → Generators: every page generator in the wiki in one table — the one
// place a generator that has stopped (its owner lost access, its template has an error, its
// list is gone) is visible without opening its template. Read from admin_get_generators,
// which also registers templates no save has registered yet. A row's buttons are the
// template's own: open it, or Re-render now (which makes the administrator its owner, as
// the banner's button does). Status wording is shared with the banner (modules/generators).

import { api } from '../core/api.js';
import { showToast } from '../core/utils.js';
import { t } from '../i18n/index.js';
import { errorText, warningText, countsText, openPage } from '../generators/index.js';

const esc = (s) => String(s ?? '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');

const container = () => document.getElementById('admin-generators-list');

const statusCell = (g) => {
    let main;
    if (!g.template_exists)     main = `<span class="admin-job-status admin-job-status-err">${esc(t('generators.error.template_missing'))}</span>`;
    else if (g.error)           main = `<span class="admin-job-status admin-job-status-err">${esc(t('admin.gen.stopped'))}</span><div class="wf-sub wf-err">${esc(errorText(g))}</div>`;
    else if (g.pending)         main = `<span class="admin-job-status admin-job-status-skip">${esc(t('generators.status-pending'))}</span>`;
    else if (g.status === 'ok') main = `<span class="admin-job-status admin-job-status-ok">${esc(t('admin.gen.ok'))}</span><div class="wf-sub">${esc(countsText(g.counts))}</div>`;
    else                        main = `<span class="admin-job-status admin-job-status-skip">${esc(t('generators.status-never'))}</span>`;
    const when = g.last_run ? `<div class="wf-sub">${esc(new Date(g.last_run).toLocaleString())}</div>` : '';
    const warn = (g.warnings || []).map(w => `<div class="wf-sub generator-warning">${esc(warningText(w))}</div>`).join('');
    return main + when + warn;
};

export const loadGenerators = async () => {
    const box = container();
    if (!box) return;
    box.innerHTML = `<p class="admin-loading">${t('admin.users.loading')}</p>`;
    const res = await api.call('admin_get_generators');
    if (!res.success) { box.innerHTML = `<p class="admin-empty">${t('admin.users.failed')}</p>`; return; }
    const list = res.data || [];
    const intro = `<div class="wf-note">${t('admin.gen.intro')}</div>`;
    if (!list.length) { box.innerHTML = intro + `<p class="admin-empty">${t('admin.gen.none')}</p>`; return; }

    const rows = list.map((g, i) => {
        const tplName = (g.template_path || `#${g.template_id}`).replace(/\.md$/i, '');
        const src = g.source
            ? `${esc(g.source.space)} / ${esc(g.source.path ? g.source.path.replace(/\.list$/i, '') : `#${g.source.id}`)}`
            : '—';
        const pages = t('admin.gen.pages', { n: g.pages, folder: esc(g.folder ?? '—') });
        return `<tr data-i="${i}">
            <td class="admin-td-name">${esc(tplName)}<div class="wf-sub">${esc(g.space)}</div></td>
            <td>${src}</td>
            <td>${pages}</td>
            <td>${g.owner ? esc(g.owner) : `<span class="wf-sub">${esc(t('admin.gen.no-owner'))}</span>`}</td>
            <td style="font-size:0.82rem">${statusCell(g)}</td>
            <td class="wf-row-btns">
                ${g.template_exists ? `<button class="btn btn-sm btn-secondary gen-open">${esc(t('admin.gen.open'))}</button>
                <button class="btn btn-sm btn-blue gen-render">${esc(t('generators.render-now'))}</button>` : ''}
            </td></tr>`;
    }).join('');
    box.innerHTML = intro + `<table class="admin-table gen-table"><thead><tr>
        <th>${t('admin.gen.col-template')}</th><th>${t('admin.gen.col-source')}</th><th>${t('admin.gen.col-pages')}</th>
        <th>${t('admin.gen.col-owner')}</th><th>${t('admin.gen.col-status')}</th><th></th></tr></thead>
        <tbody>${rows}</tbody></table>`;

    box.querySelectorAll('tbody tr').forEach(tr => {
        const g = list[Number(tr.dataset.i)];
        tr.querySelector('.gen-open')?.addEventListener('click', () => {
            // The admin panel is a lightbox over the page; close it to show the template.
            document.getElementById('admin-lightbox-close-btn')?.click();
            openPage(g.space, g.template_id);
        });
        tr.querySelector('.gen-render')?.addEventListener('click', async (e) => {
            e.target.disabled = true;
            const r = await api.call('generator_render', { id: g.template_id, space: g.space }, 'POST');
            if (r.status === 'ok') showToast(t('generators.rendered', { counts: countsText(r.counts) }), 'success');
            else showToast(r.error ? errorText(r) : (r.message || t('generators.error.other', { detail: '' })), 'error');
            loadGenerators();
        });
    });
};
