// Astucia Wiki — Copyright (C) 2026 Mads Rotwitt
// Free software under the GNU GPL v3 or later. See LICENSE for the full notice,
// or <https://www.gnu.org/licenses/>. Distributed WITHOUT ANY WARRANTY.
/**
 * Admin → Requests → Automatic access: rules on an SSO sign-in's claims that create the
 * account at once (Reader or Editor, in chosen Spaces), and what happens to people who
 * match no rule. The rules themselves are provisioning.php's; the server validates
 * everything this sends, so this module only has to make the rules easy to write.
 *
 * Claim names already seen in access requests are offered as suggestions, because the
 * hard part of writing a rule is knowing what the provider actually sends.
 */
import { api } from '../core/api.js';
import { showToast } from '../core/utils.js';
import { t } from '../i18n/index.js';

let cfg = { rules: [], unmatched: 'request' };
let spaces = [];
let ssoOn = true;
let knownClaims = [];
let onSaved = null;

const el = (tag, cls, text) => {
    const n = document.createElement(tag);
    if (cls) n.className = cls;
    if (text !== undefined) n.textContent = text;
    return n;
};

/** Claim names seen in requests, for the claim field's suggestions. */
export const setKnownClaims = (names) => {
    knownClaims = [...new Set(names)].sort();
    const dl = document.getElementById('admin-prov-claims');
    if (dl) dl.replaceChildren(...knownClaims.map(n => Object.assign(document.createElement('option'), { value: n })));
};

const spacesSummary = (sp) => sp === null ? t('admin.prov.spaces-all') : sp.join(', ');

const ruleRow = (rule, i) => {
    const row = el('div', 'admin-prov-rule');
    row.dataset.index = String(i);

    const claim = el('input', 'form-control admin-prov-claim');
    claim.value = rule.claim || '';
    claim.placeholder = t('admin.prov.claim-ph');
    claim.setAttribute('list', 'admin-prov-claims');
    claim.addEventListener('input', () => { rule.claim = claim.value; });

    const op = el('select', 'form-control admin-prov-op');
    [['equals', t('admin.prov.op-equals')], ['email_domain', t('admin.prov.op-domain')]].forEach(([v, label]) => {
        const o = el('option', '', label); o.value = v; o.selected = rule.op === v; op.appendChild(o);
    });
    op.addEventListener('change', () => { rule.op = op.value; value.placeholder = t(op.value === 'email_domain' ? 'admin.prov.domain-ph' : 'admin.prov.value-ph'); });

    const value = el('input', 'form-control admin-prov-value');
    value.value = rule.value || '';
    value.placeholder = t(rule.op === 'email_domain' ? 'admin.prov.domain-ph' : 'admin.prov.value-ph');
    value.addEventListener('input', () => { rule.value = value.value; });

    const role = el('select', 'form-control admin-prov-role');
    ['reader', 'editor'].forEach(r => {
        const o = el('option', '', t(`admin.prov.role-${r}`)); o.value = r; o.selected = rule.role === r; role.appendChild(o);
    });
    role.addEventListener('change', () => { rule.role = role.value; });

    // Spaces: all of them, or a chosen set — a small checkbox list behind a summary.
    const sp = el('details', 'admin-prov-spaces');
    const sum = el('summary', '', spacesSummary(rule.spaces ?? null));
    sp.appendChild(sum);
    const box = el('div', 'admin-prov-spaces-box');
    const all = el('label', 'admin-prov-space');
    const allCb = Object.assign(document.createElement('input'), { type: 'checkbox', checked: rule.spaces == null });
    all.append(allCb, document.createTextNode(' ' + t('admin.prov.spaces-all')));
    box.appendChild(all);
    const cbs = spaces.map(name => {
        const lab = el('label', 'admin-prov-space');
        const cb = Object.assign(document.createElement('input'), { type: 'checkbox', value: name,
            checked: Array.isArray(rule.spaces) && rule.spaces.includes(name), disabled: rule.spaces == null });
        lab.append(cb, document.createTextNode(' ' + name));
        box.appendChild(lab);
        return cb;
    });
    const sync = () => {
        rule.spaces = allCb.checked ? null : cbs.filter(c => c.checked).map(c => c.value);
        cbs.forEach(c => { c.disabled = allCb.checked; });
        sum.textContent = rule.spaces !== null && !rule.spaces.length ? t('admin.prov.spaces-none') : spacesSummary(rule.spaces);
    };
    allCb.addEventListener('change', sync);
    cbs.forEach(c => c.addEventListener('change', sync));
    sp.appendChild(box);

    const del = el('button', 'btn btn-sm btn-secondary admin-prov-del', '×');
    del.type = 'button';
    del.title = t('admin.prov.remove');
    del.addEventListener('click', () => { cfg.rules.splice(i, 1); render(); });

    const num = el('span', 'admin-prov-num', String(i + 1));
    row.append(num, claim, op, value, el('span', 'admin-prov-arrow', '→'), role, sp, del);
    return row;
};

const render = () => {
    const host = document.getElementById('admin-provisioning');
    if (!host) return;
    host.replaceChildren();

    const wrap = el('details', 'admin-prov');
    wrap.open = cfg.rules.length > 0 || cfg.unmatched === 'deny';
    wrap.appendChild(el('summary', 'admin-prov-title',
        t('admin.prov.title') + (cfg.rules.length ? ` (${cfg.rules.length})` : '')));
    if (!ssoOn) wrap.appendChild(el('p', 'admin-prov-note', t('admin.prov.no-sso')));
    wrap.appendChild(el('p', 'admin-prov-hint', t('admin.prov.hint')));
    wrap.appendChild(el('p', 'admin-prov-hint admin-prov-warn', t('admin.prov.warn')));

    const dl = el('datalist'); dl.id = 'admin-prov-claims';
    wrap.appendChild(dl);

    const list = el('div', 'admin-prov-rules');
    cfg.rules.forEach((r, i) => list.appendChild(ruleRow(r, i)));
    if (!cfg.rules.length) list.appendChild(el('p', 'admin-prov-empty', t('admin.prov.none')));
    wrap.appendChild(list);

    const add = el('button', 'btn btn-sm btn-secondary', t('admin.prov.add'));
    add.type = 'button';
    add.addEventListener('click', () => {
        cfg.rules.push({ claim: '', op: 'equals', value: '', role: 'reader', spaces: null });
        render();
        document.querySelector('#admin-provisioning .admin-prov-rule:last-child .admin-prov-claim')?.focus();
    });
    wrap.appendChild(add);

    const un = el('fieldset', 'admin-prov-unmatched');
    un.appendChild(el('legend', '', t('admin.prov.unmatched')));
    [['request', t('admin.prov.unmatched-request')], ['deny', t('admin.prov.unmatched-deny')]].forEach(([v, label]) => {
        const lab = el('label', 'admin-prov-radio');
        const rb = Object.assign(document.createElement('input'), { type: 'radio', name: 'admin-prov-unmatched', value: v, checked: cfg.unmatched === v });
        rb.addEventListener('change', () => { if (rb.checked) cfg.unmatched = v; });
        lab.append(rb, document.createTextNode(' ' + label));
        un.appendChild(lab);
    });
    wrap.appendChild(un);

    const save = el('button', 'btn btn-sm btn-green', t('admin.prov.save'));
    save.type = 'button';
    save.id = 'admin-prov-save';
    save.addEventListener('click', async () => {
        save.disabled = true;
        const res = await api.call('admin_save_provisioning', { config: JSON.stringify(cfg) }, 'POST');
        save.disabled = false;
        if (!res.success) { showToast(res.message || t('admin.err.generic'), 'error'); return; }
        cfg = res.config;
        showToast(t('admin.prov.saved'), 'success');
        render();
        onSaved?.();   // the request list's "would be admitted" hints follow the rules
    });
    wrap.appendChild(save);

    host.appendChild(wrap);
    setKnownClaims(knownClaims);
};

export const loadProvisioning = async (afterSave) => {
    onSaved = afterSave;
    const res = await api.call('admin_get_provisioning');
    if (!res.success) return;
    cfg = res.config || cfg;
    spaces = res.spaces || [];
    ssoOn = !!res.sso;
    render();
};
