PRAGMA foreign_keys = ON;

CREATE TABLE IF NOT EXISTS academic_terms (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    academic_year TEXT NOT NULL,
    semester TEXT NOT NULL CHECK (semester IN ('First Semester', 'Second Semester', 'Summer')),
    is_active INTEGER NOT NULL DEFAULT 1 CHECK (is_active IN (0, 1)),
    UNIQUE (academic_year, semester)
);

CREATE TABLE IF NOT EXISTS programs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    code TEXT NOT NULL UNIQUE COLLATE NOCASE,
    name TEXT NOT NULL,
    active INTEGER NOT NULL DEFAULT 1 CHECK (active IN (0, 1))
);

CREATE TABLE IF NOT EXISTS instructors (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    employee_no TEXT NOT NULL UNIQUE COLLATE NOCASE,
    name TEXT NOT NULL,
    email TEXT,
    max_hours_day INTEGER NOT NULL DEFAULT 6 CHECK (max_hours_day BETWEEN 1 AND 16),
    active INTEGER NOT NULL DEFAULT 1 CHECK (active IN (0, 1))
);

CREATE TABLE IF NOT EXISTS rooms (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    code TEXT NOT NULL UNIQUE COLLATE NOCASE,
    name TEXT NOT NULL,
    capacity INTEGER NOT NULL CHECK (capacity > 0 AND capacity <= 5000),
    room_type TEXT NOT NULL CHECK (room_type IN ('LECTURE', 'LAB', 'SPECIAL')),
    features_json TEXT NOT NULL DEFAULT '[]',
    active INTEGER NOT NULL DEFAULT 1 CHECK (active IN (0, 1))
);

CREATE TABLE IF NOT EXISTS subjects (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    code TEXT NOT NULL UNIQUE COLLATE NOCASE,
    name TEXT NOT NULL,
    units INTEGER NOT NULL DEFAULT 3 CHECK (units BETWEEN 1 AND 12),
    hours_per_week INTEGER NOT NULL DEFAULT 2 CHECK (hours_per_week BETWEEN 1 AND 40),
    duration_slots INTEGER NOT NULL DEFAULT 1 CHECK (duration_slots BETWEEN 1 AND 8),
    room_type TEXT NOT NULL DEFAULT 'LECTURE' CHECK (room_type IN ('LECTURE', 'LAB', 'SPECIAL')),
    required_features_json TEXT NOT NULL DEFAULT '[]',
    active INTEGER NOT NULL DEFAULT 1 CHECK (active IN (0, 1))
);

CREATE TABLE IF NOT EXISTS sections (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    program_id INTEGER NOT NULL REFERENCES programs(id) ON DELETE RESTRICT,
    term_id INTEGER NOT NULL REFERENCES academic_terms(id) ON DELETE RESTRICT,
    code TEXT NOT NULL,
    year_level INTEGER NOT NULL CHECK (year_level BETWEEN 1 AND 8),
    student_count INTEGER NOT NULL CHECK (student_count > 0 AND student_count <= 5000),
    active INTEGER NOT NULL DEFAULT 1 CHECK (active IN (0, 1)),
    UNIQUE (term_id, code)
);

CREATE TABLE IF NOT EXISTS users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    username TEXT NOT NULL UNIQUE COLLATE NOCASE,
    password_hash TEXT NOT NULL,
    display_name TEXT NOT NULL,
    email TEXT,
    role TEXT NOT NULL CHECK (role IN ('admin', 'scheduler', 'instructor', 'student')),
    instructor_id INTEGER REFERENCES instructors(id) ON DELETE SET NULL,
    section_id INTEGER REFERENCES sections(id) ON DELETE SET NULL,
    active INTEGER NOT NULL DEFAULT 1 CHECK (active IN (0, 1)),
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS pending_registrations (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    username TEXT NOT NULL UNIQUE COLLATE NOCASE,
    display_name TEXT NOT NULL,
    email TEXT,
    enrollment_ref TEXT NOT NULL,
    program_id INTEGER NOT NULL REFERENCES programs(id) ON DELETE RESTRICT,
    year_level INTEGER NOT NULL CHECK (year_level BETWEEN 1 AND 8),
    section_id INTEGER REFERENCES sections(id) ON DELETE SET NULL,
    password_hash TEXT NOT NULL,
    status TEXT NOT NULL DEFAULT 'PENDING' CHECK (status IN ('PENDING', 'APPROVED', 'REJECTED')),
    review_note TEXT,
    reviewed_by INTEGER REFERENCES users(id) ON DELETE SET NULL,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    reviewed_at TEXT
);

CREATE TABLE IF NOT EXISTS time_slots (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    code TEXT NOT NULL UNIQUE COLLATE NOCASE,
    label TEXT NOT NULL,
    start_time TEXT NOT NULL,
    end_time TEXT NOT NULL,
    slot_order INTEGER NOT NULL UNIQUE,
    CHECK (start_time < end_time)
);

CREATE TABLE IF NOT EXISTS instructor_availability (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    instructor_id INTEGER NOT NULL REFERENCES instructors(id) ON DELETE CASCADE,
    day_of_week INTEGER NOT NULL CHECK (day_of_week BETWEEN 1 AND 7),
    slot_id INTEGER NOT NULL REFERENCES time_slots(id) ON DELETE CASCADE,
    available INTEGER NOT NULL DEFAULT 1 CHECK (available IN (0, 1)),
    UNIQUE (instructor_id, day_of_week, slot_id)
);

CREATE TABLE IF NOT EXISTS room_availability (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    room_id INTEGER NOT NULL REFERENCES rooms(id) ON DELETE CASCADE,
    day_of_week INTEGER NOT NULL CHECK (day_of_week BETWEEN 1 AND 7),
    slot_id INTEGER NOT NULL REFERENCES time_slots(id) ON DELETE CASCADE,
    available INTEGER NOT NULL DEFAULT 1 CHECK (available IN (0, 1)),
    UNIQUE (room_id, day_of_week, slot_id)
);

CREATE TABLE IF NOT EXISTS section_availability (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    section_id INTEGER NOT NULL REFERENCES sections(id) ON DELETE CASCADE,
    day_of_week INTEGER NOT NULL CHECK (day_of_week BETWEEN 1 AND 7),
    slot_id INTEGER NOT NULL REFERENCES time_slots(id) ON DELETE CASCADE,
    available INTEGER NOT NULL DEFAULT 1 CHECK (available IN (0, 1)),
    UNIQUE (section_id, day_of_week, slot_id)
);

CREATE TABLE IF NOT EXISTS instructor_time_preferences (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    instructor_id INTEGER NOT NULL REFERENCES instructors(id) ON DELETE CASCADE,
    day_of_week INTEGER NOT NULL CHECK (day_of_week BETWEEN 1 AND 7),
    slot_id INTEGER NOT NULL REFERENCES time_slots(id) ON DELETE CASCADE,
    preference INTEGER NOT NULL DEFAULT 0 CHECK (preference BETWEEN -2 AND 2),
    UNIQUE (instructor_id, day_of_week, slot_id)
);

CREATE TABLE IF NOT EXISTS course_offerings (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    term_id INTEGER NOT NULL REFERENCES academic_terms(id) ON DELETE RESTRICT,
    subject_id INTEGER NOT NULL REFERENCES subjects(id) ON DELETE RESTRICT,
    section_id INTEGER NOT NULL REFERENCES sections(id) ON DELETE RESTRICT,
    instructor_id INTEGER NOT NULL REFERENCES instructors(id) ON DELETE RESTRICT,
    enrollment INTEGER NOT NULL CHECK (enrollment > 0 AND enrollment <= 5000),
    required_meetings INTEGER NOT NULL DEFAULT 1 CHECK (required_meetings BETWEEN 1 AND 20),
    status TEXT NOT NULL DEFAULT 'ACTIVE' CHECK (status IN ('ACTIVE', 'INACTIVE')),
    UNIQUE (term_id, subject_id, section_id)
);

CREATE TABLE IF NOT EXISTS schedule_runs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    term_id INTEGER NOT NULL REFERENCES academic_terms(id) ON DELETE RESTRICT,
    created_by INTEGER NOT NULL REFERENCES users(id) ON DELETE RESTRICT,
    status TEXT NOT NULL CHECK (status IN ('RUNNING', 'PUBLISHED', 'FAILED', 'ARCHIVED')),
    total_tasks INTEGER NOT NULL DEFAULT 0,
    assigned_tasks INTEGER NOT NULL DEFAULT 0,
    diagnostics_json TEXT NOT NULL DEFAULT '{}',
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS schedule_entries (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    run_id INTEGER NOT NULL REFERENCES schedule_runs(id) ON DELETE CASCADE,
    offering_id INTEGER NOT NULL REFERENCES course_offerings(id) ON DELETE RESTRICT,
    meeting_no INTEGER NOT NULL CHECK (meeting_no > 0),
    room_id INTEGER NOT NULL REFERENCES rooms(id) ON DELETE RESTRICT,
    day_of_week INTEGER NOT NULL CHECK (day_of_week BETWEEN 1 AND 7),
    slot_id INTEGER NOT NULL REFERENCES time_slots(id) ON DELETE RESTRICT,
    status TEXT NOT NULL DEFAULT 'PUBLISHED' CHECK (status IN ('PUBLISHED', 'CANCELLED')),
    UNIQUE (run_id, offering_id, meeting_no)
);

CREATE TABLE IF NOT EXISTS schedule_occupancy (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    run_id INTEGER NOT NULL REFERENCES schedule_runs(id) ON DELETE CASCADE,
    entry_id INTEGER NOT NULL REFERENCES schedule_entries(id) ON DELETE CASCADE,
    resource_type TEXT NOT NULL CHECK (resource_type IN ('ROOM', 'INSTRUCTOR', 'SECTION')),
    resource_id INTEGER NOT NULL,
    day_of_week INTEGER NOT NULL CHECK (day_of_week BETWEEN 1 AND 7),
    slot_id INTEGER NOT NULL REFERENCES time_slots(id) ON DELETE RESTRICT,
    UNIQUE (run_id, resource_type, resource_id, day_of_week, slot_id)
);

CREATE TABLE IF NOT EXISTS audit_logs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER REFERENCES users(id) ON DELETE SET NULL,
    action TEXT NOT NULL,
    entity_type TEXT,
    entity_id INTEGER,
    details_json TEXT NOT NULL DEFAULT '{}',
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS system_settings (
    setting_key TEXT PRIMARY KEY,
    setting_value TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS login_throttles (
    throttle_key TEXT PRIMARY KEY,
    attempts INTEGER NOT NULL DEFAULT 0 CHECK (attempts >= 0),
    window_started_at INTEGER NOT NULL,
    locked_until INTEGER NOT NULL DEFAULT 0
);

CREATE TABLE IF NOT EXISTS cloud_sync_state (
    id INTEGER PRIMARY KEY CHECK (id = 1),
    dirty INTEGER NOT NULL DEFAULT 1 CHECK (dirty IN (0, 1)),
    last_attempt_at TEXT,
    last_success_at TEXT,
    last_error TEXT
);

INSERT OR IGNORE INTO cloud_sync_state (id, dirty) VALUES (1, 1);

CREATE INDEX IF NOT EXISTS idx_sections_term ON sections(term_id);
CREATE INDEX IF NOT EXISTS idx_offerings_term ON course_offerings(term_id, status);
CREATE INDEX IF NOT EXISTS idx_offerings_section ON course_offerings(section_id);
CREATE INDEX IF NOT EXISTS idx_offerings_instructor ON course_offerings(instructor_id);
CREATE INDEX IF NOT EXISTS idx_pending_registrations_status ON pending_registrations(status, created_at);
CREATE INDEX IF NOT EXISTS idx_schedule_runs_term_status ON schedule_runs(term_id, status);
CREATE INDEX IF NOT EXISTS idx_schedule_entries_run ON schedule_entries(run_id);
CREATE INDEX IF NOT EXISTS idx_occupancy_run_resource ON schedule_occupancy(run_id, resource_type, resource_id);
CREATE UNIQUE INDEX IF NOT EXISTS uq_published_run_per_term ON schedule_runs(term_id) WHERE status = 'PUBLISHED';

CREATE TRIGGER IF NOT EXISTS trg_schedule_entry_term_match
BEFORE INSERT ON schedule_entries
BEGIN
    SELECT CASE WHEN NOT EXISTS (
        SELECT 1
        FROM schedule_runs sr
        JOIN course_offerings co ON co.id = NEW.offering_id
        WHERE sr.id = NEW.run_id AND sr.term_id = co.term_id
    ) THEN RAISE(ABORT, 'schedule entry term mismatch') END;
END;

CREATE TRIGGER IF NOT EXISTS trg_occupancy_entry_match
BEFORE INSERT ON schedule_occupancy
BEGIN
    SELECT CASE WHEN NOT EXISTS (
        SELECT 1 FROM schedule_entries se WHERE se.id = NEW.entry_id AND se.run_id = NEW.run_id
    ) THEN RAISE(ABORT, 'occupancy entry/run mismatch') END;
END;

CREATE TRIGGER IF NOT EXISTS trg_occupancy_resource_exists
BEFORE INSERT ON schedule_occupancy
BEGIN
    SELECT CASE
        WHEN NEW.resource_type = 'ROOM' AND NOT EXISTS (SELECT 1 FROM rooms WHERE id = NEW.resource_id) THEN RAISE(ABORT, 'unknown room resource')
        WHEN NEW.resource_type = 'INSTRUCTOR' AND NOT EXISTS (SELECT 1 FROM instructors WHERE id = NEW.resource_id) THEN RAISE(ABORT, 'unknown instructor resource')
        WHEN NEW.resource_type = 'SECTION' AND NOT EXISTS (SELECT 1 FROM sections WHERE id = NEW.resource_id) THEN RAISE(ABORT, 'unknown section resource')
    END;
END;

CREATE TRIGGER IF NOT EXISTS trg_occupancy_resource_match
BEFORE INSERT ON schedule_occupancy
BEGIN
    SELECT CASE
        WHEN NEW.resource_type = 'ROOM' AND NOT EXISTS (
            SELECT 1 FROM schedule_entries se WHERE se.id = NEW.entry_id AND se.room_id = NEW.resource_id
        ) THEN RAISE(ABORT, 'occupancy room does not match schedule entry')
        WHEN NEW.resource_type = 'INSTRUCTOR' AND NOT EXISTS (
            SELECT 1
            FROM schedule_entries se
            JOIN course_offerings co ON co.id = se.offering_id
            WHERE se.id = NEW.entry_id AND co.instructor_id = NEW.resource_id
        ) THEN RAISE(ABORT, 'occupancy instructor does not match schedule entry')
        WHEN NEW.resource_type = 'SECTION' AND NOT EXISTS (
            SELECT 1
            FROM schedule_entries se
            JOIN course_offerings co ON co.id = se.offering_id
            WHERE se.id = NEW.entry_id AND co.section_id = NEW.resource_id
        ) THEN RAISE(ABORT, 'occupancy section does not match schedule entry')
    END;
END;
