<?php
// Migrates MySQL data to DynamoDB from the command line.
// Existing DynamoDB items absent from MySQL are preserved unless --prune is used.

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../dynamo.php';

function mysql_to_dynamodb_usage(): string
{
    return implode(PHP_EOL, [
        'Usage: php scripts/migrate_mysql_to_dynamodb.php [options]',
        '',
        'Options:',
        '  --dry-run             Validate source and target without writing.',
        '  --prune               Delete target items that are absent from MySQL.',
        '  --include-demo-users  Include demo-editor and demo-viewer.',
        '  --help                Show this help.',
    ]) . PHP_EOL;
}

function mysql_to_dynamodb_parse_options(array $args): array
{
    $options = [
        'dry_run' => false,
        'prune' => false,
        'include_demo_users' => false,
        'help' => false,
    ];

    foreach ($args as $arg) {
        switch ($arg) {
            case '--dry-run':
                $options['dry_run'] = true;
                break;
            case '--prune':
                $options['prune'] = true;
                break;
            case '--include-demo-users':
                $options['include_demo_users'] = true;
                break;
            case '--help':
                $options['help'] = true;
                break;
            default:
                throw new InvalidArgumentException('Unknown option: ' . $arg);
        }
    }

    return $options;
}

function mysql_to_dynamodb_iso8601(string $value): string
{
    $timestamp = DateTimeImmutable::createFromFormat(
        'Y-m-d H:i:s',
        $value,
        new DateTimeZone('UTC')
    );
    $errors = DateTimeImmutable::getLastErrors();

    if ($timestamp === false
        || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
        throw new RuntimeException('Invalid MySQL timestamp: ' . $value);
    }

    return $timestamp->format(DateTimeInterface::ATOM);
}

function mysql_to_dynamodb_load_snapshot(mysqli $conn, bool $includeDemoUsers): array
{
    $memoryRows = $conn->query(
        'SELECT id, date, photo_url, album_url, description, created_at, updated_at
         FROM memories ORDER BY id ASC'
    );
    if ($memoryRows === false) {
        throw new RuntimeException('Failed to read memories: ' . $conn->error);
    }

    $memories = [];
    while ($row = $memoryRows->fetch_assoc()) {
        $id = (int)$row['id'];
        if ($id <= 0) {
            throw new RuntimeException('Invalid MySQL memory ID: ' . (string)$row['id']);
        }

        $key = (string)$id;
        $memories[$key] = [
            'id' => $key,
            'date' => (string)$row['date'],
            'photo_url' => (string)($row['photo_url'] ?? ''),
            'album_url' => (string)($row['album_url'] ?? ''),
            'description' => (string)($row['description'] ?? ''),
            'locations' => [],
            'created_at' => mysql_to_dynamodb_iso8601((string)$row['created_at']),
            'updated_at' => mysql_to_dynamodb_iso8601((string)$row['updated_at']),
        ];
    }

    $locationRows = $conn->query(
        'SELECT id, memory_id, name FROM locations ORDER BY memory_id ASC, id ASC'
    );
    if ($locationRows === false) {
        throw new RuntimeException('Failed to read locations: ' . $conn->error);
    }

    $locationCount = 0;
    while ($row = $locationRows->fetch_assoc()) {
        $memoryId = (string)(int)$row['memory_id'];
        if (!isset($memories[$memoryId])) {
            throw new RuntimeException('Orphan location for memory ID: ' . $memoryId);
        }

        $memories[$memoryId]['locations'][] = [
            'id' => (int)$row['id'],
            'name' => (string)$row['name'],
        ];
        $locationCount++;
    }

    $userRows = $conn->query(
        'SELECT username, password, redirect_target
         FROM auth_system.login_users ORDER BY username ASC'
    );
    if ($userRows === false) {
        throw new RuntimeException('Failed to read login users: ' . $conn->error);
    }

    $users = [];
    $skippedDemoUsers = 0;
    while ($row = $userRows->fetch_assoc()) {
        $username = (string)$row['username'];
        if (!$includeDemoUsers && in_array($username, LOCAL_DEMO_USERNAMES, true)) {
            $skippedDemoUsers++;
            continue;
        }

        $password = (string)$row['password'];
        $redirectTarget = (string)$row['redirect_target'];
        if ($username === '' || $password === '') {
            throw new RuntimeException('Login user has an empty username or password hash.');
        }
        if (!in_array($redirectTarget, ['index.php', 'index2.php'], true)) {
            throw new RuntimeException('Invalid redirect target for user: ' . $username);
        }

        $users[$username] = [
            'username' => $username,
            'password' => $password,
            'redirect_target' => $redirectTarget,
        ];
    }

    return [
        'memories' => $memories,
        'locations' => $locationCount,
        'users' => $users,
        'skipped_demo_users' => $skippedDemoUsers,
    ];
}

function mysql_to_dynamodb_read_source(bool $includeDemoUsers): array
{
    $conn = db_connect('date_memories');
    if ($conn->connect_error) {
        throw new RuntimeException('MySQL connection failed: ' . $conn->connect_error);
    }

    $transactionActive = false;
    try {
        if (!$conn->set_charset('utf8mb4')) {
            throw new RuntimeException('Failed to set MySQL charset: ' . $conn->error);
        }
        if (!$conn->query("SET time_zone = '+00:00'")) {
            throw new RuntimeException('Failed to set MySQL time zone: ' . $conn->error);
        }
        if (!$conn->begin_transaction(
            MYSQLI_TRANS_START_READ_ONLY | MYSQLI_TRANS_START_WITH_CONSISTENT_SNAPSHOT
        )) {
            throw new RuntimeException('Failed to start MySQL snapshot: ' . $conn->error);
        }
        $transactionActive = true;

        $snapshot = mysql_to_dynamodb_load_snapshot($conn, $includeDemoUsers);
        if (!$conn->commit()) {
            throw new RuntimeException('Failed to close MySQL snapshot: ' . $conn->error);
        }
        $transactionActive = false;

        return $snapshot;
    } catch (Throwable $e) {
        if ($transactionActive) {
            $conn->rollback();
        }
        throw $e;
    } finally {
        $conn->close();
    }
}

function mysql_to_dynamodb_assert_table(string $tableName, string $keyName): void
{
    $result = dynamodb_client()->describeTable(['TableName' => $tableName]);
    $table = $result['Table'] ?? [];
    $keySchema = $table['KeySchema'] ?? [];
    $definitions = $table['AttributeDefinitions'] ?? [];

    if (count($keySchema) !== 1
        || ($keySchema[0]['AttributeName'] ?? '') !== $keyName
        || ($keySchema[0]['KeyType'] ?? '') !== 'HASH') {
        throw new RuntimeException($tableName . ' must use ' . $keyName . ' as its only partition key.');
    }

    foreach ($definitions as $definition) {
        if (($definition['AttributeName'] ?? '') === $keyName
            && ($definition['AttributeType'] ?? '') === 'S') {
            return;
        }
    }

    throw new RuntimeException($tableName . ' partition key must be a String.');
}

function mysql_to_dynamodb_existing_keys(string $tableName, string $keyName): array
{
    $keys = [];
    foreach (dynamodb_scan_all($tableName) as $item) {
        if (!array_key_exists($keyName, $item) || (string)$item[$keyName] === '') {
            throw new RuntimeException('Target item is missing key ' . $keyName . ' in ' . $tableName . '.');
        }
        $keys[(string)$item[$keyName]] = true;
    }

    return $keys;
}

function mysql_to_dynamodb_keys_to_prune(array $existing, array $source): array
{
    $keys = array_values(array_diff(array_keys($existing), array_keys($source)));
    sort($keys, SORT_STRING);
    return $keys;
}

function mysql_to_dynamodb_run(array $args): int
{
    try {
        if (PHP_SAPI !== 'cli') {
            throw new RuntimeException('CLI only script.');
        }

        $options = mysql_to_dynamodb_parse_options($args);
        if ($options['help']) {
            fwrite(STDOUT, mysql_to_dynamodb_usage());
            return 0;
        }

        $source = mysql_to_dynamodb_read_source($options['include_demo_users']);
        $memoryTable = memories_table_name();
        $userTable = users_table_name();

        mysql_to_dynamodb_assert_table($memoryTable, 'id');
        mysql_to_dynamodb_assert_table($userTable, 'username');

        $memoryKeysToPrune = [];
        $userKeysToPrune = [];
        if ($options['prune']) {
            $memoryKeysToPrune = mysql_to_dynamodb_keys_to_prune(
                mysql_to_dynamodb_existing_keys($memoryTable, 'id'),
                $source['memories']
            );
            $userKeysToPrune = mysql_to_dynamodb_keys_to_prune(
                mysql_to_dynamodb_existing_keys($userTable, 'username'),
                $source['users']
            );
        }

        fwrite(STDOUT, "MySQL -> DynamoDB migration plan:\n");
        fwrite(STDOUT, '- memories : ' . count($source['memories']) . "\n");
        fwrite(STDOUT, '- locations: ' . $source['locations'] . "\n");
        fwrite(STDOUT, '- users    : ' . count($source['users']) . "\n");
        fwrite(STDOUT, '- demo users skipped: ' . $source['skipped_demo_users'] . "\n");
        fwrite(STDOUT, 'Target tables: ' . $memoryTable . ', ' . $userTable . "\n");

        if ($options['dry_run']) {
            if ($options['prune']) {
                fwrite(STDOUT, '- memories to prune: ' . count($memoryKeysToPrune) . "\n");
                fwrite(STDOUT, '- users to prune   : ' . count($userKeysToPrune) . "\n");
            }
            fwrite(STDOUT, "Dry run finished. No DynamoDB items changed.\n");
            return 0;
        }

        foreach ($source['memories'] as $memory) {
            dynamodb_put_memory($memory);
        }
        foreach ($source['users'] as $user) {
            dynamodb_put_user($user);
        }
        foreach ($memoryKeysToPrune as $id) {
            dynamodb_delete_memory($id);
        }
        foreach ($userKeysToPrune as $username) {
            dynamodb_delete_user($username);
        }

        fwrite(STDOUT, "Migration finished (MySQL -> DynamoDB).\n");
        if ($options['prune']) {
            fwrite(STDOUT, '- memories pruned: ' . count($memoryKeysToPrune) . "\n");
            fwrite(STDOUT, '- users pruned   : ' . count($userKeysToPrune) . "\n");
        }
        return 0;
    } catch (Throwable $e) {
        fwrite(STDERR, 'Migration failed: ' . $e->getMessage() . "\n");
        return 1;
    }
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === realpath(__FILE__)) {
    exit(mysql_to_dynamodb_run(array_slice($argv, 1)));
}