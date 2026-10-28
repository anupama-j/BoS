const ROLES = [
  'Chairperson/Paper Setter/Examiner/Moderator',
  'Paper Setter/Examiner/Moderator',
  'Paper Setter',
  'Examiner',
  'Moderator',
  'Translator'
];
const $ = s => document.querySelector(s);
const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
const FP = document.body.dataset.fp || '';
const FS = document.body.dataset.fs || '';
const REFRESH = new URLSearchParams(location.search).get('refresh');
const MAX_SHOWN = 150;           // keep the panel fast when a subject list is large

async function getJSON(url) {
  const r = await fetch(url);
  if (!r.ok) throw new Error('HTTP ' + r.status);
  return r.json();
}

/* ---------- colleges (from the catalogue): code <-> name auto-fill ---------- */
let COLLEGES = [];
document.body.insertAdjacentHTML('beforeend', '<datalist id="collegeCodes"></datalist><datalist id="collegeNames"></datalist>');

async function loadColleges() {
  try {
    const res = await getJSON('api.php?action=colleges');
    if (res.error) $('#apiNote').insertAdjacentHTML('beforeend', `<div class="warn">Colleges: ${esc(res.error)}</div>`);
    COLLEGES = res.items || [];
    $('#collegeCodes').innerHTML = COLLEGES.map(c => `<option value="${esc(c.code)}">${esc(c.name)}</option>`).join('');
    $('#collegeNames').innerHTML = COLLEGES.map(c => `<option value="${esc(c.name)}">${esc(c.code)}</option>`).join('');
  } catch (e) {
    $('#apiNote').insertAdjacentHTML('beforeend', `<div class="warn">Could not load colleges: ${esc(e.message)}</div>`);
  }
}

/* ---------- papersetter rows ---------- */
function addRow(v = {}) {
  const tr = document.createElement('tr');
  tr.innerHTML = `
    <td class="sr"></td>
    <td><input name="ps_name[]" value="${esc(v.name)}"></td>
    <td><input name="ps_ccode[]" list="collegeCodes" value="${esc(v.ccode)}" autocomplete="off"></td>
    <td><input name="ps_college[]" list="collegeNames" value="${esc(v.college)}" autocomplete="off"></td>
    <td><input name="ps_address[]" value="${esc(v.address)}" placeholder="Address"></td>
    <td><select name="ps_role[]">${ROLES.map(r => `<option ${r === v.role ? 'selected' : ''}>${esc(r)}</option>`).join('')}</select></td>
    <td><input name="ps_email[]" type="email" value="${esc(v.email)}"></td>
    <td><input name="ps_mobile[]" value="${esc(v.mobile)}"></td>
    <td><button type="button" class="del" title="Remove">&times;</button></td>`;
  tr.querySelector('button').onclick = () => { tr.remove(); renumber(); };

  const code = tr.querySelector('[name="ps_ccode[]"]');
  const name = tr.querySelector('[name="ps_college[]"]');
  code.addEventListener('input', () => {                      // code typed/picked -> fill college name
    const c = COLLEGES.find(x => x.code === code.value.trim());
    if (c) name.value = c.name;
  });
  name.addEventListener('input', () => {                      // name picked -> fill code
    const c = COLLEGES.find(x => x.name === name.value.trim());
    if (c) code.value = c.code;
  });

  $('#psTable tbody').appendChild(tr);
  renumber();
}
function renumber() {
  document.querySelectorAll('#psTable .sr').forEach((td, i) => td.textContent = i + 1);
}
$('#addRow').onclick = () => addRow();
for (let i = 0; i < 4; i++) addRow();

/* ---------- right-hand panel: Programmes -> Subjects (Excel or e-Samarth, decided in config.php) ---------- */
const SUBJECT_CACHE = {};        // programme id -> response

function note(res, what) {
  const n = $('#apiNote');
  let html = '';
  if (res.source === 'demo')       html = `<div class="warn">Could not reach e-Samarth &mdash; showing sample ${what}.<br>${esc(res.error)}<br><a href="test.php">Run connection test</a></div>`;
  else if (res.error)              html = `<div class="warn">${esc(res.error)}${res.source === 'excel' ? '<br><a href="test.php">Run check</a>' : ''}</div>`;
  else if (what === 'Programmes')  html = `<div class="src">Source: ${res.source === 'excel' ? 'Excel (data/catalogue)' : 'e-Samarth (' + esc(res.source) + ')'} &middot; ${res.items.length} programmes</div>`;
  n.insertAdjacentHTML('beforeend', html);
}

async function loadProgrammes() {
  const box = $('#progList');
  try {
    const res = await getJSON('api.php?action=programmes' + (REFRESH ? '&refresh=1' : ''));
    $('#apiNote').innerHTML = '';
    note(res, 'Programmes');
    box.className = ''; box.innerHTML = '';
    (res.items || []).forEach(p => {
      const label = (p.code && p.name.includes(`[${p.code}]`)) ? p.name : p.name + (p.code ? ` [${p.code}]` : '');
      const div = document.createElement('div');
      const open = FP === String(p.id);
      div.className = 'prog' + (open ? ' open' : '');
      div.innerHTML = `<div class="name"><span>${esc(label)}${p.tag ? `<br><small class="muted">${esc(p.tag)}</small>` : ''}</span><span>&#9656;</span></div><div class="subs"></div>`;
      div.querySelector('.name').onclick = () => {
        div.classList.toggle('open');
        pickProgramme(p);
        if (!div.dataset.loaded) { div.dataset.loaded = 1; renderSubjects(p, div.querySelector('.subs')); }
      };
      box.appendChild(div);
      if (open) { div.dataset.loaded = 1; renderSubjects(p, div.querySelector('.subs')); }
    });
    if (!(res.items || []).length) box.innerHTML = '<div class="muted">No programmes found.</div>';
    const ps = $('#progSearch');
    if ((res.items || []).length > 15) {                 // long lists (e-Samarth programme master has ~1500): search box
      ps.hidden = false;
      ps.oninput = () => {
        const q = ps.value.trim().toLowerCase();
        (res.items || []).forEach((p, i) => { box.children[i].hidden = !!q && !(p.name + ' ' + (p.code || '') + ' ' + (p.tag || '') + ' ' + (p.faculty || '')).toLowerCase().includes(q); });
      };
    }
  } catch (e) {
    box.innerHTML = `<div class="warn">Could not load programmes: ${esc(e.message)}</div>`;
  }
}

async function renderSubjects(p, el) {
  el.innerHTML = '<div class="muted">Loading subjects&hellip;</div>';
  try {
    if (!SUBJECT_CACHE[p.id]) {
      SUBJECT_CACHE[p.id] = await getJSON('api.php?action=subjects&programme=' + encodeURIComponent(p.id) + (REFRESH ? '&refresh=1' : ''));
      if (SUBJECT_CACHE[p.id].error) note(SUBJECT_CACHE[p.id], 'Subjects');
    }
    const items = SUBJECT_CACHE[p.id].items || [];
    el.innerHTML = (items.length > 12 ? '<input class="subsearch" type="search" placeholder="Search subject / code&hellip;">' : '') + '<div class="sublist"></div>';
    const list = el.querySelector('.sublist');
    const search = el.querySelector('.subsearch');
    const draw = () => {
      const q = search ? search.value.trim().toLowerCase() : '';
      const hits = items.filter(s => !q || (s.name + ' ' + s.code).toLowerCase().includes(q));
      list.innerHTML = '';
      if (!hits.length) list.innerHTML = '<div class="muted">No subjects found.</div>';
      hits.slice(0, MAX_SHOWN).forEach(s => {
        const d = document.createElement('div');
        d.className = 'sub' + (FS === String(s.id) && FP === String(p.id) ? ' active' : '');
        d.textContent = s.name + (s.code ? ` (${s.code})` : '');
        d.title = (s.title ? s.title + '\n' : '') + 'Click: fill the form. Double-click: show saved records for this subject.';
        d.onclick = () => {
          pickProgramme(p); pickSubject(s);
          document.querySelectorAll('.sub').forEach(x => x.classList.remove('active'));
          d.classList.add('active');
        };
        d.ondblclick = () => { location.search = `?fp=${encodeURIComponent(p.id)}&fs=${encodeURIComponent(s.id)}`; };
        list.appendChild(d);
      });
      if (hits.length > MAX_SHOWN) list.insertAdjacentHTML('beforeend', `<div class="muted">Showing ${MAX_SHOWN} of ${hits.length} &mdash; type to narrow.</div>`);
    };
    if (search) search.oninput = draw;
    draw();
  } catch (e) {
    el.innerHTML = `<div class="warn">Could not load subjects: ${esc(e.message)}</div>`;
  }
}

/* Locked fields are only ever filled from here (i.e. from the data source); the server re-checks them on save. */
function pickProgramme(p) {
  if ($('#programme_id').value !== String(p.id)) {            // different programme -> old subject no longer applies
    ['subject_id', 'subject_name', 'paper_code'].forEach(id => $('#' + id).value = '');
  }
  $('#programme_id').value = p.id;
  $('#programme_name').value = p.name;
  $('#programme_code').value = p.code || '';
  $('#faculty').value = p.faculty || '';
}
function pickSubject(s) {
  if ($('#subject_id').value !== String(s.id)) $('#course_name_dev').value = '';   // Devanagari name belongs to one course
  $('#subject_id').value = s.id;
  $('#subject_name').value = s.name;
  $('#paper_code').value = s.code || '';
}

/* exam session (locked) */
async function loadSession() {
  try {
    const res = await getJSON('api.php?action=session');
    if (res.current) $('#exam_session').value = res.current;
    else $('#apiNote').insertAdjacentHTML('beforeend', `<div class="warn">No exam session found.${res.error ? '<br>' + esc(res.error) : ''}</div>`);
  } catch (e) {
    $('#apiNote').insertAdjacentHTML('beforeend', `<div class="warn">Could not load exam session: ${esc(e.message)}</div>`);
  }
}

/* "No students opted": nothing to enter in the papersetter table */
function syncNoStudents() {
  $('#noStudNote').hidden = !$('#no_students').checked;      // only a reminder: the panel stays usable
}
$('#no_students').addEventListener('change', syncNoStudents);

/* a paper can only be saved once a programme AND subject have been picked from the panel */
$('#entryForm').addEventListener('submit', e => {
  if (!$('#programme_id').value || !$('#subject_id').value) {
    e.preventDefault();
    alert('Please pick a programme and a subject from the right-hand panel first.');
    return;
  }
  const dev = $('#course_name_dev').value.trim();
  if (dev && !/[\u0900-\u097F]/.test(dev)) {
    e.preventDefault();
    alert('Course name (Devanagari) must contain Devanagari script, e.g. माहिती तंत्रज्ञान. Leave it empty if not needed.');
    $('#course_name_dev').focus();
  }
});

loadColleges();
loadProgrammes();
loadSession();
