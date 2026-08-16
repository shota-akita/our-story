<?php
session_start();

if (!isset($_SESSION['authenticated']) || $_SESSION['authenticated'] !== true) {
    header('HTTP/1.1 401 Unauthorized');
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];
if (($method === 'POST' || $method === 'DELETE') && empty($_SESSION['can_edit'])) {
    http_response_code(403);
    echo json_encode(['error' => 'Forbidden']);
    exit;
}

require_once __DIR__ . '/config.php';

const LOCATION_NAME_MAX_LENGTH = 255;

function normalize_locations(array $locations): array
{
    if ($locations === []) {
        throw new InvalidArgumentException("At least one location name is required");
    }

    $normalized = [];
    foreach ($locations as $index => $location) {
        if (!is_array($location) || !is_string($location['name'] ?? null)) {
            throw new InvalidArgumentException("Invalid location name");
        }

        $name = trim($location['name']);
        $characterCount = preg_match_all('/./us', $name);
        if ($name === '' || $characterCount === false) {
            throw new InvalidArgumentException("Location name is required");
        }
        if ($characterCount > LOCATION_NAME_MAX_LENGTH) {
            throw new InvalidArgumentException("Location name must be 255 characters or fewer");
        }
        if (preg_match('/^(?:[a-z][a-z0-9+.-]*:\/\/|www\.|(?:[a-z0-9-]+\.)+[a-z]{2,}(?:[\/:?#]|$))/i', $name) === 1) {
            throw new InvalidArgumentException("Enter a place name instead of a URL");
        }

        $normalized[] = [
            'id' => (int)($location['id'] ?? ($index + 1)),
            'name' => $name,
        ];
    }

    return $normalized;
}

$useDynamoDb = use_dynamodb();
$conn = null;

if (!$useDynamoDb) {
    $conn = db_connect('date_memories');

    if ($conn->connect_error) {
        http_response_code(500);
        echo json_encode(["error" => "Connection failed: " . $conn->connect_error]);
        exit;
    }
}

if ($method === 'GET') {
    if ($useDynamoDb) {
        try {
            echo json_encode(dynamodb_get_memories());
        } catch (Throwable $e) {
            http_response_code(500);
            echo json_encode(["error" => $e->getMessage()]);
        }
    } else {
        $result = $conn->query("SELECT * FROM memories ORDER BY date DESC");
        $memories = [];
        while ($row = $result->fetch_assoc()) {
            $loc_res = $conn->query("SELECT id, name FROM locations WHERE memory_id = " . (int)$row['id']);
            $row['locations'] = $loc_res->fetch_all(MYSQLI_ASSOC);

            $row['photoUrl'] = $row['photo_url'];
            $row['albumUrl'] = $row['album_url'];

            $memories[] = $row;
        }
        echo json_encode($memories);
    }
}

if ($method === 'POST') {
    $data = json_decode(file_get_contents("php://input"), true);
    $id = $data['id'] ?? null;
    $date = $data['date'] ?? '';
    $photoUrl = $data['photoUrl'] ?? '';
    $albumUrl = $data['albumUrl'] ?? '';
    $description = trim((string)($data['description'] ?? ''));
    $locations = $data['locations'] ?? [];

    if ($date === '' || !is_array($locations)) {
        http_response_code(400);
        echo json_encode(["error" => "Invalid payload"]);
        exit;
    }

    try {
        $normalizedLocations = normalize_locations($locations);
    } catch (InvalidArgumentException $e) {
        http_response_code(400);
        echo json_encode(["error" => $e->getMessage()]);
        exit;
    }

    if ($useDynamoDb) {
        try {
            $existing = null;
            if ($id !== null && $id !== '') {
                $existing = dynamodb_get_memory_by_id((string)$id);
            }

            $memoryId = ($id !== null && $id !== '') ? (string)$id : dynamodb_generate_memory_id();
            $now = dynamodb_now_iso8601();

            dynamodb_put_memory([
                'id' => $memoryId,
                'date' => $date,
                'photo_url' => $photoUrl,
                'album_url' => $albumUrl,
                'description' => $description,
                'locations' => $normalizedLocations,
                'created_at' => $existing['created_at'] ?? $now,
                'updated_at' => $now,
            ]);

            $responseId = ctype_digit($memoryId) ? (int)$memoryId : $memoryId;
            echo json_encode(["status" => "success", "id" => $responseId]);
        } catch (Throwable $e) {
            http_response_code(500);
            echo json_encode(["error" => $e->getMessage()]);
        }
    } else {
        $transactionActive = false;
        try {
            if (!$conn->begin_transaction()) {
                throw new RuntimeException("Failed to start transaction: " . $conn->error);
            }
            $transactionActive = true;

            if ($id && is_numeric($id)) {
                $stmt = $conn->prepare("UPDATE memories SET date=?, photo_url=?, album_url=?, description=? WHERE id=?");
                if ($stmt === false) {
                    throw new RuntimeException("Failed to prepare memory update: " . $conn->error);
                }
                if (!$stmt->bind_param("ssssi", $date, $photoUrl, $albumUrl, $description, $id)
                    || !$stmt->execute()) {
                    throw new RuntimeException("Failed to update memory: " . $stmt->error);
                }
                if ($conn->query("DELETE FROM locations WHERE memory_id = " . (int)$id) === false) {
                    throw new RuntimeException("Failed to delete locations: " . $conn->error);
                }
                $memory_id = (int)$id;
            } else {
                $stmt = $conn->prepare("INSERT INTO memories (date, photo_url, album_url, description) VALUES (?, ?, ?, ?)");
                if ($stmt === false) {
                    throw new RuntimeException("Failed to prepare memory insert: " . $conn->error);
                }
                if (!$stmt->bind_param("ssss", $date, $photoUrl, $albumUrl, $description)
                    || !$stmt->execute()) {
                    throw new RuntimeException("Failed to insert memory: " . $stmt->error);
                }
                $memory_id = $conn->insert_id;
            }

            foreach ($normalizedLocations as $location) {
                $locationName = $location['name'];
                $stmt_loc = $conn->prepare("INSERT INTO locations (memory_id, name) VALUES (?, ?)");
                if ($stmt_loc === false) {
                    throw new RuntimeException("Failed to prepare location insert: " . $conn->error);
                }
                if (!$stmt_loc->bind_param("is", $memory_id, $locationName)
                    || !$stmt_loc->execute()) {
                    throw new RuntimeException("Failed to insert location: " . $stmt_loc->error);
                }
            }
            if (!$conn->commit()) {
                throw new RuntimeException("Failed to commit transaction: " . $conn->error);
            }
            $transactionActive = false;
            echo json_encode(["status" => "success", "id" => $memory_id]);
        } catch (Throwable $e) {
            if ($transactionActive) {
                $conn->rollback();
            }
            http_response_code(500);
            echo json_encode(["error" => $e->getMessage()]);
        }
    }
}

if ($method === 'DELETE') {
    $id = $_GET['id'] ?? null;
    if ($id !== null && $id !== '') {
        if ($useDynamoDb) {
            try {
                dynamodb_delete_memory((string)$id);
                echo json_encode(["status" => "deleted"]);
            } catch (Throwable $e) {
                http_response_code(500);
                echo json_encode(["error" => $e->getMessage()]);
            }
        } elseif (is_numeric($id)) {
            $transactionActive = false;
            try {
                if (!$conn->begin_transaction()) {
                    throw new RuntimeException("Failed to start transaction: " . $conn->error);
                }
                $transactionActive = true;

                // Delete children explicitly for databases created before the cascade constraint.
                if ($conn->query("DELETE FROM locations WHERE memory_id = " . (int)$id) === false) {
                    throw new RuntimeException("Failed to delete locations: " . $conn->error);
                }
                if ($conn->query("DELETE FROM memories WHERE id = " . (int)$id) === false) {
                    throw new RuntimeException("Failed to delete memory: " . $conn->error);
                }
                if (!$conn->commit()) {
                    throw new RuntimeException("Failed to commit transaction: " . $conn->error);
                }
                $transactionActive = false;
                echo json_encode(["status" => "deleted"]);
            } catch (Throwable $e) {
                if ($transactionActive) {
                    $conn->rollback();
                }
                http_response_code(500);
                echo json_encode(["error" => $e->getMessage()]);
            }
        } else {
            http_response_code(400);
            echo json_encode(["error" => "Invalid id"]);
        }
    }
}

if ($conn instanceof mysqli) {
    $conn->close();
}
?>