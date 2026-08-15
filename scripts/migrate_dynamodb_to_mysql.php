<?php
// Migrates DynamoDB data to MySQL from the command line.
// Usage: docker compose run --rm web php scripts/migrate_dynamodb_to_mysql.php [--prune]
// Existing MySQL rows absent from DynamoDB are preserved unless --prune is used.

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only script\n");
    exit(1);
}

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../dynamo.php';

$args = array_slice($argv, 1);
$allowedOptions = ['--prune', '--include-demo-users', '--help'];
$unknownOptions = array_values(array_diff($args, $allowedOptions));
if ($unknownOptions !== []) {
    fwrite(STDERR, 'Unknown option: ' . $unknownOptions[0] . "\n");
    exit(1);
}
if (in_array('--help', $args, true)) {
    fwrite(STDOUT, implode(PHP_EOL, [
        'Usage: php scripts/migrate_dynamodb_to_mysql.php [options]',
        '',
        'Options:',
        '  --prune               Delete target rows that are absent from DynamoDB.',
        '  --include-demo-users  Include demo-editor and demo-viewer.',
        '  --help                Show this help.',
    ]) . PHP_EOL);
    exit(0);
}

$prune = in_array('--prune', $argv, true);
$includeDemoUsers = in_array('--include-demo-users', $argv, true);

// Track active transactions so failures can roll back safely.
$memTxnActive = false;
$authTxnActive = false;

/**
 * Convert a DynamoDB ID to a positive MySQL BIGINT, or reject it.
 */
function as_bigint_id($id): ?int
{
    if (is_int($id)) {
        return $id > 0 ? $id : null;
    }

    $s = (string)$id;
    if ($s === '' || !ctype_digit($s)) {
        return null;
    }

    $normalized = ltrim($s, '0');
    if ($normalized === '') {
        return null;
    }
    if (PHP_INT_SIZE < 8) {
        throw new RuntimeException('A 64-bit PHP runtime is required for BIGINT memory IDs.');
    }
    if (strlen($normalized) > 19
        || (strlen($normalized) === 19 && strcmp($normalized, '9223372036854775807') > 0)) {
        return null;
    }

    return (int)$normalized;
}

try {
    $memConn = db_connect('date_memories');
    if ($memConn->connect_error) {
        throw new RuntimeException('date_memories connect failed: ' . $memConn->connect_error);
    }
    $memConn->set_charset('utf8mb4');
    if (!$memConn->query("SET time_zone = '+00:00'")) {
        throw new RuntimeException('Failed to set MySQL time zone: ' . $memConn->error);
    }

    $authConn = db_connect('auth_system');
    if ($authConn->connect_error) {
        throw new RuntimeException('auth_system connect failed: ' . $authConn->connect_error);
    }
    $authConn->set_charset('utf8mb4');

    $memories = dynamodb_get_memories();

    $memConn->begin_transaction();
    $memTxnActive = true;

    $upMem = $memConn->prepare(
        'INSERT INTO memories (id, date, photo_url, album_url, description, created_at, updated_at)
         VALUES (?, ?, ?, ?, ?, COALESCE(NULLIF(?, \'\'), CURRENT_TIMESTAMP), COALESCE(NULLIF(?, \'\'), CURRENT_TIMESTAMP))
            AS new
         ON DUPLICATE KEY UPDATE
                date        = new.date,
                photo_url   = new.photo_url,
                album_url   = new.album_url,
                description = new.description,
                updated_at  = new.updated_at'
    );
    if ($upMem === false) {
        throw new RuntimeException('prepare memories failed: ' . $memConn->error);
    }

    $delLoc = $memConn->prepare('DELETE FROM locations WHERE memory_id = ?');
    $insLoc = $memConn->prepare('INSERT INTO locations (memory_id, name) VALUES (?, ?)');
    if ($delLoc === false || $insLoc === false) {
        throw new RuntimeException('prepare locations failed: ' . $memConn->error);
    }

    $seenIds = [];
    $migratedMemories = 0;
    $skippedMemories = 0;

    foreach ($memories as $m) {
        $memoryId = as_bigint_id($m['id'] ?? null);
        if ($memoryId === null) {
            // Reject IDs outside the signed BIGINT range.
            $skippedMemories++;
            fwrite(STDERR, 'skip memory (non-bigint id): ' . (string)($m['id'] ?? '') . "\n");
            continue;
        }

        $date        = (string)($m['date'] ?? '');
        $photo       = (string)($m['photo_url'] ?? ($m['photoUrl'] ?? ''));
        $album       = (string)($m['album_url'] ?? ($m['albumUrl'] ?? ''));
        $description = (string)($m['description'] ?? '');
        $createdAt   = (string)($m['created_at'] ?? '');
        $updatedAt   = (string)($m['updated_at'] ?? '');

        $upMem->bind_param('issssss', $memoryId, $date, $photo, $album, $description, $createdAt, $updatedAt);
        if (!$upMem->execute()) {
            throw new RuntimeException('upsert memory failed (id=' . $memoryId . '): ' . $upMem->error);
        }

        // Replace locations so each memory matches the DynamoDB source.
        $delLoc->bind_param('i', $memoryId);
        if (!$delLoc->execute()) {
            throw new RuntimeException('delete locations failed (memory_id=' . $memoryId . '): ' . $delLoc->error);
        }

        foreach (($m['locations'] ?? []) as $loc) {
            $name = is_array($loc) ? (string)($loc['name'] ?? '') : (string)$loc;
            if ($name === '') {
                continue;
            }
            $insLoc->bind_param('is', $memoryId, $name);
            if (!$insLoc->execute()) {
                throw new RuntimeException('insert location failed (memory_id=' . $memoryId . '): ' . $insLoc->error);
            }
        }

        $seenIds[] = $memoryId;
        $migratedMemories++;
    }

    // With --prune, FK cascade removes locations for deleted memories.
    $prunedMemories = 0;
    if ($prune) {
        if (count($seenIds) > 0) {
            $in = implode(',', array_map('intval', $seenIds));
            $res = $memConn->query('DELETE FROM memories WHERE id NOT IN (' . $in . ')');
        } else {
            $res = $memConn->query('DELETE FROM memories');
        }
        if ($res === false) {
            throw new RuntimeException('prune memories failed: ' . $memConn->error);
        }
        $prunedMemories = $memConn->affected_rows;
    }

    $memConn->commit();
    $memTxnActive = false;

    $users = [];
    foreach (dynamodb_scan_all(users_table_name()) as $u) {
        $users[] = $u;
    }

    $authConn->begin_transaction();
    $authTxnActive = true;

    $upUser = $authConn->prepare(
        'INSERT INTO login_users (username, password, redirect_target)
         VALUES (?, ?, ?)
            AS new
         ON DUPLICATE KEY UPDATE
                password        = new.password,
                redirect_target = new.redirect_target'
    );
    if ($upUser === false) {
        throw new RuntimeException('prepare login_users failed: ' . $authConn->error);
    }

    $seenUsers = [];
    $migratedUsers = 0;
    $skippedDemoUsers = 0;
    foreach ($users as $u) {
        $username = (string)($u['username'] ?? '');
        if (!$includeDemoUsers && in_array($username, LOCAL_DEMO_USERNAMES, true)) {
            $skippedDemoUsers++;
            continue;
        }
        if ($username === '') {
            continue;
        }
        $password = (string)($u['password'] ?? '');
        $redirect = (string)($u['redirect_target'] ?? 'index.php');

        $upUser->bind_param('sss', $username, $password, $redirect);
        if (!$upUser->execute()) {
            throw new RuntimeException('upsert user failed (' . $username . '): ' . $upUser->error);
        }
        $seenUsers[] = $username;
        $migratedUsers++;
    }

    $prunedUsers = 0;
    if ($prune) {
        if (count($seenUsers) > 0) {
            $place = implode(',', array_fill(0, count($seenUsers), '?'));
            $del = $authConn->prepare('DELETE FROM login_users WHERE username NOT IN (' . $place . ')');
            $types = str_repeat('s', count($seenUsers));
            $del->bind_param($types, ...$seenUsers);
            if (!$del->execute()) {
                throw new RuntimeException('prune users failed: ' . $del->error);
            }
            $prunedUsers = $authConn->affected_rows;
        }
    }

    $authConn->commit();
    $authTxnActive = false;

    $memConn->close();
    $authConn->close();

    fwrite(STDOUT, "Migration finished (DynamoDB -> MySQL).\n");
    fwrite(STDOUT, "- memories upserted : {$migratedMemories}\n");
    if ($skippedMemories > 0) {
        fwrite(STDOUT, "- memories skipped  : {$skippedMemories} (non-bigint id)\n");
    }
    fwrite(STDOUT, "- users upserted    : {$migratedUsers}\n");
    fwrite(STDOUT, "- demo users skipped: {$skippedDemoUsers}\n");
    if ($prune) {
        fwrite(STDOUT, "- memories pruned   : {$prunedMemories}\n");
        fwrite(STDOUT, "- users pruned      : {$prunedUsers}\n");
    }
    fwrite(STDOUT, "Source tables: " . memories_table_name() . ', ' . users_table_name() . "\n");
    exit(0);
} catch (Throwable $e) {
    // Roll back only transactions that started successfully.
    if ($memTxnActive) {
        try { $memConn->rollback(); } catch (Throwable $ignore) {}
    }
    if ($authTxnActive) {
        try { $authConn->rollback(); } catch (Throwable $ignore) {}
    }
    fwrite(STDERR, 'Migration failed: ' . $e->getMessage() . "\n");
    exit(1);
}
