<?php
declare(strict_types=1);

if ($argc !== 2) {
    fwrite(STDERR, "Usage: php assert_database.php path-to-sqlite\n");
    exit(2);
}

$path = $argv[1];
if (!is_file($path)) {
    fwrite(STDERR, "Database file not found: {$path}\n");
    exit(2);
}

$pdo = new PDO('sqlite:' . $path);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$failures = [];

$published = $pdo->query("SELECT id, term_id, total_tasks, assigned_tasks FROM schedule_runs WHERE status = 'PUBLISHED' ORDER BY id DESC LIMIT 1")->fetch();
if (!$published) {
    $failures[] = 'No published schedule run exists.';
} else {
    $duplicate = $pdo->prepare("SELECT resource_type, resource_id, day_of_week, slot_id, COUNT(*) AS count
        FROM schedule_occupancy
        WHERE run_id = ?
        GROUP BY resource_type, resource_id, day_of_week, slot_id
        HAVING COUNT(*) > 1");
    $duplicate->execute([(int) $published['id']]);
    if ($duplicate->fetch()) {
        $failures[] = 'Published occupancy contains a duplicate resource/time key.';
    }

    $expected = $pdo->prepare("SELECT COALESCE(SUM(required_meetings), 0) FROM course_offerings WHERE term_id = ? AND status = 'ACTIVE'");
    $expected->execute([(int) $published['term_id']]);
    $expectedCount = (int) $expected->fetchColumn();
    $actual = $pdo->prepare("SELECT COUNT(*) FROM schedule_entries WHERE run_id = ? AND status = 'PUBLISHED'");
    $actual->execute([(int) $published['id']]);
    $actualCount = (int) $actual->fetchColumn();
    if ($expectedCount !== $actualCount) {
        $failures[] = "Published entry count {$actualCount} does not match required meeting count {$expectedCount}.";
    }
    if ((int) $published['total_tasks'] !== (int) $published['assigned_tasks']) {
        $failures[] = 'Published run reports fewer assigned tasks than total tasks.';
    }

    $entryStmt = $pdo->prepare('SELECT id, run_id, room_id, day_of_week, slot_id FROM schedule_entries WHERE run_id = ? AND status = \'PUBLISHED\' LIMIT 1');
    $entryStmt->execute([(int) $published['id']]);
    $entry = $entryStmt->fetch();
    $wrongRoomStmt = $pdo->prepare('SELECT id FROM rooms WHERE id <> ? LIMIT 1');
    if ($entry) {
        $wrongRoomStmt->execute([(int) $entry['room_id']]);
        $wrongRoom = $wrongRoomStmt->fetchColumn();
        if ($wrongRoom !== false) {
            $rejected = false;
            $pdo->beginTransaction();
            try {
                $insert = $pdo->prepare('INSERT INTO schedule_occupancy (run_id, entry_id, resource_type, resource_id, day_of_week, slot_id) VALUES (?, ?, \'ROOM\', ?, ?, ?)');
                $insert->execute([(int) $entry['run_id'], (int) $entry['id'], (int) $wrongRoom, (int) $entry['day_of_week'], (int) $entry['slot_id']]);
            } catch (PDOException) {
                $rejected = true;
            } finally {
                if ($pdo->inTransaction()) { $pdo->rollBack(); }
            }
            if (!$rejected) {
                $failures[] = 'The occupancy resource-match trigger allowed a room from another entry.';
            }
        }
    }
}

$foreignKeys = $pdo->query('PRAGMA foreign_key_check')->fetchAll();
if ($foreignKeys !== []) {
    $failures[] = 'SQLite foreign-key check returned violations.';
}

if ($failures !== []) {
    foreach ($failures as $failure) {
        fwrite(STDERR, "FAIL: {$failure}\n");
    }
    exit(1);
}

fwrite(STDOUT, "PASS: published schedule integrity checks\n");
