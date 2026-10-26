// Astucia Wiki — Copyright (C) 2026 Mads Rotwitt
// Free software under the GNU GPL v3 or later. See LICENSE for the full notice,
// or <https://www.gnu.org/licenses/>. Distributed WITHOUT ANY WARRANTY.
//
// The skills that apply in the current space, for the `/skill <name>` type-ahead in both
// chat composers. Cached per space: a space switch never reloads the page.
import { api } from './api.js';
import { state } from './state.js';

const _cache = new Map();

export const getSkills = async () => {
    const key = state.currentSpace || '';
    if (_cache.has(key)) return _cache.get(key);
    const res = await api.call('list_skills');
    const list = res.success ? (res.data || []) : [];
    _cache.set(key, list);
    return list;
};

export const invalidateSkills = () => { _cache.clear(); };

/**
 * Fill a composer's popup with the skills matching the name being typed after `/skill `.
 * Returns the query when it handled the input (the caller then runs its own autoPick /
 * select-first), or null when the cursor is not in a skill name.
 */
export const skillTypeahead = async (val, pos, popup, onPick) => {
    const m = /^\/skill\s+(\S*)$/i.exec(val.slice(0, pos));
    if (!m) return null;
    const query = m[1].toLowerCase();
    const skills = (await getSkills()).filter(s => s.name.startsWith(query)).slice(0, 8);
    popup.innerHTML = '';
    popup.classList.add('chat-mention-popup-cmd');
    skills.forEach(s => {
        const item = document.createElement('div');
        item.className = 'chat-mention-item';
        const nameEl = document.createElement('span');
        nameEl.className = 'chat-cmd-name';
        nameEl.textContent = s.name;
        const descEl = document.createElement('span');
        descEl.className = 'chat-cmd-desc';
        descEl.textContent = s.description.length > 90 ? s.description.slice(0, 89) + '…' : s.description;
        descEl.title = s.description;
        item.append(nameEl, descEl);
        item.addEventListener('mousedown', (e) => { e.preventDefault(); onPick(s.name); });
        popup.appendChild(item);
    });
    return { query, start: pos - m[1].length, count: skills.length };
};
