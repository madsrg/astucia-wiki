// Astucia Wiki — Copyright (C) 2026 Mads Rotwitt
// Free software under the GNU GPL v3 or later. See LICENSE for the full notice,
// or <https://www.gnu.org/licenses/>. Distributed WITHOUT ANY WARRANTY.
//
// A small line diff for the browser. The git panel only displays diffs the server
// produced, and git is optional, so anything that compares two texts client-side — the
// save-conflict dialog — needs its own.
//
// Longest common subsequence over lines, after trimming the common head and tail (which
// is nearly all of a typical edit, so the table stays small). A middle too large for the
// table is shown as "all removed, all added": still correct, just not minimal.

const MAX_CELLS = 4_000_000;

/** @returns {{type: ' '|'-'|'+', text: string}[]} — '-' only in `a`, '+' only in `b`. */
export const diffLines = (a, b) => {
    const A = String(a ?? '').split('\n');
    const B = String(b ?? '').split('\n');
    let head = 0;
    while (head < A.length && head < B.length && A[head] === B[head]) head++;
    let tail = 0;
    while (tail < A.length - head && tail < B.length - head
           && A[A.length - 1 - tail] === B[B.length - 1 - tail]) tail++;

    const out = [];
    for (let i = 0; i < head; i++) out.push({ type: ' ', text: A[i] });

    const a2 = A.slice(head, A.length - tail);
    const b2 = B.slice(head, B.length - tail);
    const n = a2.length, m = b2.length;
    if (n * m > MAX_CELLS) {
        a2.forEach(text => out.push({ type: '-', text }));
        b2.forEach(text => out.push({ type: '+', text }));
    } else {
        // lcs[i][j] = LCS length of a2[i..] and b2[j..], in one flat array.
        const w = m + 1;
        const lcs = new Uint32Array((n + 1) * w);
        for (let i = n - 1; i >= 0; i--) {
            for (let j = m - 1; j >= 0; j--) {
                lcs[i * w + j] = a2[i] === b2[j]
                    ? lcs[(i + 1) * w + j + 1] + 1
                    : Math.max(lcs[(i + 1) * w + j], lcs[i * w + j + 1]);
            }
        }
        let i = 0, j = 0;
        while (i < n && j < m) {
            if (a2[i] === b2[j])                                  { out.push({ type: ' ', text: a2[i] }); i++; j++; }
            else if (lcs[(i + 1) * w + j] >= lcs[i * w + j + 1])  { out.push({ type: '-', text: a2[i] }); i++; }
            else                                                  { out.push({ type: '+', text: b2[j] }); j++; }
        }
        while (i < n) out.push({ type: '-', text: a2[i++] });
        while (j < m) out.push({ type: '+', text: b2[j++] });
    }

    for (let i = A.length - tail; i < A.length; i++) out.push({ type: ' ', text: A[i] });
    return out;
};

/**
 * Keep only the changed lines and `context` unchanged lines around each; a run of
 * unchanged lines left out becomes one `{type: '…'}` marker carrying how many.
 */
export const collapseContext = (lines, context = 3) => {
    const keep = new Array(lines.length).fill(false);
    lines.forEach((l, i) => {
        if (l.type === ' ') return;
        for (let k = Math.max(0, i - context); k <= Math.min(lines.length - 1, i + context); k++) keep[k] = true;
    });
    const out = [];
    let skipped = 0;
    lines.forEach((l, i) => {
        if (keep[i]) {
            if (skipped) { out.push({ type: '…', count: skipped }); skipped = 0; }
            out.push(l);
        } else skipped++;
    });
    if (skipped) out.push({ type: '…', count: skipped });
    return out;
};
