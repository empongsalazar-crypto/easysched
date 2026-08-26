<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'db.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'security.php';

// New Sinai School and Colleges Sta. Rosa, Inc. schedules run Monday through Friday. Keeping the day domain
// explicit prevents the solver from publishing weekend classes accidentally.
const DAY_NAMES = [1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday'];

if (!defined('EASYSCHED_LIBRARY_MODE')) {
    easysched_start_session();
    easysched_send_security_headers();
}

final class ApiError extends RuntimeException
{
    public function __construct(public readonly int $status, string $message, public readonly array $details = [])
    {
        parent::__construct($message);
    }
}

function respond(array $payload, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function body(): array
{
    $raw = file_get_contents('php://input');
    if ($raw === false || trim($raw) === '') {
        return [];
    }
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        throw new ApiError(400, 'Request body must be valid JSON.');
    }
    return $decoded;
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return (string) $_SESSION['csrf'];
}

function require_csrf(array $input): void
{
    $given = (string) ($input['csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''));
    if ($given === '' || !hash_equals(csrf_token(), $given)) {
        throw new ApiError(419, 'Your session token is invalid or expired. Refresh and try again.');
    }
}

function current_user(PDO $pdo): ?array
{
    $userId = (int) ($_SESSION['user_id'] ?? 0);
    if ($userId < 1) {
        return null;
    }
    $now = time();
    $lastActivity = (int) ($_SESSION['last_activity'] ?? $now);
    if ($lastActivity > 0 && ($now - $lastActivity) > 1800) {
        $_SESSION = [];
        session_destroy();
        return null;
    }
    $_SESSION['last_activity'] = $now;
    $stmt = $pdo->prepare('SELECT id, username, display_name, email, role, instructor_id, section_id, active FROM users WHERE id = ? LIMIT 1');
    $stmt->execute([$userId]);
    $user = $stmt->fetch();
    if (!$user || !(int) $user['active']) {
        return null;
    }
    return $user;
}

function require_auth(PDO $pdo, array $roles = []): array
{
    $user = current_user($pdo);
    if (!$user) {
        throw new ApiError(401, 'Authentication is required.');
    }
    if ($roles && !in_array($user['role'], $roles, true)) {
        throw new ApiError(403, 'You do not have permission to perform this action.');
    }
    return $user;
}

function audit(PDO $pdo, ?array $user, string $action, ?string $entity = null, ?int $entityId = null, array $details = []): void
{
    $philippineNow = (new DateTimeImmutable('now', new DateTimeZone('Asia/Manila')))->format('Y-m-d H:i:s');
    $stmt = $pdo->prepare('INSERT INTO audit_logs (user_id, action, entity_type, entity_id, details_json, created_at) VALUES (?, ?, ?, ?, ?, ?)');
    $stmt->execute([$user['id'] ?? null, $action, $entity, $entityId, encode_json($details), $philippineNow]);
    cloud_sync_mark_dirty($pdo);
}

function login_throttle_key(string $username): string
{
    $address = (string) ($_SERVER['REMOTE_ADDR'] ?? 'local');
    return hash('sha256', $address . '|' . strtolower($username));
}

function login_ip_key(): string { return 'ip:' . hash('sha256', (string) ($_SERVER['REMOTE_ADDR'] ?? 'local')); }
function login_account_key(string $username): string { return 'account:' . hash('sha256', strtolower($username)); }

function login_attempts(PDO $pdo, string $key, int $now): int
{
    $stmt = $pdo->prepare('SELECT attempts, window_started_at FROM login_throttles WHERE throttle_key = ?');
    $stmt->execute([$key]); $row = $stmt->fetch();
    return !$row || $now - (int) $row['window_started_at'] > 900 ? 0 : (int) $row['attempts'];
}

function assert_login_allowed(PDO $pdo, string $key, int $now): void
{
    $stmt = $pdo->prepare('SELECT attempts, window_started_at, locked_until FROM login_throttles WHERE throttle_key = ?');
    $stmt->execute([$key]);
    $row = $stmt->fetch();
    if (!$row) {
        return;
    }
    // Normalize locks created by older builds that used a 15-minute duration.
    $lockedUntil = min((int) $row['locked_until'], (int) $row['window_started_at'] + 60);
    if ($lockedUntil > $now) {
        throw new ApiError(429, 'Too many login attempts. Try again in about one minute.');
    }
    if ($lockedUntil !== (int) $row['locked_until'] || $now - (int) $row['window_started_at'] > 900) {
        $pdo->prepare('DELETE FROM login_throttles WHERE throttle_key = ?')->execute([$key]);
    }
}

function record_login_failure(PDO $pdo, string $key, int $now): void
{
    $stmt = $pdo->prepare('SELECT attempts, window_started_at FROM login_throttles WHERE throttle_key = ?');
    $stmt->execute([$key]);
    $row = $stmt->fetch();
    $attempts = 1;
    $windowStarted = $now;
    if ($row && $now - (int) $row['window_started_at'] <= 900) {
        $attempts = (int) $row['attempts'] + 1;
        $windowStarted = (int) $row['window_started_at'];
    }
    $lockedUntil = $attempts >= 8 ? $now + 60 : 0;
    $upsert = $pdo->prepare('INSERT INTO login_throttles (throttle_key, attempts, window_started_at, locked_until) VALUES (?, ?, ?, ?) ON CONFLICT(throttle_key) DO UPDATE SET attempts=excluded.attempts, window_started_at=excluded.window_started_at, locked_until=excluded.locked_until');
    $upsert->execute([$key, $attempts, $windowStarted, $lockedUntil]);
}

function login_captcha_required(PDO $pdo, string $ipKey, string $accountKey, int $now): bool
{
    return max(login_attempts($pdo, $ipKey, $now), login_attempts($pdo, $accountKey, $now)) >= 3;
}

function login_captcha_valid(array $input): bool
{
    if (empty($_SESSION['login_challenge_answer'])) return true;
    $answer = trim((string) ($input['captcha'] ?? ''));
    $valid = hash_equals((string) $_SESSION['login_challenge_answer'], $answer);
    unset($_SESSION['login_challenge_answer'], $_SESSION['login_challenge_question']);
    return $valid;
}

function login_captcha_issue(): array
{
    $left = random_int(2, 9); $right = random_int(1, 9);
    $_SESSION['login_challenge_question'] = "What is {$left} + {$right}?";
    $_SESSION['login_challenge_answer'] = (string) ($left + $right);
    return ['captcha_required' => true, 'captcha_question' => $_SESSION['login_challenge_question']];
}

function login_failure(PDO $pdo, string $username, string $ipKey, string $accountKey, int $now, string $reason): never
{
    record_login_failure($pdo, $ipKey, $now); record_login_failure($pdo, $accountKey, $now);
    audit($pdo, null, 'LOGIN_FAILURE', 'authentication', null, ['username_hash' => hash('sha256', strtolower($username)), 'ip_hash' => hash('sha256', (string) ($_SERVER['REMOTE_ADDR'] ?? 'local')), 'reason' => $reason]);
    $attempts = max(login_attempts($pdo, $ipKey, $now), login_attempts($pdo, $accountKey, $now));
    usleep(min(8, 1 << min(3, max(0, $attempts - 1))) * 100000);
    throw new ApiError(401, 'The username or password is incorrect.', $attempts >= 3 ? login_captcha_issue() : []);
}

function recent_login_security_alert(PDO $pdo, string $username): ?array
{
    $usernameHash = hash('sha256', strtolower($username));
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM audit_logs WHERE action = 'LOGIN_FAILURE' AND created_at >= ? AND details_json LIKE ?");
    $philippineCutoff = (new DateTimeImmutable('now', new DateTimeZone('Asia/Manila')))->modify('-24 hours')->format('Y-m-d H:i:s');
    $stmt->execute([$philippineCutoff, '%"username_hash":"' . $usernameHash . '"%']);
    $count = (int) $stmt->fetchColumn();
    return $count > 0 ? ['failed_attempts' => $count, 'window_hours' => 24] : null;
}

function register_student(PDO $pdo, array $input): array
{
    $username = strtolower(input_string($input, 'username', 80));
    if (!preg_match('/^[a-z0-9][a-z0-9._-]*$/', $username)) throw new ApiError(422, 'Username may contain only letters, numbers, dots, hyphens, and underscores.');
    $displayName = input_string($input, 'display_name', 160);
    $email = input_email($input);
    $enrollmentRef = input_string($input, 'enrollment_ref', 60);
    $programId = input_int($input, 'program_id', 1, 100000000);
    $yearLevel = input_int($input, 'year_level', 1, 8);
    $sectionId = input_int($input, 'section_id', 1, 100000000, false);
    $password = (string) ($input['password'] ?? '');
    $passwordLength = function_exists('mb_strlen') ? mb_strlen($password) : strlen($password);
    if ($passwordLength < 10 || !preg_match('/[A-Za-z]/', $password) || !preg_match('/\d/', $password)) throw new ApiError(422, 'Use a password with at least 10 characters, including a letter and a number.');
    assert_reference($pdo, 'programs', $programId, 'Program', 'active = 1');
    if ($sectionId !== null) {
        $stmt = $pdo->prepare('SELECT id FROM sections WHERE id = ? AND program_id = ? AND year_level = ? AND active = 1');
        $stmt->execute([$sectionId, $programId, $yearLevel]);
        if (!$stmt->fetchColumn()) throw new ApiError(422, 'The selected section does not match the program and year level.');
    }
    $stmt = $pdo->prepare('SELECT id FROM users WHERE username = ? UNION SELECT id FROM pending_registrations WHERE username = ? AND status = \'PENDING\' LIMIT 1');
    $stmt->execute([$username, $username]);
    if ($stmt->fetchColumn()) throw new ApiError(409, 'That username is already registered or awaiting review.');
    try {
        $stmt = $pdo->prepare('INSERT INTO pending_registrations (username, display_name, email, enrollment_ref, program_id, year_level, section_id, password_hash) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
        $stmt->execute([$username, $displayName, $email, $enrollmentRef, $programId, $yearLevel, $sectionId, password_hash($password, PASSWORD_DEFAULT)]);
    } catch (PDOException $error) {
        throw new ApiError(409, 'A registration with those details already exists.');
    }
    $id = db_insert_id($pdo, 'pending_registrations');
    audit($pdo, null, 'REGISTER_STUDENT', 'pending_registration', $id, ['username_hash' => hash('sha256', $username)]);
    return ['message' => 'Registration submitted. An administrator must approve your account before you can sign in.'];
}

function input_string(array $input, string $key, int $max = 255, bool $required = true): string
{
    $value = trim((string) ($input[$key] ?? ''));
    if ($required && $value === '') {
        throw new ApiError(422, ucfirst($key) . ' is required.');
    }
    $length = function_exists('mb_strlen') ? mb_strlen($value) : strlen($value);
    if ($length > $max) {
        throw new ApiError(422, ucfirst($key) . ' is too long.');
    }
    return $value;
}

function input_int(array $input, string $key, int $min, int $max, bool $required = true): ?int
{
    if (!array_key_exists($key, $input) || $input[$key] === '' || $input[$key] === null) {
        if ($required) {
            throw new ApiError(422, ucfirst($key) . ' is required.');
        }
        return null;
    }
    $value = filter_var($input[$key], FILTER_VALIDATE_INT);
    if ($value === false || $value < $min || $value > $max) {
        throw new ApiError(422, ucfirst($key) . ' is invalid.');
    }
    return (int) $value;
}

function input_code(array $input, string $key): string
{
    $value = strtoupper(input_string($input, $key, 40));
    if (!preg_match('/^[A-Z0-9][A-Z0-9_-]*$/', $value)) {
        throw new ApiError(422, ucfirst($key) . ' may contain only letters, numbers, hyphens, and underscores.');
    }
    return $value;
}

function input_email(array $input, string $key = 'email'): string
{
    $value = input_string($input, $key, 180, false);
    if ($value !== '' && filter_var($value, FILTER_VALIDATE_EMAIL) === false) {
        throw new ApiError(422, 'Email address is invalid.');
    }
    return strtolower($value);
}

function clean_string_list(mixed $value, int $maxItems = 30): array
{
    if (!is_array($value)) {
        return [];
    }
    $result = [];
    $seen = [];
    foreach (array_slice($value, 0, $maxItems) as $item) {
        $text = trim((string) $item);
        $length = function_exists('mb_strlen') ? mb_strlen($text) : strlen($text);
        if ($text === '' || $length > 80) {
            continue;
        }
        $key = strtolower($text);
        if (!isset($seen[$key])) {
            $result[] = $text;
            $seen[$key] = true;
        }
    }
    return $result;
}

function assert_reference(PDO $pdo, string $table, int $id, string $label, string $extraWhere = ''): void
{
    $allowed = ['academic_terms', 'programs', 'instructors', 'rooms', 'subjects', 'sections', 'course_offerings', 'users', 'time_slots'];
    if (!in_array($table, $allowed, true)) {
        throw new LogicException('Unsupported reference table.');
    }
    $sql = "SELECT 1 FROM {$table} WHERE id = ?" . ($extraWhere !== '' ? ' AND ' . $extraWhere : '') . ' LIMIT 1';
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$id]);
    if (!$stmt->fetchColumn()) {
        throw new ApiError(422, $label . ' is invalid or inactive.');
    }
}

function active_term_id(PDO $pdo): int
{
    $stmt = $pdo->query("SELECT CAST(setting_value AS INTEGER) FROM system_settings WHERE setting_key = 'active_term_id'");
    $id = (int) $stmt->fetchColumn();
    if ($id > 0) {
        return $id;
    }
    return (int) $pdo->query('SELECT id FROM academic_terms WHERE is_active = 1 ORDER BY id DESC LIMIT 1')->fetchColumn();
}

function decode_rows(array $rows, array $jsonFields = []): array
{
    foreach ($rows as &$row) {
        foreach ($jsonFields as $field) {
            if (array_key_exists($field, $row)) {
                $row[$field] = json_array($row[$field]);
            }
        }
    }
    return $rows;
}

function active_run(PDO $pdo, int $termId): ?array
{
    $stmt = $pdo->prepare("SELECT * FROM schedule_runs WHERE term_id = ? AND status = 'PUBLISHED' ORDER BY id DESC LIMIT 1");
    $stmt->execute([$termId]);
    return $stmt->fetch() ?: null;
}

function validate_published_schedule(PDO $pdo, int $termId): array
{
    $run = active_run($pdo, $termId);
    if (!$run) {
        return ['valid' => false, 'checks' => [], 'issues' => ['No published schedule exists for the active term.']];
    }

    $expectedStmt = $pdo->prepare("SELECT COALESCE(SUM(required_meetings), 0) FROM course_offerings WHERE term_id = ? AND status = 'ACTIVE'");
    $expectedStmt->execute([$termId]);
    $expected = (int) $expectedStmt->fetchColumn();
    $entryStmt = $pdo->prepare("SELECT se.id, se.offering_id, se.meeting_no, se.room_id, se.day_of_week, se.slot_id,
                                      co.section_id, co.instructor_id, co.enrollment,
                                      s.code AS subject_code, s.duration_slots, s.room_type, s.required_features_json,
                                      sec.code AS section_code, i.max_hours_day,
                                      r.capacity, r.room_type AS assigned_room_type, r.features_json
                               FROM schedule_entries se
                               JOIN course_offerings co ON co.id = se.offering_id
                               JOIN subjects s ON s.id = co.subject_id
                               JOIN sections sec ON sec.id = co.section_id
                               JOIN instructors i ON i.id = co.instructor_id
                               JOIN rooms r ON r.id = se.room_id
                               WHERE se.run_id = ? AND se.status = 'PUBLISHED'");
    $entryStmt->execute([(int) $run['id']]);
    $entries = $entryStmt->fetchAll();
    $context = load_scheduler_context($pdo);
    $checks = [
        'required_meetings_complete' => count($entries) === $expected,
        'valid_time_slots' => true,
        'valid_room_assignments' => true,
        'room_capacity' => true,
        'room_type_and_features' => true,
        'room_conflicts' => true,
        'instructor_conflicts' => true,
        'section_conflicts' => true,
        'declared_availability' => true,
        'instructor_daily_hours' => true,
        'duplicate_assignments' => true,
    ];
    $issues = [];
    if (!$checks['required_meetings_complete']) {
        $issues[] = sprintf('Published schedule has %d of %d required meetings.', count($entries), $expected);
    }
    $occupied = [];
    $dailyHours = [];
    $assignmentKeys = [];

    foreach ($entries as $entry) {
        $assignmentKey = (int) $entry['offering_id'] . ':' . (int) $entry['meeting_no'];
        if (isset($assignmentKeys[$assignmentKey])) {
            $checks['duplicate_assignments'] = false;
            $issues[] = 'A course offering meeting is assigned more than once.';
        }
        $assignmentKeys[$assignmentKey] = true;

        if (!isset(DAY_NAMES[(int) $entry['day_of_week']])) {
            $checks['valid_time_slots'] = false;
            $issues[] = $entry['subject_code'] . ' has an invalid school day.';
            continue;
        }
        $slotIndex = null;
        foreach ($context['slots'] as $index => $slot) {
            if ((int) $slot['id'] === (int) $entry['slot_id']) { $slotIndex = $index; break; }
        }
        $window = $slotIndex === null ? null : slot_window($context['slots'], $slotIndex, max(1, (int) $entry['duration_slots']));
        if ($window === null) {
            $checks['valid_time_slots'] = false;
            $issues[] = $entry['subject_code'] . ' does not fit within the configured time slots.';
            continue;
        }

        if ((int) $entry['capacity'] < (int) $entry['enrollment']) {
            $checks['room_capacity'] = false;
            $issues[] = sprintf('%s %s exceeds its room capacity.', $entry['subject_code'], $entry['section_code']);
        }
        $features = array_map('strtolower', array_map('strval', json_array($entry['features_json'])));
        $required = json_array($entry['required_features_json']);
        if ($entry['assigned_room_type'] !== $entry['room_type'] || array_filter($required, static fn($feature): bool => !in_array(strtolower((string) $feature), $features, true))) {
            $checks['room_type_and_features'] = false;
            $checks['valid_room_assignments'] = false;
            $issues[] = sprintf('%s %s is assigned to an incompatible room.', $entry['subject_code'], $entry['section_code']);
        }

        $entryHours = 0.0;
        foreach ($window as $slot) {
            $slotId = (int) $slot['id'];
            $day = (int) $entry['day_of_week'];
            $resourceKeys = [
                'room_conflicts' => occupancy_key('ROOM', (int) $entry['room_id'], $day, $slotId),
                'instructor_conflicts' => occupancy_key('INSTRUCTOR', (int) $entry['instructor_id'], $day, $slotId),
                'section_conflicts' => occupancy_key('SECTION', (int) $entry['section_id'], $day, $slotId),
            ];
            foreach ($resourceKeys as $check => $key) {
                if (isset($occupied[$key])) {
                    $checks[$check] = false;
                    $issues[] = ucfirst(str_replace('_', ' ', $check)) . ' detected.';
                }
                $occupied[$key] = true;
            }
            if (isset($context['blocked_room'][(int) $entry['room_id'] . ':' . $day . ':' . $slotId]) || isset($context['blocked_instructor'][(int) $entry['instructor_id'] . ':' . $day . ':' . $slotId]) || isset($context['blocked_section'][(int) $entry['section_id'] . ':' . $day . ':' . $slotId])) {
                $checks['declared_availability'] = false;
                $issues[] = sprintf('%s %s uses a blocked availability period.', $entry['subject_code'], $entry['section_code']);
            }
            $entryHours += (strtotime($slot['end_time']) - strtotime($slot['start_time'])) / 3600;
        }
        $hoursKey = (int) $entry['instructor_id'] . ':' . (int) $entry['day_of_week'];
        $dailyHours[$hoursKey] = ($dailyHours[$hoursKey] ?? 0) + $entryHours;
        if ($dailyHours[$hoursKey] > (int) $entry['max_hours_day']) {
            $checks['instructor_daily_hours'] = false;
            $issues[] = 'An instructor exceeds the configured daily teaching-hour limit.';
        }
    }

    return ['valid' => !in_array(false, $checks, true), 'checks' => $checks, 'issues' => array_values(array_unique($issues)), 'expected_tasks' => $expected, 'published_tasks' => count($entries)];
}

function scoped_schedule(PDO $pdo, array $user, int $termId): array
{
    $where = ['co.term_id = ?', "sr.status = 'PUBLISHED'", "se.status = 'PUBLISHED'"];
    $params = [$termId];
    if ($user['role'] === 'instructor') {
        $where[] = 'co.instructor_id = ?';
        $params[] = (int) $user['instructor_id'];
    } elseif ($user['role'] === 'student') {
        $where[] = 'co.section_id = ?';
        $params[] = (int) $user['section_id'];
    }
    $sql = 'SELECT se.id, se.run_id, se.offering_id, se.meeting_no, se.day_of_week, se.slot_id,
                   ts.code AS slot_code, ts.label AS slot_label, ts.start_time,
                   COALESCE((SELECT end_time FROM time_slots ts_end WHERE ts_end.slot_order = ts.slot_order + s.duration_slots - 1), ts.end_time) AS end_time,
                   r.id AS room_id, r.code AS room_code, r.name AS room_name, r.capacity AS room_capacity,
                   s.id AS subject_id, s.code AS subject_code, s.name AS subject_name, s.duration_slots,
                   sec.id AS section_id, sec.code AS section_code, sec.student_count,
                   p.code AS program_code, p.name AS program_name,
                   i.id AS instructor_id, i.employee_no, i.name AS instructor_name
            FROM schedule_entries se
            JOIN schedule_runs sr ON sr.id = se.run_id
            JOIN course_offerings co ON co.id = se.offering_id
            JOIN time_slots ts ON ts.id = se.slot_id
            JOIN rooms r ON r.id = se.room_id
            JOIN subjects s ON s.id = co.subject_id
            JOIN sections sec ON sec.id = co.section_id
            JOIN programs p ON p.id = sec.program_id
            JOIN instructors i ON i.id = co.instructor_id
            WHERE ' . implode(' AND ', $where) . ' ORDER BY se.day_of_week, ts.slot_order, sec.code, s.code';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();
    foreach ($rows as &$row) {
        $row['day_name'] = DAY_NAMES[(int) $row['day_of_week']] ?? 'Unknown';
        $row['time_label'] = date('g:i A', strtotime((string) $row['start_time'])) . ' - ' . date('g:i A', strtotime((string) $row['end_time']));
    }
    return $rows;
}

function bootstrap(PDO $pdo, array $user): array
{
    $termId = active_term_id($pdo);
    $terms = $pdo->query('SELECT id, academic_year, semester, is_active FROM academic_terms ORDER BY academic_year DESC, id DESC')->fetchAll();
    $programs = $pdo->query('SELECT id, code, name, active FROM programs WHERE active = 1 ORDER BY code')->fetchAll();
    $instructors = $pdo->query('SELECT id, employee_no, name, email, max_hours_day, active FROM instructors WHERE active = 1 ORDER BY name')->fetchAll();
    $rooms = decode_rows($pdo->query('SELECT id, code, name, capacity, room_type, features_json, active FROM rooms WHERE active = 1 ORDER BY code')->fetchAll(), ['features_json']);
    foreach ($rooms as &$room) {
        $room['features'] = $room['features_json'];
        unset($room['features_json']);
    }
    $subjects = decode_rows($pdo->query('SELECT id, code, name, units, hours_per_week, duration_slots, room_type, required_features_json, active FROM subjects WHERE active = 1 ORDER BY code')->fetchAll(), ['required_features_json']);
    foreach ($subjects as &$subject) {
        $subject['required_features'] = $subject['required_features_json'];
        unset($subject['required_features_json']);
    }
    $stmt = $pdo->prepare('SELECT sec.id, sec.program_id, sec.code, sec.year_level, sec.student_count, sec.term_id, p.code AS program_code, p.name AS program_name FROM sections sec JOIN programs p ON p.id = sec.program_id WHERE sec.active = 1 AND sec.term_id = ? ORDER BY sec.code');
    $stmt->execute([$termId]);
    $sections = $stmt->fetchAll();
    $stmt = $pdo->prepare('SELECT co.id, co.term_id, co.subject_id, co.section_id, co.instructor_id, co.enrollment, co.required_meetings, co.status, s.code AS subject_code, s.name AS subject_name, sec.code AS section_code, i.name AS instructor_name FROM course_offerings co JOIN subjects s ON s.id = co.subject_id JOIN sections sec ON sec.id = co.section_id JOIN instructors i ON i.id = co.instructor_id WHERE co.term_id = ? AND co.status = \'ACTIVE\' ORDER BY sec.code, s.code');
    $stmt->execute([$termId]);
    $offerings = $stmt->fetchAll();
    $slots = $pdo->query('SELECT id, code, label, start_time, end_time, slot_order FROM time_slots ORDER BY slot_order')->fetchAll();
    $run = active_run($pdo, $termId);
    $settings = $pdo->query('SELECT setting_key, setting_value FROM system_settings')->fetchAll(PDO::FETCH_KEY_PAIR);
    $users = [];
    $pendingRegistrations = [];
    if ($user['role'] === 'admin') {
        $users = $pdo->query("SELECT u.id, u.username, u.display_name, u.email, u.role, u.instructor_id, u.section_id, u.active, i.name AS instructor_name, sec.code AS section_code FROM users u LEFT JOIN instructors i ON i.id = u.instructor_id LEFT JOIN sections sec ON sec.id = u.section_id WHERE u.active = 1 ORDER BY u.role, u.username")->fetchAll();
        $pendingRegistrations = $pdo->query("SELECT pr.id, pr.username, pr.display_name, pr.email, pr.enrollment_ref, pr.program_id, pr.year_level, pr.section_id, pr.status, pr.review_note, pr.created_at, p.code AS program_code, sec.code AS section_code FROM pending_registrations pr JOIN programs p ON p.id = pr.program_id LEFT JOIN sections sec ON sec.id = pr.section_id WHERE pr.status = 'PENDING' ORDER BY pr.created_at ASC, pr.id ASC")->fetchAll();
    }
    $schedules = scoped_schedule($pdo, $user, $termId);
    if (in_array($user['role'], ['instructor', 'student'], true)) {
        $offerings = array_values(array_filter($offerings, static function (array $offering) use ($user): bool {
            return $user['role'] === 'instructor'
                ? (int) $offering['instructor_id'] === (int) $user['instructor_id']
                : (int) $offering['section_id'] === (int) $user['section_id'];
        }));
        $allowedInstructorIds = array_map('intval', array_column($offerings, 'instructor_id'));
        $allowedSectionIds = array_map('intval', array_column($offerings, 'section_id'));
        $allowedSubjectIds = array_map('intval', array_column($offerings, 'subject_id'));
        $allowedRoomIds = array_map('intval', array_column($schedules, 'room_id'));
        $instructors = array_values(array_filter($instructors, static fn(array $row): bool => in_array((int) $row['id'], $allowedInstructorIds, true)));
        $sections = array_values(array_filter($sections, static fn(array $row): bool => in_array((int) $row['id'], $allowedSectionIds, true)));
        $subjects = array_values(array_filter($subjects, static fn(array $row): bool => in_array((int) $row['id'], $allowedSubjectIds, true)));
        $rooms = array_values(array_filter($rooms, static fn(array $row): bool => in_array((int) $row['id'], $allowedRoomIds, true)));
        $allowedProgramIds = array_map('intval', array_column($sections, 'program_id'));
        $programs = array_values(array_filter($programs, static fn(array $row): bool => in_array((int) $row['id'], $allowedProgramIds, true)));
        $settings = array_intersect_key($settings, array_flip(['institution_name', 'system_name']));
    }
    return [
        'user' => $user,
        'csrf' => csrf_token(),
        'settings' => $settings,
        'active_term_id' => $termId,
        'terms' => $terms,
        'programs' => $programs,
        'instructors' => $instructors,
        'rooms' => $rooms,
        'subjects' => $subjects,
        'sections' => $sections,
        'offerings' => $offerings,
        'users' => $users,
        'pending_registrations' => $pendingRegistrations,
        'time_slots' => $slots,
        'days' => DAY_NAMES,
        'active_run' => $run ? ['id' => (int) $run['id'], 'status' => $run['status'], 'created_at' => $run['created_at'], 'assigned_tasks' => (int) $run['assigned_tasks'], 'total_tasks' => (int) $run['total_tasks'], 'diagnostics' => json_decode($run['diagnostics_json'], true) ?: []] : null,
        'validation' => validate_published_schedule($pdo, $termId),
        'schedules' => $schedules,
        'cloud_sync' => cloud_sync_status($pdo),
    ];
}

function load_offerings(PDO $pdo, int $termId): array
{
    $stmt = $pdo->prepare('SELECT co.id, co.term_id, co.subject_id, co.section_id, co.instructor_id, co.enrollment, co.required_meetings,
                                  s.code AS subject_code, s.name AS subject_name, s.hours_per_week, s.duration_slots, s.room_type, s.required_features_json,
                                  sec.code AS section_code, sec.student_count,
                                  i.name AS instructor_name, i.max_hours_day
                           FROM course_offerings co
                           JOIN subjects s ON s.id = co.subject_id
                           JOIN sections sec ON sec.id = co.section_id
                           JOIN instructors i ON i.id = co.instructor_id
                           WHERE co.term_id = ? AND co.status = \'ACTIVE\'
                           ORDER BY co.id');
    $stmt->execute([$termId]);
    $rows = $stmt->fetchAll();
    foreach ($rows as &$row) {
        $row['required_features'] = json_array($row['required_features_json']);
        unset($row['required_features_json']);
        $row['duration_slots'] = max(1, (int) $row['duration_slots']);
        $row['required_meetings'] = max(1, (int) $row['required_meetings']);
    }
    return $rows;
}

function load_scheduler_context(PDO $pdo): array
{
    $slots = $pdo->query('SELECT id, slot_order, start_time, end_time FROM time_slots ORDER BY slot_order')->fetchAll();
    $rooms = decode_rows($pdo->query('SELECT id, capacity, room_type, features_json FROM rooms WHERE active = 1')->fetchAll(), ['features_json']);
    foreach ($rooms as &$room) {
        $room['features'] = array_map('strtolower', array_map('strval', $room['features_json']));
        unset($room['features_json']);
    }
    $blockedInstructor = [];
    foreach ($pdo->query('SELECT instructor_id, day_of_week, slot_id FROM instructor_availability WHERE available = 0')->fetchAll() as $row) {
        $blockedInstructor[(int) $row['instructor_id'] . ':' . (int) $row['day_of_week'] . ':' . (int) $row['slot_id']] = true;
    }
    $blockedRoom = [];
    foreach ($pdo->query('SELECT room_id, day_of_week, slot_id FROM room_availability WHERE available = 0')->fetchAll() as $row) {
        $blockedRoom[(int) $row['room_id'] . ':' . (int) $row['day_of_week'] . ':' . (int) $row['slot_id']] = true;
    }
    $blockedSection = [];
    foreach ($pdo->query('SELECT section_id, day_of_week, slot_id FROM section_availability WHERE available = 0')->fetchAll() as $row) {
        $blockedSection[(int) $row['section_id'] . ':' . (int) $row['day_of_week'] . ':' . (int) $row['slot_id']] = true;
    }
    $preferences = [];
    foreach ($pdo->query('SELECT instructor_id, day_of_week, slot_id, preference FROM instructor_time_preferences')->fetchAll() as $row) {
        $preferences[(int) $row['instructor_id'] . ':' . (int) $row['day_of_week'] . ':' . (int) $row['slot_id']] = (int) $row['preference'];
    }
    return ['slots' => $slots, 'rooms' => $rooms, 'blocked_instructor' => $blockedInstructor, 'blocked_room' => $blockedRoom, 'blocked_section' => $blockedSection, 'preferences' => $preferences];
}

function slot_window(array $slots, int $startIndex, int $duration): ?array
{
    $window = array_slice($slots, $startIndex, $duration);
    if (count($window) !== $duration) {
        return null;
    }
    for ($i = 1; $i < count($window); $i++) {
        if ((int) $window[$i]['slot_order'] !== (int) $window[$i - 1]['slot_order'] + 1) {
            return null;
        }
    }
    return $window;
}

function occupancy_key(string $type, int $resourceId, int $day, int $slotId): string
{
    return $type . ':' . $resourceId . ':' . $day . ':' . $slotId;
}

function candidate_for(array $task, array $room, int $day, array $window, array $context, array $occupied, array $dailyHours): ?array
{
    if ((int) $room['capacity'] < (int) $task['enrollment']) {
        return null;
    }
    if ($room['room_type'] !== $task['room_type']) {
        return null;
    }
    $roomFeatures = array_map('strtolower', $room['features']);
    foreach ($task['required_features'] as $feature) {
        if (!in_array(strtolower((string) $feature), $roomFeatures, true)) {
            return null;
        }
    }
    $roomHours = 0;
    foreach ($window as $slot) {
        $slotId = (int) $slot['id'];
        $keyRoom = occupancy_key('ROOM', (int) $room['id'], $day, $slotId);
        $keyInstructor = occupancy_key('INSTRUCTOR', (int) $task['instructor_id'], $day, $slotId);
        $keySection = occupancy_key('SECTION', (int) $task['section_id'], $day, $slotId);
        if (isset($occupied[$keyRoom]) || isset($occupied[$keyInstructor]) || isset($occupied[$keySection])) {
            return null;
        }
        if (isset($context['blocked_room'][(int) $room['id'] . ':' . $day . ':' . $slotId])) {
            return null;
        }
        if (isset($context['blocked_instructor'][(int) $task['instructor_id'] . ':' . $day . ':' . $slotId])) {
            return null;
        }
        if (isset($context['blocked_section'][(int) $task['section_id'] . ':' . $day . ':' . $slotId])) {
            return null;
        }
        $roomHours += (strtotime($slot['end_time']) - strtotime($slot['start_time'])) / 3600;
    }
    $currentHours = (float) ($dailyHours[(int) $task['instructor_id'] . ':' . $day] ?? 0);
    if ($currentHours + $roomHours > (int) $task['max_hours_day']) {
        return null;
    }
    $startOrder = (int) $window[0]['slot_order'];
    $earlyLatePenalty = ($startOrder === 1 ? 1.5 : 0) + ($startOrder >= 5 ? 1.5 : 0);
    $preferencePenalty = 0.0;
    foreach ($window as $slot) {
        $preference = (int) ($context['preferences'][(int) $task['instructor_id'] . ':' . $day . ':' . (int) $slot['id']] ?? 0);
        $preferencePenalty += $preference < 0 ? abs($preference) * 1.5 : -$preference * 0.75;
    }
    $roomWaste = max(0, (int) $room['capacity'] - (int) $task['enrollment']);
    return ['room_id' => (int) $room['id'], 'day' => $day, 'slot_id' => (int) $window[0]['id'], 'slot_ids' => array_map(static fn(array $slot): int => (int) $slot['id'], $window), 'hours' => $roomHours, 'cost' => $roomWaste * 0.01 + $earlyLatePenalty + $preferencePenalty];
}

function build_tasks(array $offerings): array
{
    $tasks = [];
    foreach ($offerings as $offering) {
        for ($meeting = 1; $meeting <= (int) $offering['required_meetings']; $meeting++) {
            $tasks[] = ['task_id' => (int) $offering['id'] . ':' . $meeting, 'offering_id' => (int) $offering['id'], 'meeting_no' => $meeting, 'subject_name' => $offering['subject_name'], 'section_id' => (int) $offering['section_id'], 'section_code' => $offering['section_code'], 'instructor_id' => (int) $offering['instructor_id'], 'instructor_name' => $offering['instructor_name'], 'max_hours_day' => (int) $offering['max_hours_day'], 'enrollment' => (int) $offering['enrollment'], 'duration_slots' => (int) $offering['duration_slots'], 'room_type' => $offering['room_type'], 'required_features' => $offering['required_features']];
        }
    }
    return $tasks;
}

function scheduler_preflight(array $offerings, array $context): array
{
    $issues = [];
    $warnings = [];
    if ($context['slots'] === []) {
        $issues[] = 'No time slots are configured.';
    }
    if ($context['rooms'] === []) {
        $issues[] = 'No active rooms are configured.';
    }

    foreach ($offerings as $offering) {
        $eligibleRooms = array_filter($context['rooms'], static function (array $room) use ($offering): bool {
            if ((int) $room['capacity'] < (int) $offering['enrollment'] || $room['room_type'] !== $offering['room_type']) {
                return false;
            }
            foreach ($offering['required_features'] as $feature) {
                if (!in_array(strtolower((string) $feature), $room['features'], true)) {
                    return false;
                }
            }
            return true;
        });
        if ($eligibleRooms === []) {
            $featureText = $offering['required_features'] ? ' with ' . implode(', ', $offering['required_features']) : '';
            $issues[] = sprintf('%s for %s needs a %s room for %d students%s, but no eligible room exists.', $offering['subject_code'], $offering['section_code'], $offering['room_type'], (int) $offering['enrollment'], $featureText);
        }
        if ((int) $offering['duration_slots'] > count($context['slots'])) {
            $issues[] = sprintf('%s for %s needs %d consecutive slots, but only %d are configured.', $offering['subject_code'], $offering['section_code'], (int) $offering['duration_slots'], count($context['slots']));
        }

        $firstWindow = slot_window($context['slots'], 0, max(1, (int) $offering['duration_slots']));
        $hoursPerMeeting = 0.0;
        foreach ($firstWindow ?: [] as $slot) {
            $hoursPerMeeting += (strtotime($slot['end_time']) - strtotime($slot['start_time'])) / 3600;
        }
        $plannedHours = $hoursPerMeeting * (int) $offering['required_meetings'];
        if ($hoursPerMeeting > 0 && abs($plannedHours - (float) $offering['hours_per_week']) > 0.01) {
            $warnings[] = sprintf('%s for %s is configured for %.1f scheduled hours but the subject requires %d hours per week.', $offering['subject_code'], $offering['section_code'], $plannedHours, (int) $offering['hours_per_week']);
        }
    }
    return ['issues' => array_values(array_unique($issues)), 'warnings' => array_values(array_unique($warnings))];
}

function explain_unscheduled_tasks(array $tasks, array $context): array
{
    $reasons = [];
    foreach ($tasks as $task) {
        $hasRoom = false;
        $hasTime = false;
        foreach ($context['rooms'] as $room) {
            if ((int) $room['capacity'] < (int) $task['enrollment'] || $room['room_type'] !== $task['room_type']) {
                continue;
            }
            $featuresMatch = true;
            foreach ($task['required_features'] as $feature) {
                if (!in_array(strtolower((string) $feature), $room['features'], true)) {
                    $featuresMatch = false;
                    break;
                }
            }
            if (!$featuresMatch) {
                continue;
            }
            $hasRoom = true;
            foreach (DAY_NAMES as $day => $_dayName) {
                foreach ($context['slots'] as $slotIndex => $_slot) {
                    $window = slot_window($context['slots'], $slotIndex, (int) $task['duration_slots']);
                    if ($window !== null && candidate_for($task, $room, (int) $day, $window, $context, [], []) !== null) {
                        $hasTime = true;
                        break 3;
                    }
                }
            }
        }
        if (!$hasRoom) {
            $reason = sprintf('%s (%s) has no room matching its capacity, type, and required features.', $task['subject_name'], $task['section_code']);
        } elseif (!$hasTime) {
            $reason = sprintf('%s (%s) has no individually valid time because of duration, availability, or daily-hour limits.', $task['subject_name'], $task['section_code']);
        } else {
            $reason = sprintf('%s (%s) has valid individual choices, but the combined room, instructor, and section constraints are over-constrained.', $task['subject_name'], $task['section_code']);
        }
        $reasons[] = $reason;
    }
    return array_values(array_unique($reasons));
}

function solve_schedule(array $tasks, array $context, int $nodeLimit = 100000): array
{
    $occupied = [];
    $dailyHours = [];
    $assigned = [];
    $nodes = 0;
    $failureCounts = [];

    $search = function (array $remaining) use (&$search, &$occupied, &$dailyHours, &$assigned, &$nodes, &$failureCounts, $context, $tasks, $nodeLimit): bool {
        if ($remaining === []) {
            return true;
        }
        if (++$nodes > $nodeLimit) {
            $failureCounts['search_limit'] = ($failureCounts['search_limit'] ?? 0) + 1;
            return false;
        }

        $bestIndex = -1;
        $bestCandidates = null;
        foreach ($remaining as $index => $task) {
            $candidates = [];
            foreach ($context['rooms'] as $room) {
                foreach (DAY_NAMES as $day => $dayName) {
                    foreach ($context['slots'] as $slotIndex => $slot) {
                        $window = slot_window($context['slots'], $slotIndex, (int) $task['duration_slots']);
                        if ($window === null) {
                            continue;
                        }
                        $candidate = candidate_for($task, $room, (int) $day, $window, $context, $occupied, $dailyHours);
                        if ($candidate !== null) {
                            foreach ($assigned as $existing) {
                                if ((int) $existing['task']['offering_id'] === (int) $task['offering_id'] && (int) $existing['candidate']['day'] === (int) $candidate['day']) {
                                    $candidate['cost'] += 4.0;
                                }
                            }
                            $candidate['day_name'] = $dayName;
                            $candidates[] = $candidate;
                        }
                    }
                }
            }
            if ($candidates === []) {
                $failureCounts['no_candidate:' . $task['subject_name'] . ' (' . $task['section_code'] . ')'] = 1;
                return false;
            }
            usort($candidates, static fn(array $left, array $right): int => $left['cost'] <=> $right['cost']);
            if ($bestCandidates === null || count($candidates) < count($bestCandidates)) {
                $bestIndex = $index;
                $bestCandidates = $candidates;
                if (count($bestCandidates) === 1) {
                    break;
                }
            }
        }

        $task = $remaining[$bestIndex];
        $nextRemaining = $remaining;
        array_splice($nextRemaining, $bestIndex, 1);
        foreach ($bestCandidates as $candidate) {
            foreach ($candidate['slot_ids'] as $slotId) {
                $occupied[occupancy_key('ROOM', $candidate['room_id'], $candidate['day'], $slotId)] = true;
                $occupied[occupancy_key('INSTRUCTOR', $task['instructor_id'], $candidate['day'], $slotId)] = true;
                $occupied[occupancy_key('SECTION', $task['section_id'], $candidate['day'], $slotId)] = true;
            }
            $hoursKey = $task['instructor_id'] . ':' . $candidate['day'];
            $dailyHours[$hoursKey] = ($dailyHours[$hoursKey] ?? 0) + $candidate['hours'];
            $assigned[$task['task_id']] = ['task' => $task, 'candidate' => $candidate];
            if ($search($nextRemaining)) {
                return true;
            }
            unset($assigned[$task['task_id']]);
            $dailyHours[$hoursKey] -= $candidate['hours'];
            if ($dailyHours[$hoursKey] <= 0) {
                unset($dailyHours[$hoursKey]);
            }
            foreach ($candidate['slot_ids'] as $slotId) {
                unset($occupied[occupancy_key('ROOM', $candidate['room_id'], $candidate['day'], $slotId)]);
                unset($occupied[occupancy_key('INSTRUCTOR', $task['instructor_id'], $candidate['day'], $slotId)]);
                unset($occupied[occupancy_key('SECTION', $task['section_id'], $candidate['day'], $slotId)]);
            }
        }
        return false;
    };

    $success = $search($tasks);
    return ['success' => $success, 'assignments' => array_values($assigned), 'nodes' => $nodes, 'failure_counts' => $failureCounts, 'soft_cost' => array_sum(array_map(static fn(array $entry): float => (float) $entry['candidate']['cost'], $assigned))];
}

function generate_schedule(PDO $pdo, array $user, int $termId): array
{
    $offerings = load_offerings($pdo, $termId);
    if ($offerings === []) {
        throw new ApiError(422, 'No active course offerings exist for the selected term.');
    }
    $context = load_scheduler_context($pdo);
    $preflight = scheduler_preflight($offerings, $context);
    if ($preflight['issues'] !== []) {
        throw new ApiError(422, $preflight['issues'][0], ['preflight_issues' => $preflight['issues'], 'warnings' => $preflight['warnings'], 'total_tasks' => count(build_tasks($offerings)), 'assigned_tasks' => 0]);
    }
    $nodeLimit = (int) ($pdo->query("SELECT setting_value FROM system_settings WHERE setting_key = 'generation_node_limit'")->fetchColumn() ?: 100000);
    $tasks = build_tasks($offerings);
    $solution = solve_schedule($tasks, $context, max(1000, min($nodeLimit, 1000000)));
    $diagnostics = ['hard_constraints' => ['room_capacity', 'room_type_and_features', 'room_overlap', 'instructor_overlap', 'section_overlap', 'instructor_daily_hours', 'instructor_room_section_availability', 'required_meetings'], 'soft_constraints' => ['instructor_time_preferences', 'spread_repeated_meetings', 'avoid_early_and_late_slots', 'minimize_unused_room_capacity'], 'warnings' => $preflight['warnings'], 'total_tasks' => count($tasks), 'assigned_tasks' => count($solution['assignments']), 'search_nodes' => $solution['nodes'], 'soft_cost' => round((float) $solution['soft_cost'], 3), 'failures' => $solution['failure_counts']];
    if (!$solution['success']) {
        $diagnostics['explanations'] = explain_unscheduled_tasks($tasks, $context);
        throw new ApiError(422, 'A complete conflict-free schedule could not be generated. The previous published schedule was preserved.', $diagnostics);
    }

    $pdo->beginTransaction();
    try {
        $archive = $pdo->prepare("UPDATE schedule_runs SET status = 'ARCHIVED' WHERE term_id = ? AND status = 'PUBLISHED'");
        $archive->execute([$termId]);
        $run = $pdo->prepare("INSERT INTO schedule_runs (term_id, created_by, status, total_tasks, assigned_tasks, diagnostics_json) VALUES (?, ?, 'RUNNING', ?, ?, ?)");
        $run->execute([$termId, (int) $user['id'], count($tasks), count($solution['assignments']), encode_json($diagnostics)]);
        $runId = db_insert_id($pdo, 'schedule_runs');
        $entry = $pdo->prepare('INSERT INTO schedule_entries (run_id, offering_id, meeting_no, room_id, day_of_week, slot_id) VALUES (?, ?, ?, ?, ?, ?)');
        $occupancy = $pdo->prepare('INSERT INTO schedule_occupancy (run_id, entry_id, resource_type, resource_id, day_of_week, slot_id) VALUES (?, ?, ?, ?, ?, ?)');
        foreach ($solution['assignments'] as $assignment) {
            $task = $assignment['task'];
            $candidate = $assignment['candidate'];
            $entry->execute([$runId, $task['offering_id'], $task['meeting_no'], $candidate['room_id'], $candidate['day'], $candidate['slot_id']]);
            $entryId = db_insert_id($pdo, 'schedule_entries');
            foreach ($candidate['slot_ids'] as $slotId) {
                $occupancy->execute([$runId, $entryId, 'ROOM', $candidate['room_id'], $candidate['day'], $slotId]);
                $occupancy->execute([$runId, $entryId, 'INSTRUCTOR', $task['instructor_id'], $candidate['day'], $slotId]);
                $occupancy->execute([$runId, $entryId, 'SECTION', $task['section_id'], $candidate['day'], $slotId]);
            }
        }
        $publish = $pdo->prepare("UPDATE schedule_runs SET status = 'PUBLISHED' WHERE id = ?");
        $publish->execute([$runId]);
        audit($pdo, $user, 'GENERATE_SCHEDULE', 'schedule_run', $runId, $diagnostics);
        $pdo->commit();
        return ['run_id' => $runId, 'diagnostics' => $diagnostics];
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw new ApiError(409, 'The generated schedule could not be committed because the data changed during generation. Try again.');
    }
}

function active_entry_context(PDO $pdo, int $termId): array
{
    $run = active_run($pdo, $termId);
    if (!$run) {
        return ['run' => null, 'entries' => []];
    }
    $stmt = $pdo->prepare('SELECT se.*, co.subject_id, co.section_id, co.instructor_id, co.enrollment, s.room_type, s.required_features_json, s.duration_slots, i.max_hours_day FROM schedule_entries se JOIN course_offerings co ON co.id = se.offering_id JOIN subjects s ON s.id = co.subject_id JOIN instructors i ON i.id = co.instructor_id WHERE se.run_id = ? AND se.status = \'PUBLISHED\'');
    $stmt->execute([(int) $run['id']]);
    $entries = $stmt->fetchAll();
    return ['run' => $run, 'entries' => $entries];
}

function validate_manual_candidate(PDO $pdo, int $termId, int $offeringId, int $roomId, int $day, int $slotId, ?int $ignoreEntryId = null): array
{
    if (!isset(DAY_NAMES[$day])) {
        throw new ApiError(422, 'Schedules may only be placed Monday through Friday.');
    }
    $stmt = $pdo->prepare('SELECT co.*, s.room_type, s.required_features_json, s.duration_slots, s.name AS subject_name, sec.code AS section_code, sec.student_count, i.name AS instructor_name, i.max_hours_day FROM course_offerings co JOIN subjects s ON s.id = co.subject_id JOIN sections sec ON sec.id = co.section_id JOIN instructors i ON i.id = co.instructor_id WHERE co.id = ? AND co.term_id = ? AND co.status = \'ACTIVE\'');
    $stmt->execute([$offeringId, $termId]);
    $offering = $stmt->fetch();
    if (!$offering) {
        throw new ApiError(404, 'Course offering not found.');
    }
    $context = load_scheduler_context($pdo);
    $room = null;
    foreach ($context['rooms'] as $candidateRoom) {
        if ((int) $candidateRoom['id'] === $roomId) {
            $room = $candidateRoom;
            break;
        }
    }
    if (!$room) {
        throw new ApiError(422, 'Room is not available.');
    }
    $slotIndex = null;
    foreach ($context['slots'] as $index => $slot) {
        if ((int) $slot['id'] === $slotId) {
            $slotIndex = $index;
            break;
        }
    }
    if ($slotIndex === null) {
        throw new ApiError(422, 'Time slot is invalid.');
    }
    $window = slot_window($context['slots'], $slotIndex, max(1, (int) $offering['duration_slots']));
    if ($window === null) {
        throw new ApiError(422, 'The subject duration does not fit within the selected day.');
    }
    $active = active_entry_context($pdo, $termId);
    $occupied = [];
    $dailyHours = [];
    foreach ($active['entries'] as $entry) {
        if ($ignoreEntryId !== null && (int) $entry['id'] === $ignoreEntryId) {
            continue;
        }
        $entrySlotIndex = null;
        foreach ($context['slots'] as $index => $slot) {
            if ((int) $slot['id'] === (int) $entry['slot_id']) {
                $entrySlotIndex = $index;
                break;
            }
        }
        if ($entrySlotIndex === null) {
            continue;
        }
        $entryWindow = slot_window($context['slots'], $entrySlotIndex, max(1, (int) $entry['duration_slots']));
        $entryHours = 0.0;
        foreach ($entryWindow ?: [] as $entrySlot) {
            $slotValue = (int) $entrySlot['id'];
            $occupied[occupancy_key('ROOM', (int) $entry['room_id'], (int) $entry['day_of_week'], $slotValue)] = true;
            $occupied[occupancy_key('INSTRUCTOR', (int) $entry['instructor_id'], (int) $entry['day_of_week'], $slotValue)] = true;
            $occupied[occupancy_key('SECTION', (int) $entry['section_id'], (int) $entry['day_of_week'], $slotValue)] = true;
            $entryHours += (strtotime($entrySlot['end_time']) - strtotime($entrySlot['start_time'])) / 3600;
        }
        if ($entryWindow !== null && isset(DAY_NAMES[(int) $entry['day_of_week']])) {
            $hoursKey = (int) $entry['instructor_id'] . ':' . (int) $entry['day_of_week'];
            $dailyHours[$hoursKey] = ($dailyHours[$hoursKey] ?? 0) + $entryHours;
        }
    }
    $task = ['enrollment' => (int) $offering['enrollment'], 'room_type' => $offering['room_type'], 'required_features' => json_array($offering['required_features_json']), 'instructor_id' => (int) $offering['instructor_id'], 'section_id' => (int) $offering['section_id'], 'max_hours_day' => (int) $offering['max_hours_day']];
    $candidate = candidate_for($task, $room, $day, $window, $context, $occupied, $dailyHours);
    if ($candidate === null) {
        throw new ApiError(409, 'The selected room, time, instructor, or section conflicts with a hard constraint.');
    }
    return ['offering' => $offering, 'candidate' => $candidate, 'run' => $active['run']];
}

function save_master(PDO $pdo, array $user, array $input): array
{
    $entity = (string) ($input['entity'] ?? '');
    $data = is_array($input['data'] ?? null) ? $input['data'] : [];
    $recordId = array_key_exists('id', $input)
        ? input_int($input, 'id', 1, 100000000, false)
        : input_int($data, 'id', 1, 100000000, false);
    $isUpdate = $recordId !== null;
    $delete = !empty($input['delete']);
    $table = ['rooms' => 'rooms', 'instructors' => 'instructors', 'subjects' => 'subjects', 'programs' => 'programs', 'sections' => 'sections', 'offerings' => 'course_offerings', 'users' => 'users'][$entity] ?? null;
    if ($table === null) {
        throw new ApiError(422, 'Unsupported master-data type.');
    }
    if ($delete) {
        if ($recordId === null) {
            throw new ApiError(422, 'A record id is required.');
        }
        if ($entity === 'users') {
            if ($user['role'] !== 'admin') throw new ApiError(403, 'Only administrators can manage user accounts.');
            if ($recordId === (int) $user['id']) throw new ApiError(409, 'You cannot deactivate your own account.');
            $adminCount = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE role='admin' AND active=1")->fetchColumn();
            $target = $pdo->prepare('SELECT role FROM users WHERE id = ? AND active = 1'); $target->execute([$recordId]);
            if ($target->fetchColumn() === 'admin' && $adminCount <= 1) throw new ApiError(409, 'At least one active administrator is required.');
        }
        $sql = $entity === 'offerings' ? "UPDATE course_offerings SET status='INACTIVE' WHERE id=? AND status='ACTIVE'" : "UPDATE {$table} SET active=0 WHERE id=? AND active=1";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$recordId]);
        if ($stmt->rowCount() !== 1) throw new ApiError(404, 'Record not found or already inactive.');
        audit($pdo, $user, 'DEACTIVATE_MASTER_DATA', $entity, $recordId);
        return ['deactivated' => $recordId];
    }

    if ($entity === 'rooms') {
        $code = input_code($data, 'code'); $name = input_string($data, 'name', 120); $capacity = input_int($data, 'capacity', 1, 5000); $type = strtoupper(input_string($data, 'room_type', 20));
        if (!in_array($type, ['LECTURE', 'LAB', 'SPECIAL'], true)) throw new ApiError(422, 'Room type is invalid.');
        $features = clean_string_list($data['features'] ?? []);
        if ($recordId) { $stmt = $pdo->prepare('UPDATE rooms SET code=?, name=?, capacity=?, room_type=?, features_json=? WHERE id=?'); $stmt->execute([$code, $name, $capacity, $type, encode_json($features), $recordId]); }
        else { $stmt = $pdo->prepare('INSERT INTO rooms (code,name,capacity,room_type,features_json) VALUES (?,?,?,?,?)'); $stmt->execute([$code,$name,$capacity,$type,encode_json($features)]); $recordId = db_insert_id($pdo, 'rooms'); }
    } elseif ($entity === 'instructors') {
        $employeeNo = input_code($data, 'employee_no'); $name = input_string($data, 'name', 120); $email = input_email($data); $max = input_int($data, 'max_hours_day', 1, 16);
        if ($recordId) { $stmt = $pdo->prepare('UPDATE instructors SET employee_no=?, name=?, email=?, max_hours_day=? WHERE id=?'); $stmt->execute([$employeeNo,$name,$email,$max,$recordId]); }
        else { $stmt = $pdo->prepare('INSERT INTO instructors (employee_no,name,email,max_hours_day) VALUES (?,?,?,?)'); $stmt->execute([$employeeNo,$name,$email,$max]); $recordId=db_insert_id($pdo, 'instructors'); }
    } elseif ($entity === 'programs') {
        $code = input_code($data, 'code'); $name = input_string($data, 'name', 160);
        if ($recordId) { $stmt=$pdo->prepare('UPDATE programs SET code=?, name=? WHERE id=?'); $stmt->execute([$code,$name,$recordId]); }
        else { $stmt=$pdo->prepare('INSERT INTO programs (code,name) VALUES (?,?)'); $stmt->execute([$code,$name]); $recordId=db_insert_id($pdo, 'programs'); }
    } elseif ($entity === 'subjects') {
        $code=input_code($data,'code'); $name=input_string($data,'name',180); $units=input_int($data,'units',1,12); $hours=input_int($data,'hours_per_week',1,40); $duration=input_int($data,'duration_slots',1,8); $type=strtoupper(input_string($data,'room_type',20));
        if (!in_array($type,['LECTURE','LAB','SPECIAL'],true)) throw new ApiError(422,'Room type is invalid.');
        $features=clean_string_list($data['required_features']??[]);
        if ($recordId) { $stmt=$pdo->prepare('UPDATE subjects SET code=?,name=?,units=?,hours_per_week=?,duration_slots=?,room_type=?,required_features_json=? WHERE id=?'); $stmt->execute([$code,$name,$units,$hours,$duration,$type,encode_json($features),$recordId]); }
        else { $stmt=$pdo->prepare('INSERT INTO subjects (code,name,units,hours_per_week,duration_slots,room_type,required_features_json) VALUES (?,?,?,?,?,?,?)'); $stmt->execute([$code,$name,$units,$hours,$duration,$type,encode_json($features)]); $recordId=db_insert_id($pdo, 'subjects'); }
    } elseif ($entity === 'sections') {
        $programId=input_int($data,'program_id',1,100000000); $termId=input_int($data,'term_id',1,100000000); $code=input_code($data,'code'); $year=input_int($data,'year_level',1,8); $count=input_int($data,'student_count',1,5000);
        assert_reference($pdo, 'programs', $programId, 'Program', 'active = 1');
        assert_reference($pdo, 'academic_terms', $termId, 'Academic term');
        if ($recordId) { $stmt=$pdo->prepare('UPDATE sections SET program_id=?,term_id=?,code=?,year_level=?,student_count=? WHERE id=?'); $stmt->execute([$programId,$termId,$code,$year,$count,$recordId]); }
        else { $stmt=$pdo->prepare('INSERT INTO sections (program_id,term_id,code,year_level,student_count) VALUES (?,?,?,?,?)'); $stmt->execute([$programId,$termId,$code,$year,$count]); $recordId=db_insert_id($pdo, 'sections'); }
    } elseif ($entity === 'offerings') {
        $termId=input_int($data,'term_id',1,100000000); $subjectId=input_int($data,'subject_id',1,100000000); $sectionId=input_int($data,'section_id',1,100000000); $instructorId=input_int($data,'instructor_id',1,100000000); $enrollment=input_int($data,'enrollment',1,5000); $meetings=input_int($data,'required_meetings',1,20);
        assert_reference($pdo, 'academic_terms', $termId, 'Academic term');
        assert_reference($pdo, 'subjects', $subjectId, 'Subject', 'active = 1');
        assert_reference($pdo, 'instructors', $instructorId, 'Instructor', 'active = 1');
        $sectionStmt = $pdo->prepare('SELECT student_count FROM sections WHERE id = ? AND term_id = ? AND active = 1');
        $sectionStmt->execute([$sectionId, $termId]);
        $sectionCount = $sectionStmt->fetchColumn();
        if ($sectionCount === false) throw new ApiError(422, 'Section must be active and belong to the selected academic term.');
        if ($enrollment > (int) $sectionCount) throw new ApiError(422, 'Enrollment cannot exceed the section student count.');
        if ($recordId) { $stmt=$pdo->prepare('UPDATE course_offerings SET term_id=?,subject_id=?,section_id=?,instructor_id=?,enrollment=?,required_meetings=? WHERE id=?'); $stmt->execute([$termId,$subjectId,$sectionId,$instructorId,$enrollment,$meetings,$recordId]); }
        else { $stmt=$pdo->prepare('INSERT INTO course_offerings (term_id,subject_id,section_id,instructor_id,enrollment,required_meetings) VALUES (?,?,?,?,?,?)'); $stmt->execute([$termId,$subjectId,$sectionId,$instructorId,$enrollment,$meetings]); $recordId=db_insert_id($pdo, 'course_offerings'); }
    } else {
        if ($user['role'] !== 'admin') throw new ApiError(403, 'Only administrators can manage user accounts.');
        $username = strtolower(input_string($data, 'username', 80));
        if (!preg_match('/^[a-z0-9][a-z0-9._-]*$/', $username)) throw new ApiError(422, 'Username may contain lowercase letters, numbers, dots, hyphens, and underscores.');
        $displayName = input_string($data, 'display_name', 120);
        $email = input_email($data);
        $role = strtolower(input_string($data, 'role', 20));
        if (!in_array($role, ['admin', 'scheduler', 'instructor', 'student'], true)) throw new ApiError(422, 'User role is invalid.');
        $instructorId = input_int($data, 'instructor_id', 1, 100000000, false);
        $sectionId = input_int($data, 'section_id', 1, 100000000, false);
        if ($role === 'instructor') {
            if ($instructorId === null) throw new ApiError(422, 'An instructor account must be linked to a faculty record.');
            assert_reference($pdo, 'instructors', $instructorId, 'Instructor', 'active = 1');
            $sectionId = null;
        } elseif ($role === 'student') {
            if ($sectionId === null) throw new ApiError(422, 'A student account must be linked to a section.');
            assert_reference($pdo, 'sections', $sectionId, 'Section', 'active = 1');
            $instructorId = null;
        } else {
            $instructorId = null;
            $sectionId = null;
        }
        $password = (string) ($data['password'] ?? '');
        $passwordLength = function_exists('mb_strlen') ? mb_strlen($password) : strlen($password);
        if (!$recordId && $password === '') throw new ApiError(422, 'A temporary password is required for a new account.');
        if ($password !== '' && ($passwordLength < 10 || !preg_match('/[A-Za-z]/', $password) || !preg_match('/\d/', $password))) throw new ApiError(422, 'Temporary passwords need at least 10 characters, including a letter and a number.');
        if ($recordId) {
            if ($recordId === (int) $user['id'] && $role !== 'admin') throw new ApiError(409, 'Use another administrator account before changing your own administrator role.');
            $sql = 'UPDATE users SET username=?,display_name=?,email=?,role=?,instructor_id=?,section_id=?' . ($password !== '' ? ',password_hash=?' : '') . ' WHERE id=? AND active=1';
            $params = [$username,$displayName,$email,$role,$instructorId,$sectionId];
            if ($password !== '') $params[] = password_hash($password, PASSWORD_DEFAULT);
            $params[] = $recordId;
            $stmt=$pdo->prepare($sql); $stmt->execute($params);
        } else {
            $stmt=$pdo->prepare('INSERT INTO users (username,password_hash,display_name,email,role,instructor_id,section_id) VALUES (?,?,?,?,?,?,?)');
            $stmt->execute([$username,password_hash($password,PASSWORD_DEFAULT),$displayName,$email,$role,$instructorId,$sectionId]);
            $recordId=db_insert_id($pdo, 'users');
        }
    }
    audit($pdo, $user, $isUpdate ? 'UPDATE_MASTER_DATA' : 'CREATE_MASTER_DATA', $entity, $recordId);
    return ['id' => $recordId];
}

function review_registration(PDO $pdo, array $user, array $input): array
{
    $registrationId = input_int($input, 'registration_id', 1, 100000000);
    $decision = strtoupper(input_string($input, 'decision', 10));
    if (!in_array($decision, ['APPROVE', 'REJECT'], true)) throw new ApiError(422, 'Registration decision is invalid.');
    $stmt = $pdo->prepare('SELECT * FROM pending_registrations WHERE id = ? AND status = \'PENDING\'');
    $stmt->execute([$registrationId]);
    $registration = $stmt->fetch();
    if (!$registration) throw new ApiError(404, 'Pending registration not found.');
    $note = input_string($input, 'review_note', 300, false);
    $pdo->beginTransaction();
    try {
        if ($decision === 'APPROVE') {
            $exists = $pdo->prepare('SELECT id FROM users WHERE username = ?'); $exists->execute([$registration['username']]);
            if ($exists->fetchColumn()) throw new ApiError(409, 'That username already belongs to an active account.');
            $insert = $pdo->prepare('INSERT INTO users (username, password_hash, display_name, email, role, section_id) VALUES (?, ?, ?, ?, \'student\', ?)');
            $insert->execute([$registration['username'], $registration['password_hash'], $registration['display_name'], $registration['email'], $registration['section_id']]);
        }
        $update = $pdo->prepare('UPDATE pending_registrations SET status = ?, review_note = ?, reviewed_by = ?, reviewed_at = ? WHERE id = ? AND status = \'PENDING\'');
        $reviewedAt = (new DateTimeImmutable('now', new DateTimeZone('Asia/Manila')))->format('Y-m-d H:i:s');
        $update->execute([$decision === 'APPROVE' ? 'APPROVED' : 'REJECTED', $note, (int) $user['id'], $reviewedAt, $registrationId]);
        audit($pdo, $user, $decision === 'APPROVE' ? 'APPROVE_STUDENT_REGISTRATION' : 'REJECT_STUDENT_REGISTRATION', 'pending_registration', $registrationId);
        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        if ($error instanceof ApiError) throw $error;
        throw new ApiError(409, 'The registration could not be reviewed.');
    }
    return ['message' => $decision === 'APPROVE' ? 'Student registration approved.' : 'Student registration rejected.'];
}

function export_csv(PDO $pdo, array $user, int $termId): never
{
    $rows = scoped_schedule($pdo, $user, $termId);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="EasySched-Schedule-' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'wb');
    fputcsv($out, ['Subject Code', 'Subject', 'Section', 'Program', 'Instructor', 'Room', 'Day', 'Time', 'Enrollment']);
    foreach ($rows as $row) {
        $values = [$row['subject_code'], $row['subject_name'], $row['section_code'], $row['program_code'], $row['instructor_name'], $row['room_code'], $row['day_name'], $row['time_label'], $row['student_count']];
        foreach ($values as &$value) {
            $value = (string) $value;
            if ($value !== '' && in_array($value[0], ['=', '+', '-', '@'], true)) {
                $value = "'" . $value;
            }
        }
        fputcsv($out, $values);
    }
    fclose($out);
    exit;
}

function handle(PDO $pdo): never
{
    $action = (string) ($_GET['action'] ?? $_POST['action'] ?? 'bootstrap');
    $input = (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') ? body() : [];

    if ($action === 'login') {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') throw new ApiError(405, 'POST is required.');
        $now = time();
        $username = strtolower(trim((string) ($input['username'] ?? ''))); $password = (string) ($input['password'] ?? '');
        $ipKey = login_ip_key(); $accountKey = login_account_key($username);
        assert_login_allowed($pdo, $ipKey, $now); assert_login_allowed($pdo, $accountKey, $now);
        if (login_captcha_required($pdo, $ipKey, $accountKey, $now) && !login_captcha_valid($input)) { login_failure($pdo, $username, $ipKey, $accountKey, $now, 'captcha_failed'); }
        if ($username === '' || strlen($username) > 80 || $password === '' || (function_exists('mb_strlen') ? mb_strlen($password) : strlen($password)) > 200) login_failure($pdo, $username, $ipKey, $accountKey, $now, 'invalid_input');
        $stmt = $pdo->prepare('SELECT * FROM users WHERE username = ? AND active = 1 LIMIT 1'); $stmt->execute([$username]); $record = $stmt->fetch();
        if (!$record || !password_verify($password, $record['password_hash'])) login_failure($pdo, $username, $ipKey, $accountKey, $now, 'invalid_credentials');
        $pdo->prepare('DELETE FROM login_throttles WHERE throttle_key IN (?, ?)')->execute([$ipKey, $accountKey]);
        unset($_SESSION['login_challenge_answer'], $_SESSION['login_challenge_question']);
        session_regenerate_id(true); $_SESSION['user_id']=(int)$record['id']; $_SESSION['csrf']=bin2hex(random_bytes(32));
        $user=current_user($pdo); audit($pdo,$user,'LOGIN'); $snapshot = bootstrap($pdo,$user); $snapshot['security_alert'] = recent_login_security_alert($pdo, (string) $user['username']); respond(['ok'=>true,'data'=>$snapshot]);
    }
    if ($action === 'register') {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') throw new ApiError(405, 'POST is required.');
        respond(['ok' => true, 'data' => register_student($pdo, $input)]);
    }
    if ($action === 'registration_options') {
        $programs = $pdo->query('SELECT id, code, name FROM programs WHERE active = 1 ORDER BY code')->fetchAll();
        $sections = $pdo->query('SELECT id, program_id, code, year_level FROM sections WHERE active = 1 ORDER BY code')->fetchAll();
        respond(['ok' => true, 'data' => ['programs' => $programs, 'sections' => $sections]]);
    }
    if ($action === 'health') {
        $pdo->query('SELECT 1')->fetchColumn();
        respond(['ok' => true, 'data' => ['service' => 'EasySched API', 'database' => 'connected']]);
    }
    if ($action === 'logout') {
        $user=require_auth($pdo); require_csrf($input); audit($pdo,$user,'LOGOUT'); $_SESSION=[]; if (ini_get('session.use_cookies')) { $params=session_get_cookie_params(); setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'] ?? '', (bool) $params['secure'], (bool) $params['httponly']); } session_destroy(); respond(['ok'=>true]);
    }

    $user = require_auth($pdo);
    if (in_array($action, ['generate','save_schedule','delete_schedule','save_master','change_password','save_settings','sync_cloud','review_registration'], true)) require_csrf($input);
    if ($action === 'bootstrap') respond(['ok'=>true,'data'=>bootstrap($pdo,$user)]);
    if ($action === 'sync_cloud') {
        require_auth($pdo, ['admin', 'scheduler']);
        $status = cloud_sync_snapshot($pdo);
        respond(['ok' => true, 'data' => ['cloud_sync' => $status]]);
    }
    if ($action === 'export') export_csv($pdo,$user,active_term_id($pdo));
    if ($action === 'generate') { require_auth($pdo,['admin','scheduler']); $termId=input_int($input,'term_id',1,100000000,false)??active_term_id($pdo); if ($termId !== active_term_id($pdo)) throw new ApiError(422, 'Only the active academic term can be generated.'); $result=generate_schedule($pdo,$user,$termId); respond(['ok'=>true,'data'=>array_merge($result,['snapshot'=>bootstrap($pdo,$user)])]); }
    if ($action === 'save_master') { require_auth($pdo,['admin','scheduler']); $result=save_master($pdo,$user,$input); respond(['ok'=>true,'data'=>array_merge($result,['snapshot'=>bootstrap($pdo,$user)])]); }
    if ($action === 'review_registration') { require_auth($pdo, ['admin']); $result = review_registration($pdo, $user, $input); respond(['ok' => true, 'data' => array_merge($result, ['snapshot' => bootstrap($pdo, $user)])]); }
    if ($action === 'save_schedule') {
        require_auth($pdo,['admin','scheduler']); $entryId=input_int($input,'entry_id',1,100000000); $roomId=input_int($input,'room_id',1,100000000); $day=input_int($input,'day_of_week',1,7); $slotId=input_int($input,'slot_id',1,100000000); $termId=active_term_id($pdo); if (!isset(DAY_NAMES[$day])) throw new ApiError(422, 'Schedules may only be placed Monday through Friday.');
        $stmt=$pdo->prepare("SELECT se.*, co.term_id FROM schedule_entries se JOIN schedule_runs sr ON sr.id=se.run_id AND sr.status='PUBLISHED' JOIN course_offerings co ON co.id=se.offering_id WHERE se.id=? AND se.status='PUBLISHED'"); $stmt->execute([$entryId]); $entry=$stmt->fetch(); if(!$entry || (int)$entry['term_id']!==$termId) throw new ApiError(404,'Schedule entry not found.');
        $validated=validate_manual_candidate($pdo,$termId,(int)$entry['offering_id'],$roomId,$day,$slotId,$entryId); $pdo->beginTransaction(); try { $pdo->prepare('DELETE FROM schedule_occupancy WHERE entry_id=?')->execute([$entryId]); $pdo->prepare('UPDATE schedule_entries SET room_id=?,day_of_week=?,slot_id=? WHERE id=?')->execute([$roomId,$day,$slotId,$entryId]); $occupancy=$pdo->prepare('INSERT INTO schedule_occupancy (run_id,entry_id,resource_type,resource_id,day_of_week,slot_id) VALUES (?,?,?,?,?,?)'); foreach($validated['candidate']['slot_ids'] as $occupiedSlot){$occupancy->execute([(int)$entry['run_id'],$entryId,'ROOM',$roomId,$day,$occupiedSlot]);$occupancy->execute([(int)$entry['run_id'],$entryId,'INSTRUCTOR',(int)$validated['offering']['instructor_id'],$day,$occupiedSlot]);$occupancy->execute([(int)$entry['run_id'],$entryId,'SECTION',(int)$validated['offering']['section_id'],$day,$occupiedSlot]);} audit($pdo,$user,'UPDATE_SCHEDULE','schedule_entry',$entryId,['room_id'=>$roomId,'day'=>$day,'slot'=>$slotId]);$pdo->commit(); } catch(Throwable $error){if($pdo->inTransaction())$pdo->rollBack(); if($error instanceof ApiError)throw $error; throw new ApiError(409,'The schedule entry could not be saved.');} respond(['ok'=>true,'data'=>['snapshot'=>bootstrap($pdo,$user)]]);
    }
    if ($action === 'delete_schedule') { require_auth($pdo,['admin','scheduler']); $entryId=input_int($input,'entry_id',1,100000000); $stmt=$pdo->prepare("SELECT se.id, se.run_id FROM schedule_entries se JOIN schedule_runs sr ON sr.id=se.run_id AND sr.status='PUBLISHED' JOIN course_offerings co ON co.id=se.offering_id WHERE se.id=? AND se.status='PUBLISHED' AND co.term_id=?");$stmt->execute([$entryId,active_term_id($pdo)]);if(!$stmt->fetch())throw new ApiError(404,'Schedule entry not found.');$pdo->prepare("UPDATE schedule_entries SET status='CANCELLED' WHERE id=?")->execute([$entryId]);$pdo->prepare('DELETE FROM schedule_occupancy WHERE entry_id=?')->execute([$entryId]);audit($pdo,$user,'CANCEL_SCHEDULE','schedule_entry',$entryId);respond(['ok'=>true,'data'=>['snapshot'=>bootstrap($pdo,$user)]]); }
    if ($action === 'change_password') { $current=(string)($input['current_password']??'');$next=(string)($input['new_password']??'');$confirm=(string)($input['confirm_password']??'');$nextLength=function_exists('mb_strlen')?mb_strlen($next):strlen($next);if($current===''||$next===''||$next!==$confirm||$nextLength<10||!preg_match('/[A-Za-z]/',$next)||!preg_match('/\d/',$next))throw new ApiError(422,'Use a matching password with at least 10 characters, including a letter and a number.');$stmt=$pdo->prepare('SELECT password_hash FROM users WHERE id=?');$stmt->execute([(int)$user['id']]);if(!password_verify($current,(string)$stmt->fetchColumn()))throw new ApiError(401,'The current password is incorrect.');$pdo->prepare('UPDATE users SET password_hash=? WHERE id=?')->execute([password_hash($next,PASSWORD_DEFAULT),(int)$user['id']]);audit($pdo,$user,'CHANGE_PASSWORD','user',(int)$user['id']);respond(['ok'=>true,'data'=>['message'=>'Password changed successfully.']]); }
    if ($action === 'save_settings') { require_auth($pdo,['admin']);$year=input_string($input,'academic_year',9);$semester=input_string($input,'semester',30);if(!preg_match('/^20\d{2}-20\d{2}$/',$year)||!in_array($semester,['First Semester','Second Semester','Summer'],true))throw new ApiError(422,'Academic year or semester is invalid.');$stmt=$pdo->prepare('SELECT id FROM academic_terms WHERE academic_year=? AND semester=?');$stmt->execute([$year,$semester]);$termId=(int)($stmt->fetchColumn()?:0);if(!$termId){$pdo->prepare('INSERT INTO academic_terms (academic_year,semester,is_active) VALUES (?,?,1)')->execute([$year,$semester]);$termId=db_insert_id($pdo, 'academic_terms');}$pdo->prepare("UPDATE academic_terms SET is_active=0")->execute();$pdo->prepare('UPDATE academic_terms SET is_active=1 WHERE id=?')->execute([$termId]);$pdo->prepare("INSERT INTO system_settings(setting_key,setting_value) VALUES('active_term_id',?) ON CONFLICT(setting_key) DO UPDATE SET setting_value=excluded.setting_value")->execute([(string)$termId]);audit($pdo,$user,'UPDATE_SETTINGS');respond(['ok'=>true,'data'=>['snapshot'=>bootstrap($pdo,$user)]]); }
    throw new ApiError(404, 'Unknown action.');
}

if (!defined('EASYSCHED_LIBRARY_MODE')) {
    try {
        handle(db());
    } catch (ApiError $error) {
        respond(['ok' => false, 'error' => $error->getMessage(), 'details' => $error->details], $error->status);
    } catch (PDOException $error) {
        // Do not expose SQL details. Convert integrity failures into an actionable
        // validation response while keeping the server log useful for diagnosis.
        error_log('EasySched database error: ' . $error->getMessage());
        $status = str_starts_with((string) $error->getCode(), '23') ? 409 : 500;
        $message = $status === 409 ? 'The change conflicts with an existing record or referenced data.' : 'The server could not complete that request.';
        respond(['ok' => false, 'error' => $message, 'details' => []], $status);
    } catch (Throwable $error) {
        error_log('EasySched API error: ' . $error->getMessage());
        respond(['ok' => false, 'error' => 'The server could not complete that request.'], 500);
    }
}
