<?php

require_once __DIR__ . '/config.php';

use Aws\DynamoDb\DynamoDbClient;
use Aws\DynamoDb\Marshaler;

function ensure_aws_sdk_loaded(): void
{
    static $loaded = false;
    if ($loaded) {
        return;
    }

    if (class_exists(DynamoDbClient::class) && class_exists(Marshaler::class)) {
        $loaded = true;
        return;
    }

    $autoload = __DIR__ . '/vendor/autoload.php';
    if (!is_readable($autoload)) {
        throw new RuntimeException('AWS SDK not found. Build image with composer install first.');
    }

    require_once $autoload;
    $loaded = true;
}

function dynamodb_client(): DynamoDbClient
{
    static $client = null;
    if ($client instanceof DynamoDbClient) {
        return $client;
    }

    ensure_aws_sdk_loaded();

    $config = [
        'version' => 'latest',
        'region' => getenv('AWS_REGION') ?: 'ap-northeast-1',
    ];

    $accessKey = getenv('AWS_ACCESS_KEY_ID') ?: '';
    $secretKey = getenv('AWS_SECRET_ACCESS_KEY') ?: '';
    if ($accessKey !== '' && $secretKey !== '') {
        $credentials = [
            'key' => $accessKey,
            'secret' => $secretKey,
        ];

        $sessionToken = getenv('AWS_SESSION_TOKEN') ?: '';
        if ($sessionToken !== '') {
            $credentials['token'] = $sessionToken;
        }

        $config['credentials'] = $credentials;
    }

    $client = new DynamoDbClient($config);
    return $client;
}

function dynamodb_marshaler(): Marshaler
{
    static $marshaler = null;
    if ($marshaler instanceof Marshaler) {
        return $marshaler;
    }

    ensure_aws_sdk_loaded();
    $marshaler = new Marshaler();
    return $marshaler;
}

function memories_table_name(): string
{
    return getenv('DYNAMODB_MEMORIES_TABLE') ?: 'our-story-memories';
}

function users_table_name(): string
{
    return getenv('DYNAMODB_USERS_TABLE') ?: 'our-story-users';
}

function dynamodb_now_iso8601(): string
{
    return gmdate('c');
}

function normalize_memory_item(array $item): array
{
    $item['id'] = (string)($item['id'] ?? '');
    $item['date'] = (string)($item['date'] ?? '');
    $item['photo_url'] = (string)($item['photo_url'] ?? '');
    $item['album_url'] = (string)($item['album_url'] ?? '');
    $item['description'] = (string)($item['description'] ?? '');
    $item['created_at'] = (string)($item['created_at'] ?? '');
    $item['updated_at'] = (string)($item['updated_at'] ?? '');

    $locations = $item['locations'] ?? [];
    if (!is_array($locations)) {
        $locations = [];
    }

    $normalizedLocations = [];
    foreach ($locations as $index => $location) {
        $name = '';
        if (is_array($location)) {
            $name = (string)($location['name'] ?? '');
        } elseif (is_string($location)) {
            $name = $location;
        }

        if ($name === '') {
            continue;
        }

        $normalizedLocations[] = [
            'id' => (int)($location['id'] ?? ($index + 1)),
            'name' => $name,
        ];
    }

    $item['locations'] = $normalizedLocations;
    $item['photoUrl'] = $item['photo_url'];
    $item['albumUrl'] = $item['album_url'];

    if (ctype_digit($item['id'])) {
        $item['id'] = (int)$item['id'];
    }

    return $item;
}

function dynamodb_scan_all(string $tableName): array
{
    $client = dynamodb_client();
    $marshaler = dynamodb_marshaler();

    $lastKey = null;
    $items = [];

    do {
        $params = ['TableName' => $tableName];
        if ($lastKey !== null) {
            $params['ExclusiveStartKey'] = $lastKey;
        }

        $result = $client->scan($params);
        foreach ($result['Items'] ?? [] as $rawItem) {
            $items[] = $marshaler->unmarshalItem($rawItem);
        }

        $lastKey = $result['LastEvaluatedKey'] ?? null;
    } while ($lastKey !== null);

    return $items;
}

function dynamodb_get_memories(): array
{
    $rawItems = dynamodb_scan_all(memories_table_name());
    $items = array_map('normalize_memory_item', $rawItems);

    usort($items, static function ($a, $b) {
        return strcmp((string)$b['date'], (string)$a['date']);
    });

    return $items;
}

function dynamodb_get_memory_by_id(string $id): ?array
{
    $client = dynamodb_client();
    $marshaler = dynamodb_marshaler();

    $result = $client->getItem([
        'TableName' => memories_table_name(),
        'Key' => $marshaler->marshalItem(['id' => $id]),
        'ConsistentRead' => true,
    ]);

    if (!isset($result['Item'])) {
        return null;
    }

    return normalize_memory_item($marshaler->unmarshalItem($result['Item']));
}

function dynamodb_generate_memory_id(): string
{
    return (string)(int)round(microtime(true) * 1000) . (string)random_int(100, 999);
}

function dynamodb_put_memory(array $item): void
{
    $client = dynamodb_client();
    $marshaler = dynamodb_marshaler();

    $client->putItem([
        'TableName' => memories_table_name(),
        'Item' => $marshaler->marshalItem($item),
    ]);
}

function dynamodb_delete_memory(string $id): void
{
    $client = dynamodb_client();
    $marshaler = dynamodb_marshaler();

    $client->deleteItem([
        'TableName' => memories_table_name(),
        'Key' => $marshaler->marshalItem(['id' => $id]),
    ]);
}

function dynamodb_find_user(string $username): ?array
{
    $client = dynamodb_client();
    $marshaler = dynamodb_marshaler();

    $result = $client->getItem([
        'TableName' => users_table_name(),
        'Key' => $marshaler->marshalItem(['username' => $username]),
        'ConsistentRead' => true,
    ]);

    if (!isset($result['Item'])) {
        return null;
    }

    $item = $marshaler->unmarshalItem($result['Item']);

    return [
        'username' => (string)($item['username'] ?? ''),
        'password' => (string)($item['password'] ?? ''),
        'redirect_target' => (string)($item['redirect_target'] ?? ''),
    ];
}

function dynamodb_put_user(array $user): void
{
    $client = dynamodb_client();
    $marshaler = dynamodb_marshaler();

    $client->putItem([
        'TableName' => users_table_name(),
        'Item' => $marshaler->marshalItem([
            'username' => (string)$user['username'],
            'password' => (string)$user['password'],
            'redirect_target' => (string)$user['redirect_target'],
        ]),
    ]);
}

function dynamodb_delete_user(string $username): void
{
    $client = dynamodb_client();
    $marshaler = dynamodb_marshaler();

    $client->deleteItem([
        'TableName' => users_table_name(),
        'Key' => $marshaler->marshalItem(['username' => $username]),
    ]);
}
