<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'security.php';
easysched_start_session();
easysched_send_security_headers();
?><!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description" content="New Sinai School and Colleges Sta. Rosa, Inc. automated class scheduling system">
    <title>EasySched | New Sinai School and Colleges Sta. Rosa, Inc.</title>
    <link rel="stylesheet" href="styles.css?v=20260822-premium">
</head>
<body>
    <a class="skip-link" href="#mainContent">Skip to content</a>

    <section class="login-shell" id="loginView" aria-labelledby="loginTitle">
        <div class="login-panel">
            <div class="brand-lockup brand-lockup-dark">
                <img class="brand-logo" src="assets/school-logo.png" alt="New Sinai School and Colleges Sta. Rosa, Inc. seal">
                <div><strong>EasySched</strong><span>New Sinai School and Colleges Sta. Rosa, Inc.</span></div>
            </div>
            <p class="eyebrow">Academic scheduling workspace</p>
            <h1 id="loginTitle">Sign in to manage schedules</h1>
            <p class="muted">Secure server sessions, SQLite persistence, and conflict-aware scheduling.</p>
            <form id="loginForm" novalidate>
                <div class="field">
                    <label for="loginUsername">Username</label>
                    <input id="loginUsername" name="username" autocomplete="username" required maxlength="80">
                </div>
                <div class="field">
                    <label for="loginPassword">Password</label>
                    <input id="loginPassword" name="password" type="password" autocomplete="current-password" required maxlength="200">
                </div>
                <div class="field" id="loginCaptchaWrap" hidden>
                    <label for="loginCaptcha" id="loginCaptchaLabel">Security check</label>
                    <input id="loginCaptcha" name="captcha" inputmode="numeric" autocomplete="off" maxlength="8">
                </div>
                <p class="form-error" id="loginError" role="alert"></p>
                <button class="button button-primary button-wide" type="submit">Sign in</button>
            </form>
            <button class="button button-ghost button-wide" id="forgotPasswordButton" type="button">Forgot password?</button>
            <button class="button button-ghost button-wide" id="showRegistrationButton" type="button">New student? Request an account</button>
            <p class="login-note">Student requests require administrator approval before sign-in.</p>
        </div>
        <div class="login-panel registration-panel" id="registrationView" hidden>
            <div class="brand-lockup brand-lockup-dark"><div><strong>Student registration</strong><span>Request access to EasySched</span></div></div>
            <p class="eyebrow">Enrollment access request</p>
            <h1>Request a student account</h1>
            <form id="registrationForm" novalidate>
                <div class="field"><label for="registrationName">Full name</label><input id="registrationName" required maxlength="160"></div>
                <div class="field"><label for="registrationUsername">Preferred username</label><input id="registrationUsername" required maxlength="80"></div>
                <div class="field"><label for="registrationRef">Enrollment reference</label><input id="registrationRef" required maxlength="60"></div>
                <div class="field"><label for="registrationEmail">Email</label><input id="registrationEmail" type="email" maxlength="180"></div>
                <div class="field"><label for="registrationOtp">Email verification code</label><input id="registrationOtp" inputmode="numeric" maxlength="6" placeholder="Click Send code first"></div>
                <button class="button button-ghost button-wide" id="sendRegistrationOtpButton" type="button">Send email code</button>
                <div class="field"><label for="registrationProgram">Program</label><select id="registrationProgram" required><option value="">Loading programs...</option></select></div>
                <div class="field"><label for="registrationYear">Year level</label><input id="registrationYear" type="number" min="1" max="8" required></div>
                <div class="field"><label for="registrationSection">Section (optional)</label><select id="registrationSection"><option value="">No section assigned yet</option></select></div>
                <div class="field"><label for="registrationPassword">Password</label><input id="registrationPassword" type="password" minlength="10" required></div>
                <p class="form-error" id="registrationError" role="alert"></p>
                <button class="button button-primary button-wide" type="submit">Submit registration</button>
                <button class="button button-ghost button-wide" id="backToLoginButton" type="button">Back to sign in</button>
            </form>
        </div>
        <div class="login-panel registration-panel" id="forgotPasswordView" hidden>
            <div class="brand-lockup brand-lockup-dark"><div><strong>Password recovery</strong><span>Verify your email to reset access</span></div></div>
            <p class="eyebrow">Account recovery</p><h1>Reset your password</h1>
            <form id="forgotPasswordForm" novalidate>
                <div class="field"><label for="resetAccount">Username or email</label><input id="resetAccount" required maxlength="180"></div>
                <button class="button button-ghost button-wide" id="sendResetOtpButton" type="button">Send reset code</button>
                <div class="field"><label for="resetOtp">Verification code</label><input id="resetOtp" inputmode="numeric" maxlength="6"></div>
                <div class="field"><label for="resetPassword">New password</label><input id="resetPassword" type="password" minlength="10" required></div>
                <div class="field"><label for="resetPasswordConfirm">Confirm new password</label><input id="resetPasswordConfirm" type="password" minlength="10" required></div>
                <p class="form-error" id="resetError" role="alert"></p><button class="button button-primary button-wide" type="submit">Reset password</button><button class="button button-ghost button-wide" id="backFromResetButton" type="button">Back to sign in</button>
            </form>
        </div>
        <div class="login-aside" id="loginParallax" aria-label="New Sinai campus">
            <div class="campus-frame" aria-hidden="true"><div class="campus-photo"></div></div>
            <div class="login-aside-content">
                <div class="aside-kicker">New Sinai School and Colleges Sta. Rosa, Inc.</div>
                <h2>Smarter schedules for a stronger academic community.</h2>
                <p>Conflict-aware scheduling built for the people, classrooms, and learning spaces of New Sinai.</p>
                <div class="aside-points">
                    <div><strong>Conflict-free by design</strong><span>Rooms, faculty, sections, and time slots are validated before publication.</span></div>
                    <div><strong>Reliable anywhere</strong><span>Works locally and backs up approved changes to the cloud.</span></div>
                </div>
            </div>
        </div>
    </section>

    <div class="app-shell" id="appView" hidden>
        <div class="sidebar-backdrop" id="sidebarBackdrop"></div>
        <aside class="sidebar" id="sidebar" aria-label="Primary navigation">
            <div class="brand-lockup">
                <img class="brand-logo" src="assets/school-logo.png" alt="New Sinai School and Colleges Sta. Rosa, Inc. seal">
                <div><strong>EasySched</strong><span>New Sinai School and Colleges Sta. Rosa, Inc.</span></div>
            </div>
            <div class="user-card">
                <div class="avatar" id="userAvatar">A</div>
                <div><strong id="userName">User</strong><span id="userRole">Role</span></div>
            </div>
            <nav class="nav-list" id="navList" aria-label="Application pages"></nav>
            <div class="sidebar-footer"><span class="status-dot" id="cloudStatusDot"></span><span id="cloudStatusText">Local SQLite</span></div>
        </aside>

        <main class="main-shell" id="mainContent">
            <header class="topbar">
                <div class="topbar-left">
                    <button class="icon-button menu-button" id="menuButton" type="button" aria-label="Open navigation" aria-expanded="false">Menu</button>
                    <div><p class="eyebrow" id="pageEyebrow">Overview</p><h1 id="pageTitle">Dashboard</h1></div>
                </div>
                <div class="topbar-actions">
                    <label class="search-field" for="globalSearch"><span aria-hidden="true">Search</span><input id="globalSearch" type="search" placeholder="Search this view" autocomplete="off"></label>
                    <button class="button button-ghost" id="logoutButton" type="button">Sign out</button>
                </div>
            </header>

            <div class="page-container">
                <section class="page" id="page-dashboard" data-page="dashboard" aria-labelledby="dashboardHeading">
                    <div class="page-heading"><div><p class="eyebrow">Command center</p><h2 id="dashboardHeading">Scheduling overview</h2><p class="muted">A live view of the active academic term.</p></div><div class="page-heading-actions"><button class="button button-primary manage-only" id="dashboardGenerateButton" type="button">Generate schedule</button></div></div>
                    <div class="metric-grid" id="metricGrid"></div>
                    <div class="dashboard-grid">
                        <article class="panel panel-large"><div class="panel-heading"><div><p class="eyebrow">Latest publication</p><h3>Schedule health</h3></div><span class="badge" id="scheduleHealthBadge">No schedule</span></div><div id="dashboardHealth" class="health-list"></div></article>
                        <article class="panel"><div class="panel-heading"><div><p class="eyebrow">At a glance</p><h3>Generation record</h3></div></div><div id="runSummary" class="summary-list"></div></article>
                    </div>
                    <article class="panel"><div class="panel-heading"><div><p class="eyebrow">Next on the calendar</p><h3>Upcoming classes</h3></div><button class="button button-ghost" data-navigate="schedules" type="button">Open schedule</button></div><div class="table-wrap"><table class="data-table compact"><thead><tr><th>Time</th><th>Subject</th><th>Section</th><th>Room</th><th>Instructor</th></tr></thead><tbody id="upcomingBody"></tbody></table></div></article>
                </section>

                <section class="page" id="page-schedules" data-page="schedules" aria-labelledby="schedulesHeading" hidden>
                    <div class="page-heading"><div><p class="eyebrow">Published timetable</p><h2 id="schedulesHeading">Weekly schedule</h2><p class="muted">Filter by the audience you need to answer for.</p></div><div class="page-heading-actions"><button class="button button-ghost" id="printButton" type="button">Print</button><button class="button button-secondary" id="exportButton" type="button">Export CSV</button><button class="button button-primary manage-only" id="generateButton" type="button">Generate schedule</button></div></div>
                    <div class="filter-bar"><label class="field-inline">View<select id="scheduleViewFilter"><option value="all">All classes</option><option value="section">By section</option><option value="instructor">By instructor</option><option value="room">By room</option></select></label><label class="field-inline" id="scheduleFilterValueWrap">Filter<select id="scheduleFilterValue"><option value="all">All</option></select></label><span class="filter-spacer"></span><span class="legend"><span class="legend-dot lecture"></span>Lecture <span class="legend-dot lab"></span>Laboratory</span></div>
                    <div class="calendar-panel"><div class="calendar-grid" id="calendarGrid"></div></div>
                    <article class="panel schedule-table-panel"><div class="panel-heading"><div><p class="eyebrow">Detailed list</p><h3 id="scheduleCountLabel">0 classes</h3></div><span class="badge badge-neutral" id="conflictBadge">Validated</span></div><div class="table-wrap"><table class="data-table"><thead><tr><th>Day</th><th>Time</th><th>Subject</th><th>Section</th><th>Instructor</th><th>Room</th><th class="manage-column">Action</th></tr></thead><tbody id="scheduleTableBody"></tbody></table></div></article>
                </section>

                <section class="page" id="page-data" data-page="data" aria-labelledby="dataHeading" hidden>
                    <div class="page-heading"><div><p class="eyebrow">Master data</p><h2 id="dataHeading">Academic setup</h2><p class="muted">Maintain the resources used by the scheduler.</p></div></div>
                    <div class="data-tabs" role="tablist" aria-label="Master data types"><button class="tab-button active" role="tab" aria-selected="true" data-data-tab="rooms" type="button">Rooms</button><button class="tab-button" role="tab" aria-selected="false" data-data-tab="instructors" type="button">Faculty</button><button class="tab-button" role="tab" aria-selected="false" data-data-tab="subjects" type="button">Subjects</button><button class="tab-button" role="tab" aria-selected="false" data-data-tab="programs" type="button">Programs</button><button class="tab-button" role="tab" aria-selected="false" data-data-tab="sections" type="button">Sections</button><button class="tab-button" role="tab" aria-selected="false" data-data-tab="offerings" type="button">Offerings</button><button class="tab-button admin-only" role="tab" aria-selected="false" data-data-tab="users" type="button">Users</button></div>
                    <article class="panel"><div class="panel-heading"><div><p class="eyebrow">Current term records</p><h3 id="dataTabTitle">Rooms</h3></div><button class="button button-primary manage-only" id="addRecordButton" type="button">Add record</button></div><div class="table-wrap"><table class="data-table"><thead id="dataTableHead"></thead><tbody id="dataTableBody"></tbody></table></div></article>
                    <article class="panel admin-only" id="registrationReviewPanel" hidden><div class="panel-heading"><div><p class="eyebrow">Enrollment review</p><h3>Pending student registrations</h3></div><span class="badge badge-warning" id="pendingRegistrationCount">0 pending</span></div><div class="table-wrap"><table class="data-table"><thead><tr><th>Name</th><th>Username</th><th>Enrollment ref.</th><th>Program</th><th>Year</th><th>Section</th><th>Action</th></tr></thead><tbody id="pendingRegistrationBody"></tbody></table></div></article>
                </section>

                <section class="page" id="page-reports" data-page="reports" aria-labelledby="reportsHeading" hidden>
                    <div class="page-heading"><div><p class="eyebrow">Evidence and review</p><h2 id="reportsHeading">Reports</h2><p class="muted">Use these summaries during review and defense presentation.</p></div><button class="button button-secondary" id="reportExportButton" type="button">Export current schedule</button></div>
                    <div class="metric-grid" id="reportMetricGrid"></div>
                    <div class="report-grid"><article class="panel"><div class="panel-heading"><div><p class="eyebrow">Constraint validation</p><h3>Hard constraints</h3></div></div><div id="constraintReport" class="constraint-list"></div></article><article class="panel"><div class="panel-heading"><div><p class="eyebrow">Capacity planning</p><h3>Room utilization</h3></div></div><div id="roomReport" class="bar-list"></div></article></div>
                </section>

                <section class="page" id="page-settings" data-page="settings" aria-labelledby="settingsHeading" hidden>
                    <div class="page-heading"><div><p class="eyebrow">System controls</p><h2 id="settingsHeading">Settings</h2><p class="muted">Term configuration and account security.</p></div></div>
                    <div class="settings-grid"><article class="panel manage-only"><div class="panel-heading"><div><p class="eyebrow">Academic period</p><h3>Active term</h3></div></div><form id="settingsForm"><div class="field"><label for="academicYear">Academic year</label><input id="academicYear" pattern="20[0-9]{2}-20[0-9]{2}" required></div><div class="field"><label for="semester">Semester</label><select id="semester"><option>First Semester</option><option>Second Semester</option><option>Summer</option></select></div><button class="button button-primary" type="submit">Save term</button></form></article><article class="panel"><div class="panel-heading"><div><p class="eyebrow">Account</p><h3>Change password</h3></div></div><form id="passwordForm"><div class="field"><label for="currentPassword">Current password</label><input id="currentPassword" type="password" required></div><div class="field"><label for="newPassword">New password</label><input id="newPassword" type="password" minlength="10" required></div><div class="field"><label for="confirmPassword">Confirm new password</label><input id="confirmPassword" type="password" minlength="10" required></div><button class="button button-secondary" type="submit">Change password</button></form></article></div>
                    <article class="panel security-note"><div><p class="eyebrow">Deployment boundary</p><h3>Defense build security posture</h3><p>This build uses server-side sessions, password hashes, CSRF tokens, prepared SQLite queries, role checks, audit logs, and a restrictive content security policy. Use HTTPS and a production database before handling real student records.</p></div></article>
                </section>
            </div>
        </main>
    </div>

    <div class="modal-backdrop" id="modalBackdrop" hidden><section class="modal" id="modal" role="dialog" aria-modal="true" aria-labelledby="modalTitle"><div class="modal-header"><div><p class="eyebrow" id="modalEyebrow">Record</p><h2 id="modalTitle">Edit record</h2></div><button class="icon-button" id="modalCloseButton" type="button" aria-label="Close dialog">Close</button></div><form id="modalForm"><div class="modal-body" id="modalBody"></div><div class="modal-footer"><button class="button button-ghost" id="modalCancelButton" type="button">Cancel</button><button class="button button-primary" type="submit">Save</button></div></form></section></div>
    <div class="toast-stack" id="toastStack" aria-live="polite" aria-atomic="true"></div>
    <div class="sr-only" id="liveRegion" aria-live="polite"></div>
    <script src="script.js?v=20260822-premium" defer></script>
</body>
</html>
