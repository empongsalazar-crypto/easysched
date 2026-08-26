(() => {
  'use strict';

  const NAV = {
    admin: [
      ['dashboard', 'Overview'], ['schedules', 'Schedule'], ['data', 'Academic setup'], ['reports', 'Reports'], ['settings', 'Settings']
    ],
    scheduler: [
      ['dashboard', 'Overview'], ['schedules', 'Schedule'], ['data', 'Academic setup'], ['reports', 'Reports'], ['settings', 'Settings']
    ],
    instructor: [
      ['dashboard', 'Overview'], ['schedules', 'My schedule'], ['settings', 'Settings']
    ],
    student: [
      ['dashboard', 'Overview'], ['schedules', 'My section'], ['settings', 'Settings']
    ]
  };
  const PAGE_META = {
    dashboard: ['Overview', 'Scheduling overview'],
    schedules: ['Published timetable', 'Weekly schedule'],
    data: ['Master data', 'Academic setup'],
    reports: ['Evidence and review', 'Reports'],
    settings: ['System controls', 'Settings']
  };
  const ICONS = { dashboard: '&#8962;', schedules: '&#9638;', data: '&#9670;', reports: '&#9635;', settings: '&#9881;' };
  const state = { snapshot: null, page: 'dashboard', dataTab: 'rooms', scheduleView: 'all', scheduleFilter: 'all', query: '' };
  let modalMode = null;
  let modalRecord = null;
  let modalReturnFocus = null;
  let cloudSyncRunning = false;
  let cloudSyncTimer = null;
  let registrationOpen = false;

  const $ = (selector, root = document) => root.querySelector(selector);
  const $$ = (selector, root = document) => Array.from(root.querySelectorAll(selector));
  const esc = (value) => String(value ?? '').replace(/[&<>"']/g, (char) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[char]));
  const text = (value) => String(value ?? '').trim();
  const role = () => state.snapshot?.user?.role || '';
  const canManage = () => ['admin', 'scheduler'].includes(role());
  const canAdmin = () => role() === 'admin';
  const days = () => Object.entries(state.snapshot?.days || {}).sort((a, b) => Number(a[0]) - Number(b[0]));
  const slots = () => state.snapshot?.time_slots || [];
  const schedules = () => state.snapshot?.schedules || [];
  const subjectById = (id) => (state.snapshot?.subjects || []).find((item) => Number(item.id) === Number(id));

  async function request(action, options = {}) {
    const method = options.method || 'GET';
    const headers = { Accept: 'application/json' };
    let url = `api.php?action=${encodeURIComponent(action)}`;
    const init = { method, headers, credentials: 'same-origin' };
    if (method !== 'GET') {
      headers['Content-Type'] = 'application/json';
      const payload = { ...(options.body || {}), csrf: state.snapshot?.csrf || '' };
      init.body = JSON.stringify(payload);
    }
    const response = await fetch(url, init);
    const contentType = response.headers.get('content-type') || '';
    if (action === 'export' && !contentType.includes('application/json')) return response;
    let payload;
    try { payload = await response.json(); } catch { throw new Error('The server returned an invalid response.'); }
    if (response.status === 401) {
      state.snapshot = null;
      if (!registrationOpen && action !== 'registration_options') showLogin();
    }
    if (!response.ok || payload.ok === false) {
      const error = new Error(payload.error || 'The request could not be completed.');
      error.details = payload.details || {};
      error.status = response.status;
      throw error;
    }
    return payload.data;
  }

  function showToast(title, message, type = 'success') {
    const toast = document.createElement('div');
    toast.className = `toast ${type}`;
    const body = document.createElement('div');
    body.innerHTML = `<strong>${esc(title)}</strong><p>${esc(message)}</p>`;
    const close = document.createElement('button');
    close.type = 'button'; close.setAttribute('aria-label', 'Dismiss notification'); close.textContent = 'x';
    close.addEventListener('click', () => toast.remove());
    toast.append(body, close); $('#toastStack').append(toast);
    window.setTimeout(() => toast.remove(), 5000);
  }

  function showLogin(message = '') {
    registrationOpen = false;
    $('#loginView').hidden = false; $('#appView').hidden = true;
    $('#loginView').classList.remove('registration-mode');
    $('#registrationView').hidden = true; $('#loginForm').closest('.login-panel').hidden = false;
    $('#loginError').textContent = message;
    $('#loginUsername').focus();
  }

  async function showRegistration() {
    registrationOpen = true;
    $('#loginView').classList.add('registration-mode'); $('#loginForm').closest('.login-panel').hidden = true; $('#registrationView').hidden = false; $('#registrationError').textContent = '';
    try {
      const options = await request('registration_options');
      $('#registrationProgram').innerHTML = '<option value="">Select program</option>' + options.programs.map((item) => `<option value="${esc(item.id)}">${esc(item.code)} - ${esc(item.name)}</option>`).join('');
      $('#registrationSection').innerHTML = '<option value="">No section assigned yet</option>' + options.sections.map((item) => `<option value="${esc(item.id)}" data-program="${esc(item.program_id)}" data-year="${esc(item.year_level)}">${esc(item.code)} - Year ${esc(item.year_level)}</option>`).join('');
    } catch (error) { $('#registrationError').textContent = error.message; }
    $('#registrationName').focus();
  }

  async function registerStudent(event) { event.preventDefault(); $('#registrationError').textContent = ''; const body = { display_name: $('#registrationName').value, username: $('#registrationUsername').value, enrollment_ref: $('#registrationRef').value, email: $('#registrationEmail').value, program_id: $('#registrationProgram').value, year_level: $('#registrationYear').value, section_id: $('#registrationSection').value, password: $('#registrationPassword').value }; try { const result = await request('register', { method: 'POST', body }); showLogin(); showToast('Registration submitted', result.message, 'success'); } catch (error) { $('#registrationError').textContent = error.message; } }

  async function reviewRegistration(id, decision) { const label = decision === 'APPROVE' ? 'approve this student registration' : 'reject this student registration'; if (!window.confirm(`Are you sure you want to ${label}?`)) return; const button = $(`.review-registration[data-registration-id="${id}"][data-decision="${decision}"]`); if (button) { button.disabled = true; button.textContent = decision === 'APPROVE' ? 'Approving...' : 'Rejecting...'; } try { const result = await request('review_registration', { method: 'POST', body: { registration_id: id, decision } }); if (result.snapshot) result.snapshot.pending_registrations = (result.snapshot.pending_registrations || []).filter((row) => Number(row.id) !== Number(id)); applySnapshot(result.snapshot); showToast('Registration reviewed', result.message); } catch (error) { if (button) { button.disabled = false; button.textContent = decision === 'APPROVE' ? 'Approve' : 'Reject'; } showToast('Could not review registration', error.message, 'error'); } }

  function updateLoginChallenge(details = {}) {
    const required = Boolean(details.captcha_required);
    $('#loginCaptchaWrap').hidden = !required;
    $('#loginCaptcha').required = required;
    $('#loginCaptcha').value = '';
    if (required) $('#loginCaptchaLabel').textContent = details.captcha_question || 'Security check';
  }

  function showApp() {
    $('#loginView').hidden = true; $('#appView').hidden = false;
    $('#userName').textContent = state.snapshot.user.display_name;
    $('#userRole').textContent = state.snapshot.user.role;
    $('#userAvatar').textContent = state.snapshot.user.display_name.slice(0, 2).toUpperCase();
    renderCloudStatus();
    buildNavigation();
    renderAll();
  }

  function renderCloudStatus() {
    const sync = state.snapshot?.cloud_sync;
    const dot = $('#cloudStatusDot');
    const label = $('#cloudStatusText');
    if (!dot || !label) return;
    dot.className = 'status-dot';
    if (state.snapshot?.database_driver === 'pgsql') {
      label.textContent = 'Supabase PostgreSQL';
      return;
    }
    if (!sync?.configured) {
      label.textContent = 'Local SQLite';
      return;
    }
    if (sync.last_error) {
      dot.classList.add('error');
      label.textContent = 'Cloud retry pending';
    } else if (sync.dirty) {
      dot.classList.add('pending');
      label.textContent = 'Waiting for cloud backup';
    } else {
      label.textContent = 'Local + cloud backed up';
    }
  }

  function scheduleCloudSync(delay = 1500) {
    if (!state.snapshot?.cloud_sync?.configured || !state.snapshot.cloud_sync.dirty) return;
    window.clearTimeout(cloudSyncTimer);
    cloudSyncTimer = window.setTimeout(syncCloud, delay);
  }

  async function syncCloud() {
    if (cloudSyncRunning || !state.snapshot?.cloud_sync?.configured || !state.snapshot.cloud_sync.dirty) return;
    cloudSyncRunning = true;
    try {
      const result = await request('sync_cloud', { method: 'POST', body: {} });
      if (state.snapshot) state.snapshot.cloud_sync = result.cloud_sync;
    } catch {
      if (state.snapshot?.cloud_sync) {
        state.snapshot.cloud_sync.dirty = true;
        state.snapshot.cloud_sync.last_error = 'Cloud backup is temporarily unavailable.';
      }
    } finally {
      cloudSyncRunning = false;
      renderCloudStatus();
    }
  }

  function buildNavigation() {
    const nav = $('#navList');
    nav.replaceChildren();
    (NAV[role()] || []).forEach(([id, label]) => {
      const item = document.createElement('li');
      const button = document.createElement('button');
      button.type = 'button'; button.dataset.navigate = id; button.className = id === state.page ? 'active' : '';
      button.setAttribute('aria-current', id === state.page ? 'page' : 'false');
      button.innerHTML = `<span class="nav-icon" aria-hidden="true">${ICONS[id]}</span><span>${esc(label)}</span>`;
      item.append(button); nav.append(item);
    });
  }

  function navigate(page) {
    if (!(NAV[role()] || []).some(([id]) => id === page)) return;
    if (state.page !== page) {
      state.query = '';
      $('#globalSearch').value = '';
    }
    state.page = page;
    $$('.page').forEach((section) => { section.hidden = section.dataset.page !== page; });
    const activePage = $(`#page-${page}`);
    activePage?.classList.remove('page-enter');
    if (activePage) requestAnimationFrame(() => activePage.classList.add('page-enter'));
    $$('#navList button').forEach((button) => { const active = button.dataset.navigate === page; button.classList.toggle('active', active); button.setAttribute('aria-current', active ? 'page' : 'false'); });
    const [eyebrow, title] = PAGE_META[page]; $('#pageEyebrow').textContent = eyebrow; $('#pageTitle').textContent = title;
    const searchField = $('#globalSearch').closest('.search-field');
    const searchablePage = ['schedules', 'data'].includes(page);
    searchField.hidden = !searchablePage;
    searchField.setAttribute('aria-hidden', String(!searchablePage));
    $('#globalSearch').setAttribute('placeholder', page === 'data' ? 'Search records' : 'Search classes');
    closeSidebar();
    if (page === 'dashboard') renderDashboard();
    if (page === 'schedules') renderSchedules();
    if (page === 'data') renderData();
    if (page === 'reports') renderReports();
    if (page === 'settings') renderSettings();
  }

  function applySnapshot(snapshot) {
    state.snapshot = snapshot;
    if (!snapshot) return showLogin();
    if (!(NAV[snapshot.user.role] || []).some(([id]) => id === state.page)) state.page = 'dashboard';
    showApp();
    scheduleCloudSync();
  }

  function metric(label, value, note) { return `<div class="metric"><div class="metric-label">${esc(label)}</div><div class="metric-value">${esc(value)}</div><div class="metric-note">${esc(note)}</div></div>`; }

  function renderDashboard() {
    const snapshot = state.snapshot; const list = schedules(); const run = snapshot.active_run; const validation = snapshot.validation;
    const assigned = run ? Number(run.assigned_tasks) : 0; const total = run ? Number(run.total_tasks) : snapshot.offerings.length;
    $('#metricGrid').innerHTML = [metric('Active offerings', snapshot.offerings.length, 'Course-section assignments'), metric('Published classes', list.length, run ? `Run #${run.id}` : 'No published run'), metric('Sections', snapshot.sections.length, 'Current academic term'), metric('Rooms', snapshot.rooms.length, 'Available resources')].join('');
    $('#scheduleHealthBadge').className = `badge ${validation?.valid ? '' : run ? 'badge-warning' : 'badge-neutral'}`;
    $('#scheduleHealthBadge').textContent = run ? (validation?.valid ? 'Validated' : 'Review required') : 'No schedule';
    const checks = Object.entries(validation?.checks || {});
    $('#dashboardHealth').innerHTML = checks.length ? checks.map(([item, passed]) => `<div class="health-row"><span>${esc(item.replaceAll('_', ' '))}</span><strong class="${passed ? 'health-ok' : 'health-error'}">${passed ? 'Passed' : 'Failed'}</strong></div>`).join('') : '<div class="empty-state">Generate a schedule to see constraint validation.</div>';
    $('#runSummary').innerHTML = run ? [`<div class="summary-row"><span>Run status</span><strong>${esc(run.status)}</strong></div>`, `<div class="summary-row"><span>Assigned tasks</span><strong>${assigned} / ${total}</strong></div>`, `<div class="summary-row"><span>Search nodes</span><strong>${Number(run.diagnostics?.search_nodes || 0).toLocaleString()}</strong></div>`, `<div class="summary-row"><span>Soft-cost score</span><strong>${esc(run.diagnostics?.soft_cost ?? 'n/a')}</strong></div>`].join('') : '<div class="empty-state">No generation record yet.</div>';
    const upcoming = list.slice(0, 6);
    $('#upcomingBody').innerHTML = upcoming.length ? upcoming.map((row) => `<tr><td><strong>${esc(row.day_name)}</strong><span class="subline">${esc(row.time_label || row.slot_label)}</span></td><td><strong>${esc(row.subject_code)}</strong><span class="subline">${esc(row.subject_name)}</span></td><td>${esc(row.section_code)}</td><td>${esc(row.room_code)}</td><td>${esc(row.instructor_name)}</td></tr>`).join('') : '<tr><td colspan="5"><div class="empty-state"><strong>No published schedule</strong>Generate a conflict-free timetable to populate this list.</div></td></tr>';
    $$('.manage-only').forEach((element) => { element.hidden = !canManage(); });
  }

  function scheduleRow(row, includeAction = false) {
    const action = includeAction && canManage() ? `<td class="manage-column"><button class="button button-ghost edit-entry" data-entry-id="${Number(row.id)}" type="button">Edit</button><button class="button button-danger cancel-entry" data-entry-id="${Number(row.id)}" type="button">Cancel</button></td>` : '';
    return `<tr><td>${esc(row.day_name)}</td><td><strong>${esc(row.time_label || row.slot_label)}</strong></td><td><strong>${esc(row.subject_code)}</strong><span class="subline">${esc(row.subject_name)}</span></td><td>${esc(row.section_code)}<span class="subline">${esc(row.program_code)}</span></td><td>${esc(row.instructor_name)}</td><td>${esc(row.room_code)}<span class="subline">${Number(row.room_capacity)} seats</span></td>${action}</tr>`;
  }

  function visibleScheduleRows() {
    const query = state.query.toLowerCase();
    return schedules().filter((row) => {
      const selected = state.scheduleView === 'all' || state.scheduleFilter === 'all' || (state.scheduleView === 'section' && String(row.section_id) === state.scheduleFilter) || (state.scheduleView === 'instructor' && String(row.instructor_id) === state.scheduleFilter) || (state.scheduleView === 'room' && String(row.room_id) === state.scheduleFilter);
      const searchable = `${row.subject_code} ${row.subject_name} ${row.section_code} ${row.instructor_name} ${row.room_code} ${row.day_name} ${row.slot_label}`.toLowerCase();
      return selected && (!query || searchable.includes(query));
    });
  }

  function renderSchedules() {
    renderFilterValues();
    const rows = visibleScheduleRows(); $('#scheduleCountLabel').textContent = `${rows.length} ${rows.length === 1 ? 'class' : 'classes'}`;
    const columnCount = canManage() ? 7 : 6;
    $('#scheduleTableBody').innerHTML = rows.length ? rows.map((row) => scheduleRow(row, true)).join('') : `<tr><td colspan="${columnCount}"><div class="empty-state"><strong>No classes match this view</strong>Change the filter or generate a schedule.</div></td></tr>`;
    const hasRun = Boolean(state.snapshot.active_run);
    const valid = Boolean(state.snapshot.validation?.valid);
    $('#conflictBadge').textContent = hasRun ? (valid ? 'Validated' : 'Conflict found') : 'No run';
    $('#conflictBadge').className = `badge ${hasRun ? (valid ? 'badge-neutral' : 'badge-warning') : 'badge-neutral'}`;
    renderCalendar(rows);
    $$('.manage-column').forEach((cell) => { cell.hidden = !canManage(); });
  }

  function renderFilterValues() {
    const select = $('#scheduleFilterValue'); const previous = state.scheduleFilter; let values = [];
    if (state.scheduleView === 'section') values = state.snapshot.sections.map((item) => [item.id, `${item.code} - ${item.program_code}`]);
    if (state.scheduleView === 'instructor') values = state.snapshot.instructors.map((item) => [item.id, item.name]);
    if (state.scheduleView === 'room') values = state.snapshot.rooms.map((item) => [item.id, item.code]);
    $('#scheduleFilterValueWrap').hidden = state.scheduleView === 'all';
    select.innerHTML = `<option value="all">All</option>${values.map(([id, label]) => `<option value="${esc(id)}">${esc(label)}</option>`).join('')}`;
    select.value = values.some(([id]) => String(id) === previous) ? previous : 'all'; state.scheduleFilter = select.value;
  }

  function renderCalendar(rows) {
    const columns = days(); const byCell = new Map(); rows.forEach((row) => { const key = `${row.day_of_week}:${row.slot_id}`; if (!byCell.has(key)) byCell.set(key, []); byCell.get(key).push(row); });
    let html = '<div class="calendar-cell calendar-head">Time</div>' + columns.map(([, name]) => `<div class="calendar-cell calendar-head">${esc(name)}</div>`).join('');
    slots().forEach((slot) => { html += `<div class="calendar-cell calendar-time">${esc(slot.label)}</div>`; columns.forEach(([day]) => { const entries = byCell.get(`${day}:${slot.id}`) || []; html += `<div class="calendar-cell">${entries.map((row) => { const subject = subjectById(row.subject_id); const lab = subject?.room_type === 'LAB'; return `<div class="calendar-event ${lab ? 'lab' : ''}" title="${esc(`${row.subject_code} | ${row.time_label} | ${row.section_code} | ${row.room_code}`)}"><strong>${esc(row.subject_code)}</strong><span>${esc(row.time_label)}</span><span>${esc(row.section_code)} · ${esc(row.room_code)}</span><span>${esc(row.instructor_name)}</span></div>`; }).join('')}</div>`; }); });
    $('#calendarGrid').innerHTML = html;
  }

  const DATA_META = {
    rooms: { title: 'Rooms', singular: 'Room', headers: ['Code', 'Name', 'Capacity', 'Type', 'Features'], row: (item) => [item.code, item.name, `${item.capacity} seats`, item.room_type, (item.features || []).join(', ')], fields: roomFields },
    instructors: { title: 'Faculty', singular: 'Faculty member', headers: ['Employee no.', 'Name', 'Email', 'Max hours/day'], row: (item) => [item.employee_no, item.name, item.email || '-', `${item.max_hours_day} hours`], fields: instructorFields },
    subjects: { title: 'Subjects', singular: 'Subject', headers: ['Code', 'Name', 'Hours/week', 'Duration', 'Room type'], row: (item) => [item.code, item.name, `${item.hours_per_week} hours`, `${item.duration_slots} slot(s)`, item.room_type], fields: subjectFields },
    programs: { title: 'Programs', singular: 'Program', headers: ['Code', 'Program name'], row: (item) => [item.code, item.name], fields: programFields },
    sections: { title: 'Sections', singular: 'Section', headers: ['Code', 'Program', 'Year', 'Students'], row: (item) => [item.code, item.program_code, `Year ${item.year_level}`, item.student_count], fields: sectionFields },
    offerings: { title: 'Course offerings', singular: 'Course offering', headers: ['Subject', 'Section', 'Instructor', 'Enrollment', 'Meetings'], row: (item) => [item.subject_code, item.section_code, item.instructor_name, item.enrollment, item.required_meetings], fields: offeringFields },
    users: { title: 'Users', singular: 'User', headers: ['Username', 'Display name', 'Role', 'Assignment'], row: (item) => [item.username, item.display_name, item.role, item.instructor_name || item.section_code || '-'], fields: userFields }
  };

  function renderData() {
    if (state.dataTab === 'users' && !canAdmin()) state.dataTab = 'rooms';
    const meta = DATA_META[state.dataTab]; $('#dataTabTitle').textContent = meta.title; $('#dataTableHead').innerHTML = `<tr>${meta.headers.map((header) => `<th>${esc(header)}</th>`).join('')}<th class="manage-column">Action</th></tr>`;
    $$('.tab-button').forEach((button) => { const active = button.dataset.dataTab === state.dataTab; button.classList.toggle('active', active); button.setAttribute('aria-selected', String(active)); });
    const query = state.query.toLowerCase();
    const source = (state.snapshot[state.dataTab] || []).filter((item) => !query || Object.values(item).some((value) => String(value ?? '').toLowerCase().includes(query)));
    $('#dataTableBody').innerHTML = source.length ? source.map((item) => `<tr>${meta.row(item).map((cell) => `<td>${esc(cell)}</td>`).join('')}<td class="manage-column"><button class="button button-ghost edit-record" data-entity="${state.dataTab}" data-record-id="${Number(item.id || 0)}" type="button">Edit</button><button class="button button-danger delete-record" data-entity="${state.dataTab}" data-record-id="${Number(item.id || 0)}" type="button">Deactivate</button></td></tr>`).join('') : `<tr><td colspan="${meta.headers.length + 1}"><div class="empty-state">No records found.</div></td></tr>`;
    $$('.manage-column').forEach((cell) => { cell.hidden = !canManage(); });
    renderPendingRegistrations();
  }

  function renderPendingRegistrations() { const panel = $('#registrationReviewPanel'); if (!panel) return; panel.hidden = !canAdmin(); if (!canAdmin()) return; const rows = state.snapshot.pending_registrations || []; $('#pendingRegistrationCount').textContent = `${rows.length} pending`; $('#pendingRegistrationBody').innerHTML = rows.length ? rows.map((row) => `<tr><td>${esc(row.display_name)}</td><td>${esc(row.username)}</td><td>${esc(row.enrollment_ref)}</td><td>${esc(row.program_code)}</td><td>${esc(row.year_level)}</td><td>${esc(row.section_code || 'Unassigned')}</td><td><button class="button button-primary review-registration" data-registration-id="${Number(row.id)}" data-decision="APPROVE" type="button">Approve</button> <button class="button button-danger review-registration" data-registration-id="${Number(row.id)}" data-decision="REJECT" type="button">Reject</button></td></tr>`).join('') : '<tr><td colspan="7"><div class="empty-state">No pending registrations.</div></td></tr>'; }

  function renderReports() {
    const rows = schedules(); const roomCounts = new Map(); rows.forEach((row) => roomCounts.set(row.room_code, (roomCounts.get(row.room_code) || 0) + 1)); const max = Math.max(1, ...roomCounts.values());
    const run = state.snapshot.active_run; const validation = state.snapshot.validation; const metrics = [metric('Published classes', rows.length, 'Current published run'), metric('Faculty used', new Set(rows.map((row) => row.instructor_id)).size, `of ${state.snapshot.instructors.length}`), metric('Rooms used', new Set(rows.map((row) => row.room_id)).size, `of ${state.snapshot.rooms.length}`), metric('Sections covered', new Set(rows.map((row) => row.section_id)).size, `of ${state.snapshot.sections.length}`)];
    $('#reportMetricGrid').innerHTML = metrics.join(''); $('#constraintReport').innerHTML = Object.entries(validation?.checks || {}).map(([item, passed]) => `<div class="constraint-row"><span class="constraint-status ${passed ? '' : 'health-error'}">${passed ? 'PASS' : 'FAIL'}</span><span>${esc(item.replaceAll('_', ' '))}</span></div>`).join('') || '<div class="empty-state">No published run.</div>';
    $('#roomReport').innerHTML = state.snapshot.rooms.map((room) => { const count = roomCounts.get(room.code) || 0; return `<div class="bar-row"><span class="bar-label">${esc(room.code)}</span><div class="bar-track"><div class="bar-fill" style="width:${Math.round((count / max) * 100)}%"></div></div><strong>${count}</strong></div>`; }).join('');
  }

  function renderSettings() {
    const terms = state.snapshot.terms; const active = terms.find((term) => Number(term.id) === Number(state.snapshot.active_term_id)) || terms[0]; if (active) { $('#academicYear').value = active.academic_year; $('#semester').value = active.semester; } $('#settingsForm').closest('.panel').hidden = !canAdmin();
  }

  function renderAll() { buildNavigation(); $$('.manage-only').forEach((element) => { element.hidden = !canManage(); }); $$('.admin-only').forEach((element) => { element.hidden = !canAdmin(); }); navigate(state.page); }

  function openModal(title, fields, mode, record = {}) { modalMode = mode; modalRecord = record; modalReturnFocus = document.activeElement instanceof HTMLElement ? document.activeElement : null; $('#modalTitle').textContent = title; $('#modalEyebrow').textContent = mode.startsWith('edit') ? 'Update record' : 'New record'; $('#modalBody').innerHTML = `<div class="form-grid">${fields(record).join('')}</div>`; $('#modalBackdrop').hidden = false; $('#modalCloseButton').focus(); }
  function inputField(id, label, value, type = 'text', required = true, extra = '') { const full = /(?:^|\s)class="[^"]*\bfull\b/.test(extra); return `<div class="field${full ? ' full' : ''}"><label for="modal-${id}">${esc(label)}</label><input id="modal-${id}" name="${esc(id)}" type="${type}" value="${esc(value)}" ${required ? 'required' : ''} ${extra}></div>`; }
  function selectField(id, label, value, options, required = true) { return `<div class="field"><label for="modal-${id}">${esc(label)}</label><select id="modal-${id}" name="${esc(id)}" ${required ? 'required' : ''}>${options.map(([optionValue, optionLabel]) => `<option value="${esc(optionValue)}" ${String(optionValue) === String(value) ? 'selected' : ''}>${esc(optionLabel)}</option>`).join('')}</select></div>`; }
  function roomFields(row) { return [inputField('code','Room code',row.code || ''), inputField('name','Room name',row.name || ''), inputField('capacity','Capacity',row.capacity || '', 'number', true, 'min="1" max="5000"'), selectField('room_type','Room type',row.room_type || 'LECTURE', [['LECTURE','Lecture'],['LAB','Laboratory'],['SPECIAL','Special']]), inputField('features','Features (comma separated)',(row.features || []).join(', '), 'text', false, 'class="full"')]; }
  function instructorFields(row) { return [inputField('employee_no','Employee number',row.employee_no || ''), inputField('name','Full name',row.name || ''), inputField('email','Email',row.email || '', 'email', false), inputField('max_hours_day','Maximum hours per day',row.max_hours_day || 6, 'number', true, 'min="1" max="16"')]; }
  function subjectFields(row) { return [inputField('code','Subject code',row.code || ''), inputField('name','Subject name',row.name || ''), inputField('units','Units',row.units || 3, 'number', true, 'min="1" max="12"'), inputField('hours_per_week','Hours per week',row.hours_per_week || 2, 'number', true, 'min="1" max="40"'), inputField('duration_slots','Duration in slots',row.duration_slots || 1, 'number', true, 'min="1" max="8"'), selectField('room_type','Room type',row.room_type || 'LECTURE', [['LECTURE','Lecture'],['LAB','Laboratory'],['SPECIAL','Special']]), inputField('required_features','Required features (comma separated)',(row.required_features || []).join(', '), 'text', false, 'class="full"')]; }
  function programFields(row) { return [inputField('code','Program code',row.code || ''), inputField('name','Program name',row.name || '', 'text', true, 'class="full"')]; }
  function sectionFields(row) { return [selectField('program_id','Program',row.program_id || state.snapshot.programs[0]?.id || '', state.snapshot.programs.map((item) => [item.id, `${item.code} - ${item.name}`])), selectField('term_id','Term',row.term_id || state.snapshot.active_term_id, state.snapshot.terms.map((item) => [item.id, `${item.academic_year} - ${item.semester}`])), inputField('code','Section code',row.code || ''), inputField('year_level','Year level',row.year_level || 1, 'number', true, 'min="1" max="8"'), inputField('student_count','Student count',row.student_count || 30, 'number', true, 'min="1" max="5000"')]; }
  function offeringFields(row) { return [selectField('term_id','Term',row.term_id || state.snapshot.active_term_id, state.snapshot.terms.map((item) => [item.id, `${item.academic_year} - ${item.semester}`])), selectField('subject_id','Subject',row.subject_id || state.snapshot.subjects[0]?.id || '', state.snapshot.subjects.map((item) => [item.id, `${item.code} - ${item.name}`])), selectField('section_id','Section',row.section_id || state.snapshot.sections[0]?.id || '', state.snapshot.sections.map((item) => [item.id, item.code])), selectField('instructor_id','Instructor',row.instructor_id || state.snapshot.instructors[0]?.id || '', state.snapshot.instructors.map((item) => [item.id, item.name])), inputField('enrollment','Enrollment',row.enrollment || 30, 'number', true, 'min="1" max="5000"'), inputField('required_meetings','Meetings per week',row.required_meetings || 1, 'number', true, 'min="1" max="20"')]; }
  function userFields(row) { return [inputField('username','Username',row.username || ''), inputField('display_name','Display name',row.display_name || ''), inputField('email','Email',row.email || '', 'email', false), selectField('role','Role',row.role || 'student',[['admin','Administrator'],['scheduler','Scheduler'],['instructor','Instructor'],['student','Student']]), selectField('instructor_id','Faculty link',row.instructor_id || '', [['','Not linked'], ...state.snapshot.instructors.map((item) => [item.id,item.name])], false), selectField('section_id','Section link',row.section_id || '', [['','Not linked'], ...state.snapshot.sections.map((item) => [item.id,item.code])], false), inputField('password',row.id ? 'New password (leave blank to keep current)' : 'Temporary password','', 'password', !row.id, 'minlength="10" class="full"')]; }

  function openDataRecord(entity, id = 0) { const record = (state.snapshot[entity] || []).find((item) => Number(item.id) === Number(id)) || {}; const mode = id ? `edit-${entity}` : `create-${entity}`; const label = DATA_META[entity].singular; openModal(id ? `Edit ${label}` : `Add ${label}`, DATA_META[entity].fields, mode, record); }

  function closeModal() { const returnFocus = modalReturnFocus; $('#modalBackdrop').hidden = true; $('#modalBody').replaceChildren(); modalMode = null; modalRecord = null; modalReturnFocus = null; if (returnFocus?.isConnected) returnFocus.focus(); }

  async function submitModal(event) { event.preventDefault(); if (!modalMode) return; const entity = modalMode.replace(/^(create|edit)-/, ''); const formData = new FormData(event.currentTarget); const data = Object.fromEntries(formData.entries()); if (['rooms', 'subjects'].includes(entity)) data.features = text(data.features).split(',').map((item) => item.trim()).filter(Boolean); if (entity === 'subjects') data.required_features = text(formData.get('required_features')).split(',').map((item) => item.trim()).filter(Boolean); if (modalRecord?.id) data.id = Number(modalRecord.id); try { const result = await request('save_master', { method: 'POST', body: { entity, data } }); applySnapshot(result.snapshot); closeModal(); showToast('Saved', `${DATA_META[entity].singular} saved successfully.`); } catch (error) { showToast('Could not save', error.message, 'error'); } }

  function openScheduleEditor(entryId) { const row = schedules().find((item) => Number(item.id) === Number(entryId)); if (!row) return; const roomOptions = state.snapshot.rooms.map((item) => [item.id, `${item.code} - ${item.name}`]); const dayOptions = days(); const slotOptions = slots().map((item) => [item.id, item.label]); openModal(`Edit ${row.subject_code}`, () => [selectField('room_id','Room',row.room_id,roomOptions), selectField('day_of_week','Day',row.day_of_week,dayOptions), selectField('slot_id','Start time',row.slot_id,slotOptions)], 'edit-schedule', row); }

  async function submitScheduleEditor(event) { event.preventDefault(); const form = new FormData(event.currentTarget); try { const result = await request('save_schedule', { method: 'POST', body: { entry_id: Number(modalRecord.id), room_id: Number(form.get('room_id')), day_of_week: Number(form.get('day_of_week')), slot_id: Number(form.get('slot_id')) } }); applySnapshot(result.snapshot); closeModal(); showToast('Schedule updated', 'The entry passed hard-constraint validation.'); } catch (error) { showToast('Cannot update schedule', error.message, 'error'); } }

  async function cancelScheduleEntry(entryId) { if (!canManage() || !window.confirm('Cancel this class meeting? The published run will be marked incomplete until regenerated.')) return; try { const result = await request('delete_schedule', { method: 'POST', body: { entry_id: Number(entryId) } }); applySnapshot(result.snapshot); showToast('Class cancelled', 'The meeting was removed while the schedule history was preserved.', 'warn'); } catch (error) { showToast('Cannot cancel class', error.message, 'error'); } }

  async function generate() { const buttons = [$('#generateButton'), $('#dashboardGenerateButton')].filter(Boolean); buttons.forEach((button) => { button.disabled = true; button.textContent = 'Generating...'; }); try { const result = await request('generate', { method: 'POST', body: { term_id: state.snapshot.active_term_id } }); applySnapshot(result.snapshot); showToast('Schedule published', `${result.diagnostics.assigned_tasks} classes passed validation and were published.`); } catch (error) { const details = error.details || {}; const issue = details.preflight_issues?.[0] || details.explanations?.[0] || Object.keys(details.failures || {})[0]; const message = issue ? `${error.message} ${issue.replace(/^no_candidate:/, '')}` : error.message; showToast('Generation failed', message, 'error'); } finally { buttons.forEach((button) => { button.disabled = false; button.textContent = 'Generate schedule'; }); } }

  function exportSchedule() { window.location.href = 'api.php?action=export'; }

  async function deleteRecord(entity, id) { if (!canManage() || !window.confirm('Deactivate this record? Existing history will be preserved.')) return; try { const result = await request('save_master', { method: 'POST', body: { entity, id: Number(id), delete: true } }); applySnapshot(result.snapshot); showToast('Deactivated', 'The record is inactive and existing history was preserved.'); } catch (error) { showToast('Cannot deactivate record', error.message, 'error'); } }

  async function login(event) { event.preventDefault(); const username = text($('#loginUsername').value).toLowerCase(); const password = $('#loginPassword').value; const captcha = $('#loginCaptcha').value; $('#loginError').textContent = ''; try { const result = await request('login', { method: 'POST', body: { username, password, captcha } }); updateLoginChallenge(); applySnapshot(result); showToast('Welcome', `Signed in as ${result.user.display_name}.`); if (result.security_alert?.failed_attempts) showToast('Security notice', `${result.security_alert.failed_attempts} failed login attempt${result.security_alert.failed_attempts === 1 ? '' : 's'} were recorded for this account in the last 24 hours. Change your password if this was not you.`, 'warn'); } catch (error) { $('#loginError').textContent = error.message; updateLoginChallenge(error.details || {}); (error.details?.captcha_required ? $('#loginCaptcha') : $('#loginPassword')).focus(); } }
  async function logout() { try { await request('logout', { method: 'POST', body: {} }); } catch (error) { /* session may already be gone */ } state.snapshot = null; showLogin(); }

  async function saveSettings(event) { event.preventDefault(); try { const result = await request('save_settings', { method: 'POST', body: { academic_year: $('#academicYear').value, semester: $('#semester').value } }); applySnapshot(result.snapshot); showToast('Term saved', 'The active academic term was updated.'); } catch (error) { showToast('Cannot save term', error.message, 'error'); } }
  async function changePassword(event) { event.preventDefault(); try { const result = await request('change_password', { method: 'POST', body: { current_password: $('#currentPassword').value, new_password: $('#newPassword').value, confirm_password: $('#confirmPassword').value } }); $('#passwordForm').reset(); showToast('Password changed', result.message); } catch (error) { showToast('Cannot change password', error.message, 'error'); } }

  function closeSidebar() { $('#sidebar').classList.remove('open'); $('#sidebarBackdrop').classList.remove('active'); $('#menuButton').setAttribute('aria-expanded', 'false'); }
  function toggleSidebar() { const open = $('#sidebar').classList.toggle('open'); $('#sidebarBackdrop').classList.toggle('active', open); $('#menuButton').setAttribute('aria-expanded', String(open)); }

  function bindEvents() {
    $('#loginForm').addEventListener('submit', login); $('#registrationForm').addEventListener('submit', registerStudent); $('#showRegistrationButton').addEventListener('click', showRegistration); $('#backToLoginButton').addEventListener('click', () => showLogin()); $('#logoutButton').addEventListener('click', logout); $('#menuButton').addEventListener('click', toggleSidebar); $('#sidebarBackdrop').addEventListener('click', closeSidebar); $('#modalCloseButton').addEventListener('click', closeModal); $('#modalCancelButton').addEventListener('click', closeModal); $('#modalForm').addEventListener('submit', (event) => modalMode === 'edit-schedule' ? submitScheduleEditor(event) : submitModal(event)); $('#generateButton').addEventListener('click', generate); $('#dashboardGenerateButton').addEventListener('click', generate); $('#exportButton').addEventListener('click', exportSchedule); $('#reportExportButton').addEventListener('click', exportSchedule); $('#printButton').addEventListener('click', () => window.print()); $('#settingsForm').addEventListener('submit', saveSettings); $('#passwordForm').addEventListener('submit', changePassword);
    $('#globalSearch').addEventListener('input', (event) => { state.query = event.target.value; if (state.page === 'schedules') renderSchedules(); if (state.page === 'data') renderData(); }); $('#scheduleViewFilter').addEventListener('change', (event) => { state.scheduleView = event.target.value; state.scheduleFilter = 'all'; renderSchedules(); }); $('#scheduleFilterValue').addEventListener('change', (event) => { state.scheduleFilter = event.target.value; renderSchedules(); }); $('#addRecordButton').addEventListener('click', () => openDataRecord(state.dataTab));
    document.addEventListener('click', (event) => { const navigateButton = event.target.closest('[data-navigate]'); if (navigateButton) navigate(navigateButton.dataset.navigate); const dataTab = event.target.closest('[data-data-tab]'); if (dataTab) { state.dataTab = dataTab.dataset.dataTab; $$('.tab-button').forEach((button) => { const active = button.dataset.dataTab === state.dataTab; button.classList.toggle('active', active); button.setAttribute('aria-selected', String(active)); }); renderData(); } const review = event.target.closest('.review-registration'); if (review) reviewRegistration(Number(review.dataset.registrationId), review.dataset.decision); const edit = event.target.closest('.edit-record'); if (edit) openDataRecord(edit.dataset.entity, Number(edit.dataset.recordId)); const del = event.target.closest('.delete-record'); if (del) deleteRecord(del.dataset.entity, Number(del.dataset.recordId)); const editEntry = event.target.closest('.edit-entry'); if (editEntry) openScheduleEditor(Number(editEntry.dataset.entryId)); const cancelEntry = event.target.closest('.cancel-entry'); if (cancelEntry) cancelScheduleEntry(Number(cancelEntry.dataset.entryId)); });
    document.addEventListener('keydown', (event) => { if (event.key === 'Escape') { if (!$('#modalBackdrop').hidden) closeModal(); closeSidebar(); } });
    const parallax = $('#loginParallax');
    if (parallax && window.matchMedia('(prefers-reduced-motion: no-preference)').matches) {
      parallax.addEventListener('pointermove', (event) => {
        const rect = parallax.getBoundingClientRect();
        const x = ((event.clientX - rect.left) / rect.width - .5) * 16;
        const y = ((event.clientY - rect.top) / rect.height - .5) * 12;
        parallax.style.setProperty('--parallax-x', `${x}px`);
        parallax.style.setProperty('--parallax-y', `${y}px`);
      });
      parallax.addEventListener('pointerleave', () => { parallax.style.setProperty('--parallax-x', '0px'); parallax.style.setProperty('--parallax-y', '0px'); });
    }
  }

  async function start() { bindEvents(); window.setInterval(syncCloud, 15000); try { const result = await request('bootstrap'); if (!registrationOpen) applySnapshot(result); } catch (error) { if (error.status !== 401 && !registrationOpen) showLogin(error.message); else if (error.status === 401 && !registrationOpen) showLogin(); } }
  document.addEventListener('DOMContentLoaded', start);
})();
