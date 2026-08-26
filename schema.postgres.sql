CREATE TABLE IF NOT EXISTS academic_terms (
    id BIGSERIAL PRIMARY KEY,
    academic_year TEXT NOT NULL,
    semester TEXT NOT NULL CHECK (semester IN ('First Semester', 'Second Semester', 'Summer')),
    is_active INTEGER NOT NULL DEFAULT 1 CHECK (is_active IN (0, 1)),
    UNIQUE (academic_year, semester)
);
CREATE TABLE IF NOT EXISTS programs (
    id BIGSERIAL PRIMARY KEY,
    code TEXT NOT NULL UNIQUE,
    name TEXT NOT NULL,
    active INTEGER NOT NULL DEFAULT 1 CHECK (active IN (0, 1))
);
CREATE TABLE IF NOT EXISTS instructors (
    id BIGSERIAL PRIMARY KEY,
    employee_no TEXT NOT NULL UNIQUE,
    name TEXT NOT NULL,
    email TEXT,
    max_hours_day INTEGER NOT NULL DEFAULT 6 CHECK (max_hours_day BETWEEN 1 AND 16),
    active INTEGER NOT NULL DEFAULT 1 CHECK (active IN (0, 1))
);
CREATE TABLE IF NOT EXISTS rooms (
    id BIGSERIAL PRIMARY KEY,
    code TEXT NOT NULL UNIQUE,
    name TEXT NOT NULL,
    capacity INTEGER NOT NULL CHECK (capacity BETWEEN 1 AND 5000),
    room_type TEXT NOT NULL CHECK (room_type IN ('LECTURE', 'LAB', 'SPECIAL')),
    features_json TEXT NOT NULL DEFAULT '[]',
    active INTEGER NOT NULL DEFAULT 1 CHECK (active IN (0, 1))
);
CREATE TABLE IF NOT EXISTS subjects (
    id BIGSERIAL PRIMARY KEY,
    code TEXT NOT NULL UNIQUE,
    name TEXT NOT NULL,
    units INTEGER NOT NULL DEFAULT 3 CHECK (units BETWEEN 1 AND 12),
    hours_per_week INTEGER NOT NULL DEFAULT 2 CHECK (hours_per_week BETWEEN 1 AND 40),
    duration_slots INTEGER NOT NULL DEFAULT 1 CHECK (duration_slots BETWEEN 1 AND 8),
    room_type TEXT NOT NULL DEFAULT 'LECTURE' CHECK (room_type IN ('LECTURE', 'LAB', 'SPECIAL')),
    required_features_json TEXT NOT NULL DEFAULT '[]',
    active INTEGER NOT NULL DEFAULT 1 CHECK (active IN (0, 1))
);
CREATE TABLE IF NOT EXISTS sections (
    id BIGSERIAL PRIMARY KEY,
    program_id BIGINT NOT NULL REFERENCES programs(id) ON DELETE RESTRICT,
    term_id BIGINT NOT NULL REFERENCES academic_terms(id) ON DELETE RESTRICT,
    code TEXT NOT NULL,
    year_level INTEGER NOT NULL CHECK (year_level BETWEEN 1 AND 8),
    student_count INTEGER NOT NULL CHECK (student_count BETWEEN 1 AND 5000),
    active INTEGER NOT NULL DEFAULT 1 CHECK (active IN (0, 1)),
    UNIQUE (term_id, code)
);
CREATE TABLE IF NOT EXISTS users (
    id BIGSERIAL PRIMARY KEY,
    username TEXT NOT NULL UNIQUE,
    password_hash TEXT NOT NULL,
    display_name TEXT NOT NULL,
    email TEXT,
    role TEXT NOT NULL CHECK (role IN ('admin', 'scheduler', 'instructor', 'student')),
    instructor_id BIGINT REFERENCES instructors(id) ON DELETE SET NULL,
    section_id BIGINT REFERENCES sections(id) ON DELETE SET NULL,
    active INTEGER NOT NULL DEFAULT 1 CHECK (active IN (0, 1)),
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE IF NOT EXISTS pending_registrations (
    id BIGSERIAL PRIMARY KEY,
    username TEXT NOT NULL UNIQUE,
    display_name TEXT NOT NULL,
    email TEXT,
    enrollment_ref TEXT NOT NULL,
    program_id BIGINT NOT NULL REFERENCES programs(id) ON DELETE RESTRICT,
    year_level INTEGER NOT NULL CHECK (year_level BETWEEN 1 AND 8),
    section_id BIGINT REFERENCES sections(id) ON DELETE SET NULL,
    password_hash TEXT NOT NULL,
    status TEXT NOT NULL DEFAULT 'PENDING' CHECK (status IN ('PENDING', 'APPROVED', 'REJECTED')),
    review_note TEXT,
    reviewed_by BIGINT REFERENCES users(id) ON DELETE SET NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    reviewed_at TIMESTAMP
);
CREATE TABLE IF NOT EXISTS time_slots (
    id BIGSERIAL PRIMARY KEY,
    code TEXT NOT NULL UNIQUE,
    label TEXT NOT NULL,
    start_time TEXT NOT NULL,
    end_time TEXT NOT NULL,
    slot_order INTEGER NOT NULL UNIQUE,
    CHECK (start_time < end_time)
);
CREATE TABLE IF NOT EXISTS instructor_availability (
    id BIGSERIAL PRIMARY KEY,
    instructor_id BIGINT NOT NULL REFERENCES instructors(id) ON DELETE CASCADE,
    day_of_week INTEGER NOT NULL CHECK (day_of_week BETWEEN 1 AND 7),
    slot_id BIGINT NOT NULL REFERENCES time_slots(id) ON DELETE CASCADE,
    available INTEGER NOT NULL DEFAULT 1 CHECK (available IN (0, 1)),
    UNIQUE (instructor_id, day_of_week, slot_id)
);
CREATE TABLE IF NOT EXISTS room_availability (
    id BIGSERIAL PRIMARY KEY,
    room_id BIGINT NOT NULL REFERENCES rooms(id) ON DELETE CASCADE,
    day_of_week INTEGER NOT NULL CHECK (day_of_week BETWEEN 1 AND 7),
    slot_id BIGINT NOT NULL REFERENCES time_slots(id) ON DELETE CASCADE,
    available INTEGER NOT NULL DEFAULT 1 CHECK (available IN (0, 1)),
    UNIQUE (room_id, day_of_week, slot_id)
);
CREATE TABLE IF NOT EXISTS section_availability (
    id BIGSERIAL PRIMARY KEY,
    section_id BIGINT NOT NULL REFERENCES sections(id) ON DELETE CASCADE,
    day_of_week INTEGER NOT NULL CHECK (day_of_week BETWEEN 1 AND 7),
    slot_id BIGINT NOT NULL REFERENCES time_slots(id) ON DELETE CASCADE,
    available INTEGER NOT NULL DEFAULT 1 CHECK (available IN (0, 1)),
    UNIQUE (section_id, day_of_week, slot_id)
);
CREATE TABLE IF NOT EXISTS instructor_time_preferences (
    id BIGSERIAL PRIMARY KEY,
    instructor_id BIGINT NOT NULL REFERENCES instructors(id) ON DELETE CASCADE,
    day_of_week INTEGER NOT NULL CHECK (day_of_week BETWEEN 1 AND 7),
    slot_id BIGINT NOT NULL REFERENCES time_slots(id) ON DELETE CASCADE,
    preference INTEGER NOT NULL DEFAULT 0 CHECK (preference BETWEEN -2 AND 2),
    UNIQUE (instructor_id, day_of_week, slot_id)
);
CREATE TABLE IF NOT EXISTS course_offerings (
    id BIGSERIAL PRIMARY KEY,
    term_id BIGINT NOT NULL REFERENCES academic_terms(id) ON DELETE RESTRICT,
    subject_id BIGINT NOT NULL REFERENCES subjects(id) ON DELETE RESTRICT,
    section_id BIGINT NOT NULL REFERENCES sections(id) ON DELETE RESTRICT,
    instructor_id BIGINT NOT NULL REFERENCES instructors(id) ON DELETE RESTRICT,
    enrollment INTEGER NOT NULL CHECK (enrollment BETWEEN 1 AND 5000),
    required_meetings INTEGER NOT NULL DEFAULT 1 CHECK (required_meetings BETWEEN 1 AND 20),
    status TEXT NOT NULL DEFAULT 'ACTIVE' CHECK (status IN ('ACTIVE', 'INACTIVE')),
    UNIQUE (term_id, subject_id, section_id)
);
CREATE TABLE IF NOT EXISTS schedule_runs (
    id BIGSERIAL PRIMARY KEY,
    term_id BIGINT NOT NULL REFERENCES academic_terms(id) ON DELETE RESTRICT,
    created_by BIGINT NOT NULL REFERENCES users(id) ON DELETE RESTRICT,
    status TEXT NOT NULL CHECK (status IN ('RUNNING', 'PUBLISHED', 'FAILED', 'ARCHIVED')),
    total_tasks INTEGER NOT NULL DEFAULT 0,
    assigned_tasks INTEGER NOT NULL DEFAULT 0,
    diagnostics_json TEXT NOT NULL DEFAULT '{}',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE IF NOT EXISTS schedule_entries (
    id BIGSERIAL PRIMARY KEY,
    run_id BIGINT NOT NULL REFERENCES schedule_runs(id) ON DELETE CASCADE,
    offering_id BIGINT NOT NULL REFERENCES course_offerings(id) ON DELETE RESTRICT,
    meeting_no INTEGER NOT NULL CHECK (meeting_no > 0),
    room_id BIGINT NOT NULL REFERENCES rooms(id) ON DELETE RESTRICT,
    day_of_week INTEGER NOT NULL CHECK (day_of_week BETWEEN 1 AND 7),
    slot_id BIGINT NOT NULL REFERENCES time_slots(id) ON DELETE RESTRICT,
    status TEXT NOT NULL DEFAULT 'PUBLISHED' CHECK (status IN ('PUBLISHED', 'CANCELLED')),
    UNIQUE (run_id, offering_id, meeting_no)
);
CREATE TABLE IF NOT EXISTS schedule_occupancy (
    id BIGSERIAL PRIMARY KEY,
    run_id BIGINT NOT NULL REFERENCES schedule_runs(id) ON DELETE CASCADE,
    entry_id BIGINT NOT NULL REFERENCES schedule_entries(id) ON DELETE CASCADE,
    resource_type TEXT NOT NULL CHECK (resource_type IN ('ROOM', 'INSTRUCTOR', 'SECTION')),
    resource_id BIGINT NOT NULL,
    day_of_week INTEGER NOT NULL CHECK (day_of_week BETWEEN 1 AND 7),
    slot_id BIGINT NOT NULL REFERENCES time_slots(id) ON DELETE RESTRICT,
    UNIQUE (run_id, resource_type, resource_id, day_of_week, slot_id)
);
CREATE TABLE IF NOT EXISTS audit_logs (
    id BIGSERIAL PRIMARY KEY,
    user_id BIGINT REFERENCES users(id) ON DELETE SET NULL,
    action TEXT NOT NULL,
    entity_type TEXT,
    entity_id BIGINT,
    details_json TEXT NOT NULL DEFAULT '{}',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE IF NOT EXISTS system_settings (setting_key TEXT PRIMARY KEY, setting_value TEXT NOT NULL);
CREATE TABLE IF NOT EXISTS login_throttles (throttle_key TEXT PRIMARY KEY, attempts INTEGER NOT NULL DEFAULT 0 CHECK (attempts >= 0), window_started_at BIGINT NOT NULL, locked_until BIGINT NOT NULL DEFAULT 0);
CREATE INDEX IF NOT EXISTS idx_sections_term ON sections(term_id);
CREATE INDEX IF NOT EXISTS idx_offerings_term ON course_offerings(term_id, status);
CREATE INDEX IF NOT EXISTS idx_offerings_section ON course_offerings(section_id);
CREATE INDEX IF NOT EXISTS idx_offerings_instructor ON course_offerings(instructor_id);
CREATE INDEX IF NOT EXISTS idx_pending_registrations_status ON pending_registrations(status, created_at);
CREATE INDEX IF NOT EXISTS idx_schedule_runs_term_status ON schedule_runs(term_id, status);
CREATE INDEX IF NOT EXISTS idx_schedule_entries_run ON schedule_entries(run_id);
CREATE INDEX IF NOT EXISTS idx_occupancy_run_resource ON schedule_occupancy(run_id, resource_type, resource_id);
CREATE UNIQUE INDEX IF NOT EXISTS uq_published_run_per_term ON schedule_runs(term_id) WHERE status = 'PUBLISHED';
