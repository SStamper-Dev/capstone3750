<?php
header("Content-Type: application/json");

/* ===========================
   CONFIG / ENV
=========================== */

$DB_HOST = getenv("DB_HOST");
$DB_NAME = getenv("DB_NAME");
$DB_USER = getenv("DB_USER");
$DB_PASS = getenv("DB_PASS");
$DB_PORT = getenv("DB_PORT") ?: 3306;

$TEST_MODE = true;
$TEST_PASSWORD = "clemson-test-2026";

try {
    $pdo = new PDO(
        "mysql:host=$DB_HOST;port=$DB_PORT;dbname=$DB_NAME;charset=utf8mb4",
        $DB_USER,
        $DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(["error" => "Database connection failed"]);
    exit;
}

/* ===========================
   HELPERS
=========================== */

function json_input() {
    return json_decode(file_get_contents("php://input"), true);
}

function respond($data, $status = 200) {
    http_response_code($status);
    echo json_encode($data);
    exit;
}

function require_test_mode() {
    global $TEST_MODE, $TEST_PASSWORD;

    if (!$TEST_MODE) {
        respond(["error" => "Test mode disabled"], 403);
    }

    $headers = getallheaders();
    if (!isset($headers["X-Test-Password"]) ||
        $headers["X-Test-Password"] !== $TEST_PASSWORD) {
        respond(["error" => "Forbidden"], 403);
    }
}

/* ===========================
   ROUTER
=========================== */

$method = $_SERVER["REQUEST_METHOD"];
$path = parse_url($_SERVER["REQUEST_URI"], PHP_URL_PATH);

// Remove leading script path if needed
$path = preg_replace("#^.*/api#", "/api", $path);
$segments = explode("/", trim($path, "/"));

/* ===========================
   PRODUCTION ENDPOINTS
=========================== */

// POST /api/reset
if ($method === "POST" && $path === "/api/reset") {
    // Stub: truncate tables later
    respond(["status" => "reset"]);
}

// POST /api/players
if ($method === "POST" && $path === "/api/players") {
    $data = json_input();
    if (!isset($data["username"])) {
        respond(["error" => "Username required"], 400);
    }

    // Stub insert
    $player_id = rand(1, 9999);

    respond(["player_id" => $player_id], 201);
}

// GET /api/players/{id}/stats
if ($method === "GET" && preg_match("#^/api/players/(\d+)/stats$#", $path, $m)) {
    $player_id = $m[1];

    // Stub stats
    respond([
        "games_played" => 0,
        "wins" => 0,
        "losses" => 0,
        "total_shots" => 0,
        "total_hits" => 0,
        "accuracy" => 0.0
    ]);
}

// POST /api/games
if ($method === "POST" && $path === "/api/games") {
    $data = json_input();

    if (!isset($data["creator_id"], $data["grid_size"], $data["max_players"])) {
        respond(["error" => "Missing fields"], 400);
    }

    if ($data["grid_size"] < 5 || $data["grid_size"] > 15) {
        respond(["error" => "Invalid grid size"], 400);
    }

    if ($data["max_players"] < 1) {
        respond(["error" => "Invalid max players"], 400);
    }

    $game_id = rand(1, 9999);

    respond(["game_id" => $game_id], 201);
}

// POST /api/games/{id}/join
if ($method === "POST" && preg_match("#^/api/games/(\d+)/join$#", $path, $m)) {
    respond(["status" => "joined"]);
}

// GET /api/games/{id}
if ($method === "GET" && preg_match("#^/api/games/(\d+)$#", $path, $m)) {
    respond([
        "game_id" => (int)$m[1],
        "grid_size" => 8,
        "status" => "waiting",
        "current_turn_index" => 0,
        "active_players" => 1
    ]);
}

// POST /api/games/{id}/place
if ($method === "POST" && preg_match("#^/api/games/(\d+)/place$#", $path)) {
    $data = json_input();

    if (!isset($data["player_id"], $data["ships"])) {
        respond(["error" => "Invalid request"], 400);
    }

    if (count($data["ships"]) !== 3) {
        respond(["error" => "Exactly 3 ships required"], 400);
    }

    respond(["status" => "ships placed"]);
}

// POST /api/games/{id}/fire
if ($method === "POST" && preg_match("#^/api/games/(\d+)/fire$#", $path)) {
    $data = json_input();

    if (!isset($data["player_id"], $data["row"], $data["col"])) {
        respond(["error" => "Invalid request"], 400);
    }

    // Stub response
    respond([
        "result" => "miss",
        "next_player_id" => null,
        "game_status" => "active"
    ]);
}

// GET /api/games/{id}/moves
if ($method === "GET" && preg_match("#^/api/games/(\d+)/moves$#", $path)) {
    respond([
        "moves" => []
    ]);
}

/* ===========================
   TEST MODE ENDPOINTS
=========================== */

// POST /api/test/games/{id}/restart
if ($method === "POST" &&
    preg_match("#^/api/test/games/(\d+)/restart$#", $path)) {

    require_test_mode();
    respond(["status" => "restarted"]);
}

// POST /api/test/games/{id}/ships
if ($method === "POST" &&
    preg_match("#^/api/test/games/(\d+)/ships$#", $path)) {

    require_test_mode();
    respond(["status" => "ships set"]);
}

// GET /api/test/games/{id}/board/{player_id}
if ($method === "GET" &&
    preg_match("#^/api/test/games/(\d+)/board/(\d+)$#", $path)) {

    require_test_mode();
    respond([
        "board" => []
    ]);
}

/* ===========================
   FALLBACK
=========================== */

respond(["error" => "Endpoint not found"], 404);