/* College picker: a dropdown under the college-code / college-name boxes (works with ~1,300 colleges, unlike <datalist>).
   Type part of a code or name, pick a row with the mouse or Up/Down + Enter: both boxes are filled. */
(function () {
  let pop = null, active = null, items = [], sel = -1;

  function ensure() {
    if (pop) return pop;
    pop = document.createElement('div');
    pop.className = 'cpick'; pop.hidden = true;
    pop.addEventListener('mousedown', e => e.preventDefault());          // keep focus in the input while clicking
    document.body.appendChild(pop);
    const hide = () => { pop.hidden = true; active = null; };
    window.addEventListener('scroll', e => { if (!pop.contains(e.target)) hide(); }, true);
    window.addEventListener('resize', hide);
    document.addEventListener('mousedown', e => { if (active && !pop.contains(e.target) && e.target !== active.code && e.target !== active.name) hide(); });
    return pop;
  }

  function render() {
    pop.innerHTML = '';
    if (!items.length) { pop.innerHTML = '<div class="cpick-none">No matching college</div>'; return; }
    items.forEach((c, i) => {
      const d = document.createElement('div');
      d.className = 'cpick-row' + (i === sel ? ' on' : '');
      const b = document.createElement('b'); b.textContent = c.code;
      d.append(b, ' ' + c.name);                                          // textContent only: names are never parsed as HTML
      d.onclick = () => choose(c);
      pop.appendChild(d);
    });
  }

  function choose(c) {
    active.code.value = c.code; active.name.value = c.name;
    [active.code, active.name].forEach(el => el.dispatchEvent(new Event('change', { bubbles: true })));
    pop.hidden = true;
  }

  function open(ctx, input) {
    ensure();
    active = ctx;
    const all = ctx.get() || [];
    const q = input.value.trim().toLowerCase();
    if (!all.length) { items = []; pop.innerHTML = '<div class="cpick-none">No colleges loaded (see the message on the right)</div>'; }
    else {
      items = (q ? all.filter(c => String(c.code).toLowerCase().startsWith(q) || c.name.toLowerCase().includes(q)) : all).slice(0, 40);
      if (q) items.sort((a, b) => (String(b.code).toLowerCase() === q) - (String(a.code).toLowerCase() === q));
      sel = -1; render();
    }
    const r = input.getBoundingClientRect();
    pop.style.left = Math.max(4, Math.min(r.left, window.innerWidth - 440)) + 'px';
    pop.style.top = (r.bottom + 2) + 'px';
    pop.style.minWidth = Math.max(r.width, 360) + 'px';
    pop.hidden = false;
  }

  window.attachCollegePicker = function (codeInput, nameInput, get) {
    const ctx = { code: codeInput, name: nameInput, get };
    [codeInput, nameInput].forEach(inp => {
      inp.setAttribute('autocomplete', 'off');
      inp.addEventListener('focus', () => open(ctx, inp));
      inp.addEventListener('click', () => { if (!active || pop.hidden) open(ctx, inp); });
      inp.addEventListener('input', () => open(ctx, inp));
      inp.addEventListener('keydown', e => {
        if (!pop || pop.hidden || active !== ctx) return;
        if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
          e.preventDefault(); sel = (sel + (e.key === 'ArrowDown' ? 1 : -1) + items.length) % Math.max(items.length, 1); render();
          const on = pop.querySelector('.on'); if (on) on.scrollIntoView({ block: 'nearest' });
        } else if (e.key === 'Enter' && sel >= 0 && items[sel]) { e.preventDefault(); choose(items[sel]); }
        else if (e.key === 'Escape') { pop.hidden = true; }
      });
    });
  };
})();
