// Astucia Wiki — Copyright (C) 2026 Mads Rotwitt
// Free software under the GNU GPL v3 or later. See LICENSE for the full notice,
// or <https://www.gnu.org/licenses/>. Distributed WITHOUT ANY WARRANTY.
/**
 * "Template tags" — what a page generator's template may say, explained where it is written:
 * from the ? in a template's banner, and from the editor toolbar while a template is edited
 * (the banner is hidden then). The language itself is mustache.php; this only describes it.
 *
 * It opens with the template's own list: every field with the exact tag it has (the same keys
 * wiki_gen_rows() gives the template, from `generator_sources`) and how that kind of field is
 * used, so nobody has to guess that "Account manager" is {{account_manager}}. The rest is the
 * general reference: built-in tags, sections that show if / unless, repeats, Markdown vs HTML,
 * and the set-up keys in the front matter.
 *
 * Tags and examples are code and stay literal; every word around them goes through t().
 */
import { api } from '../core/api.js';
import { state } from '../core/state.js';
import { t } from '../i18n/index.js';

const esc = (s) => String(s ?? '').replace(/&/g, '&amp;').replace(/</g, '&lt;')
    .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
const code = (s) => `<code>${esc(s)}</code>`;
const row = (tag, what) => `<tr><td>${code(tag)}</td><td>${what}</td></tr>`;
const table = (rows) => `<table class="gen-help-table"><tbody>${rows.join('')}</tbody></table>`;
const section = (title, body) => `<section class="gen-help-section"><h4>${esc(title)}</h4>${body}</section>`;

/** How a field of this kind is written in a template. */
const usage = (f) => {
    switch (f.type) {
        case 'object':
            return f.multiple
                ? `${code(`{{#${f.key}}}…{{/${f.key}}}`)} — ${esc(t('generators.help-use-repeat'))}; ${code(`{{_add.${f.key}}}`)}`
                : `${code(`{{${f.key}}}`)}, ${code(`{{${f.key}.${(f.subs?.[0]?.key) || 'field'}}}`)}`;
        case 'lookup': {
            // Its items carry their own list's fields (page_generators.php, wiki_gen_rows).
            const sub = (f.subs || []).find(x => !x.title)?.key || 'field';
            return f.multiple
                ? `${code(`{{${f.key}}}`)}; ${code(`{{#${f.key}}}{{_text}} {{_edit}} {{${sub}}}{{/${f.key}}}`)} — ${esc(t('generators.help-use-repeat'))}`
                : `${code(`{{${f.key}}}`)}, ${code(`{{${f.key}.${sub}}}`)}`;
        }
        case 'boolean':
            return `${code(`{{#${f.key}}}…{{/${f.key}}}`)}, ${code(`{{^${f.key}}}…{{/${f.key}}}`)}`;
        case 'number':
            return `${code(`{{${f.key}}}`)}, ${code(`{{${f.key}.value}}`)}, ${code(`{{${f.key}.unit}}`)}`;
        default:
            return code(`{{${f.key}}}`);
    }
};

let sources = null;     // generator_sources, once per page load

/** This template's list's fields, or null when it cannot be read. */
export const templateFields = async () => {
    const src = state.currentPageGenerator?.source;
    if (!src?.readable) return null;
    sources ??= api.call('generator_sources').then(r => (r.success ? r.data : [])).catch(() => []);
    const list = (await sources).find(l => l.space === src.space && Number(l.id) === Number(src.id));
    return list ? { title: `${list.space} / ${list.title}`, fields: list.fields } : null;
};

const body = (mine) => {
    const out = [`<p class="generator-hint">${esc(t('generators.help-intro'))}</p>`];

    if (mine) {
        const rows = mine.fields.map(f => `<tr><td>${esc(f.name)}${f.title ? ` <span class="gen-help-badge">${esc(t('list.title-badge'))}</span>` : ''}</td><td>${usage(f)}</td></tr>`);
        out.push(section(t('generators.help-your-fields', { list: mine.title }),
            `<table class="gen-help-table gen-help-fields"><thead><tr><th>${esc(t('generators.help-col-field'))}</th><th>${esc(t('generators.help-col-tag'))}</th></tr></thead><tbody>${rows.join('')}</tbody></table>`));
    }

    out.push(section(t('generators.help-fields'), `<p>${esc(t('generators.help-fields-text'))}</p>`));

    out.push(section(t('generators.help-builtin'), table([
        row('{{_title}}', esc(t('generators.help-b-title'))),
        row('{{_edit}}', esc(t('generators.help-b-edit'))),
        row('{{_add.cards}}', esc(t('generators.help-b-add'))),
        row('{{#_comments}}{{name}} {{at}} {{text}}{{/_comments}}', esc(t('generators.help-k-comments'))),
        row('{{_comment_form}}', esc(t('generators.help-b-comment-form'))),
        row('{{_source.title}}', esc(t('generators.help-b-source-title'))),
        row('{{_source.url}}', esc(t('generators.help-b-source-url'))),
        row('{{_row.id}}', esc(t('generators.help-b-row'))),
    ])));

    out.push(section(t('generators.help-sections'), table([
        row('{{#field}} … {{/field}}', esc(t('generators.help-sec-if'))),
        row('{{^field}} … {{/field}}', esc(t('generators.help-sec-unless'))),
    ]) + `<pre class="gen-help-example">${esc('{{#phone}}Phone: {{phone}}{{/phone}}\n{{^phone}}No phone on file.{{/phone}}')}</pre>`));

    out.push(section(t('generators.help-loops'), `<p>${esc(t('generators.help-loops-text'))}</p>` + table([
        row('{{_index}}', esc(t('generators.help-l-index'))),
        row('{{#_first}} … {{/_first}}', esc(t('generators.help-l-first'))),
        row('{{^_last}}, {{/_last}}', esc(t('generators.help-l-last'))),
    ]) + `<pre class="gen-help-example">${esc('{{#cards}}\n{{_index}}. {{number}}, expires {{expires}}\n{{/cards}}\n{{^cards}}No cards.{{/cards}}')}</pre>`));

    out.push(section(t('generators.help-kinds'), table([
        row('{{address}}  {{address.city}}', esc(t('generators.help-k-group'))),
        row('{{price}}  {{price.value}}  {{price.unit}}', esc(t('generators.help-k-number'))),
        row('{{paid}}  {{#paid}}…{{/paid}}', esc(t('generators.help-k-yesno'))),
        row('{{customer}}  {{#customer}}{{city}}{{/customer}}', esc(t('generators.help-k-lookup'))),
    ])));

    out.push(section(t('generators.help-markup'),
        `<p>${esc(t('generators.help-md'))}</p><p>${esc(t('generators.help-html'))}</p>`));

    out.push(section(t('generators.help-setup'), table([
        row('generator_source', esc(t('generators.help-s-source'))),
        row('generator_folder', esc(t('generators.help-s-folder'))),
        row('generator_filename', esc(t('generators.help-s-filename'))),
    ])));
    return out.join('');
};

/** Open the dialog. `dialog` is the generators module's own (title, html, opts). */
export const openTemplateHelp = async (dialog) => {
    const mine = await templateFields();
    const { overlay } = dialog(t('generators.help-title'), body(mine), { wide: true });
    overlay.classList.add('gen-help');
};
