# Legacy Data Migration Guide

The original EasySched prototype is a browser-only application. It has no server database, so the safe migration path is an explicit export, review, and import into the normalized SQLite schema. This keeps the old files and any existing browser data intact while making the new system authoritative only after verification.

## 1. Preserve the source

1. Keep a read-only copy of the old `index.html`, `script.js`, and `styles.css`.
2. Export any browser `localStorage` JSON from the browser developer tools and save it outside the web root.
3. Record the export date, academic year, semester, and person who approved the mapping.
4. Do not copy old password fields into `users`. The old prototype credentials are not a trusted password database.

On a fresh data directory, the first application request creates demonstration records. Perform migration work on a backup copy, reconcile code collisions deliberately, and deactivate demonstration rows only after their official replacements are verified. Do not overwrite a matching code merely because it came from the seed data.

## 2. Mapping to the new schema

| Legacy concept | New table | Required review |
| --- | --- | --- |
| School year/semester | `academic_terms` | Normalize to `YYYY-YYYY` and one allowed semester name |
| Program/course | `programs` | Deduplicate by code; retain the official name |
| Class/section | `sections` | Map to program and term; verify student count |
| Subject/course | `subjects` | Add units, duration, room type, and required features |
| Teacher/faculty | `instructors` | Deduplicate by employee number; verify email |
| Classroom/lab | `rooms` | Verify capacity, type, and feature list |
| Subject assigned to a section | `course_offerings` | Verify instructor, enrollment, and meetings per week |
| Legacy account | `users` | Create a fresh hash and reviewed role/link; never import a plaintext password |
| Weekly timetable row | `schedule_runs`, `schedule_entries`, `schedule_occupancy` | Import only after master data and conflict validation |

## 3. Staging process

Use a temporary staging table or CSV review sheet. Do not insert directly into published schedule tables. The staging table below is connection-local; perform the import and validation with the same SQLite connection.

```sql
CREATE TEMP TABLE legacy_offerings_stage (
    term_year TEXT NOT NULL CHECK (term_year GLOB '20[0-9][0-9]-20[0-9][0-9]'),
    semester TEXT NOT NULL CHECK (semester IN ('First Semester', 'Second Semester', 'Summer')),
    program_code TEXT NOT NULL,
    program_name TEXT NOT NULL,
    section_code TEXT NOT NULL,
    section_year_level INTEGER NOT NULL CHECK (section_year_level BETWEEN 1 AND 8),
    section_student_count INTEGER NOT NULL CHECK (section_student_count BETWEEN 1 AND 5000),
    subject_code TEXT NOT NULL,
    instructor_employee_no TEXT NOT NULL,
    enrollment INTEGER NOT NULL CHECK (enrollment BETWEEN 1 AND 5000),
    required_meetings INTEGER NOT NULL DEFAULT 1 CHECK (required_meetings BETWEEN 1 AND 20)
);

-- Load reviewed CSV rows here with your SQLite import tool.
-- Validate counts, codes, and duplicates before the INSERT statements below.
```

After review, insert in dependency order inside one transaction. The examples use `INSERT ... SELECT` and preserve existing records when the unique key already exists:

```sql
BEGIN;

INSERT INTO academic_terms (academic_year, semester, is_active)
SELECT DISTINCT term_year, semester, 0
FROM legacy_offerings_stage
WHERE NOT EXISTS (
  SELECT 1 FROM academic_terms t
  WHERE t.academic_year = legacy_offerings_stage.term_year
    AND t.semester = legacy_offerings_stage.semester
);

INSERT INTO programs (code, name)
SELECT DISTINCT program_code, program_name
FROM legacy_offerings_stage s
WHERE NOT EXISTS (SELECT 1 FROM programs p WHERE p.code = s.program_code);

-- Insert reviewed instructors, rooms, and subjects before sections.
-- Their IDs must be resolved by code/employee number, never guessed.

INSERT INTO sections (program_id, term_id, code, year_level, student_count)
SELECT p.id, t.id, s.section_code, MAX(s.section_year_level), MAX(s.section_student_count)
FROM legacy_offerings_stage s
JOIN programs p ON p.code = s.program_code
JOIN academic_terms t ON t.academic_year = s.term_year AND t.semester = s.semester
WHERE NOT EXISTS (
  SELECT 1 FROM sections x WHERE x.term_id = t.id AND x.code = s.section_code
)
GROUP BY p.id, t.id, s.section_code;

-- Confirm repeated staging rows agree before using the MAX values above.
SELECT term_year, semester, section_code
FROM legacy_offerings_stage
GROUP BY term_year, semester, section_code
HAVING MIN(section_year_level) <> MAX(section_year_level)
    OR MIN(section_student_count) <> MAX(section_student_count);

INSERT INTO course_offerings
    (term_id, subject_id, section_id, instructor_id, enrollment, required_meetings)
SELECT t.id, sub.id, sec.id, i.id, s.enrollment, s.required_meetings
FROM legacy_offerings_stage s
JOIN academic_terms t ON t.academic_year = s.term_year AND t.semester = s.semester
JOIN subjects sub ON sub.code = s.subject_code
JOIN sections sec ON sec.term_id = t.id AND sec.code = s.section_code
JOIN instructors i ON i.employee_no = s.instructor_employee_no
WHERE NOT EXISTS (
  SELECT 1 FROM course_offerings x
  WHERE x.term_id = t.id AND x.subject_id = sub.id AND x.section_id = sec.id
);

-- Keep this transaction open while running the validation checklist below.
-- Finish with COMMIT; only after every check passes, otherwise use ROLLBACK;.
```

The staging rows carry reviewed section year level and section size so repeated offerings do not create duplicate section rows. Verify that all rows for a given `(term, section_code)` agree on those values before committing. Likewise, subject room type/duration and room feature lists must be verified by the registrar or scheduling coordinator.

## 4. Schedule import policy

Prefer regenerating schedules from validated offerings. If an approved legacy timetable must be preserved, import it into a new `schedule_runs` row with status `RUNNING`, validate every row using the same room/instructor/section occupancy rules, and insert `schedule_occupancy`. In the same transaction, archive any existing `PUBLISHED` run for that term immediately before marking the validated run `PUBLISHED`; the schema permits only one published run per term. Never mark a partially imported run published.

## 5. Validation checklist

- Every code referenced by a staging row resolves to exactly one master record.
- Repeated rows for the same program code agree on the official program name.
- Every offering fits at least one eligible room by enrollment, room type, and required features.
- Repeated rows for the same term and section agree on year level and section size.
- Each offering has one instructor, one section, and an allowed room type.
- Required meetings and duration fit the available slots.
- No duplicate `(term, subject, section)` offering exists.
- No room, instructor, or section is double-booked in the imported timetable.
- The active term is explicitly selected after import.
- A backup of `data/easysched.sqlite` exists before the transaction.

If every check passes, commit the transaction. If any check fails, roll back and correct the staging data. Existing database rows are never deleted as part of this migration.
