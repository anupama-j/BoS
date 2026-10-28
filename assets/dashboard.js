/* Dashboard behaviour: tooltips (textContent only), chart/table toggle, show-all, table filter */
const tip = document.getElementById('tip');
function showTip(el, x, y) {
  let d; try { d = JSON.parse(el.dataset.tip); } catch (e) { return; }
  tip.replaceChildren();
  const t = document.createElement('div'); t.className = 't'; t.textContent = d.t; tip.append(t);
  d.r.forEach(([label, val, cls]) => {
    const row = document.createElement('div'); row.className = 'r';
    const k = document.createElement('i'); k.className = 'k ' + cls;
    const v = document.createElement('span'); v.className = 'vv'; v.textContent = val;
    const l = document.createElement('span'); l.textContent = label;
    row.append(k, v, l); tip.append(row);
  });
  tip.hidden = false;
  const r = tip.getBoundingClientRect();
  tip.style.left = Math.max(8, Math.min(x + 14, innerWidth - r.width - 8)) + 'px';
  tip.style.top  = Math.max(8, Math.min(y + 14, innerHeight - r.height - 8)) + 'px';
}
document.addEventListener('pointermove', e => {
  const el = e.target.closest && e.target.closest('[data-tip]');
  if (el) showTip(el, e.clientX, e.clientY); else tip.hidden = true;
});
document.addEventListener('focusin', e => {
  const el = e.target.closest && e.target.closest('[data-tip]');
  if (el) { const b = el.getBoundingClientRect(); showTip(el, b.left, b.bottom); }
});
document.addEventListener('focusout', () => { tip.hidden = true; });

/* chart <-> table view (every chart has a table twin) */
document.querySelectorAll('[data-card]').forEach(card => {
  const btn = card.querySelector('[data-toggle]'), viz = card.querySelector('.viz'), tbl = card.querySelector('.tbl');
  if (!btn || !tbl) return;
  btn.addEventListener('click', () => {
    const toTable = tbl.hidden;
    tbl.hidden = !toTable;
    if (viz) viz.hidden = toTable;
    card.querySelectorAll('.legend').forEach(l => l.hidden = toTable);
    btn.textContent = toTable ? 'Chart view' : 'Table view';
  });
});

document.querySelectorAll('[data-showall]').forEach(b => b.addEventListener('click', () => {
  b.closest('.viz').classList.add('showall'); b.hidden = true;
}));

document.querySelectorAll('[data-filter]').forEach(inp => inp.addEventListener('input', () => {
  const q = inp.value.trim().toLowerCase();
  document.querySelectorAll('#' + inp.dataset.filter + ' tbody tr').forEach(tr => { tr.hidden = q && !tr.textContent.toLowerCase().includes(q); });
}));
