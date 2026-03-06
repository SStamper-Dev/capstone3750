<?php
header("Content-Type: application/json");

/* ===========================
   CONFIG / ENV
=========================== */

//load local environment variables if file exists
if (file_exists(__DIR__ . '/.env.local.php')) {
    require __DIR__ . '/.env.local.php';
}

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
    try {
        // Only truncate Battleship tables because it seems the Solo Project is also in this database
        $tables = [
            "move",
            "ship",
            "game_player",
            "game",
            "player"
        ];

        // Disable FK checks
        $pdo->exec("SET FOREIGN_KEY_CHECKS = 0");

        foreach ($tables as $table) {
            $pdo->exec("TRUNCATE TABLE `$table`");
        }

        // Re-enable FK checks
        $pdo->exec("SET FOREIGN_KEY_CHECKS = 1");

        respond(["status" => "reset"], 200);

    } catch (Exception $e) {
        respond(["error" => "Failed to reset database"], 500);
    }
}

// POST /api/players
if ($method === "POST" && $path === "/api/players") {
    $data = json_input();

    if (!isset($data["username"]) || trim($data["username"]) === "") {
        respond(["error" => "Username required"], 400);
    }

    try {
        $stmt = $pdo->prepare("INSERT INTO player (username) VALUES (:username)");
        $stmt->execute([
            ":username" => $data["username"]
        ]);

        $player_id = $pdo->lastInsertId();

        respond(["player_id" => (int)$player_id], 201);

    } catch (PDOException $e) {
        respond(["error" => "Failed to create player"], 500);
    }
}

// GET /api/players/{id}/stats
if ($method === "GET" && preg_match("#^/api/players/(\d+)/stats$#", $path, $m)) {
    $player_id = $m[1]; // Extract player ID from URL

    // query player table get their total_wins, total_losses, total_shots, and total_hits fields
    $stmt = $pdo->prepare("
        SELECT total_wins, total_losses, total_shots, total_hits
        FROM player
        WHERE player_id = :player_id
    ");
    $stmt->execute([":player_id" => $player_id]);
    $stats = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$stats) {
        respond(["error" => "Player not found"], 404);
    }
    else{
        //add total_wins and total_losses and save it in response as games_played
        $stats["games_played"] = $stats["total_wins"] + $stats["total_losses"];
        //calcuate accuracy as total_hits / total_shots and save it in response as accuracy as a decimal value
        $stats["accuracy"] = $stats["total_shots"] > 0 ? $stats["total_hits"] / $stats["total_shots"] : 0;
        respond($stats);
    }
    
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

    $stmt = $pdo->prepare("
        INSERT INTO game (grid_size, status, current_turn_index, created_at, max_players)
        VALUES (:grid_size, 'waiting', 0, NOW(), :max_players)
        ");

        $stmt->execute([
            ":grid_size" => $data["grid_size"],
            ":max_players" => $data["max_players"]
        ]);

    $game_id = $pdo->lastInsertId();

    respond(["game_id" => $game_id], 201);
}

// POST /api/games/{id}/join
if ($method === "POST" && preg_match("#^/api/games/(\d+)/join$#", $path, $m)) {
    $game_id = $m[1]; // Extract game ID from URL
    $data = json_input();
    if (!isset($data["player_id"])) {
        respond(["error" => "Player ID required"], 400);
    }
    else {
        $player_id = $data["player_id"];
        // Check if game exists and is waiting for players
        $stmt = $pdo->prepare("SELECT * FROM game WHERE game_id = :game_id");
        $stmt->execute([":game_id" => $game_id]);
        $game = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$game) {
            respond(["error" => "Game not found"], 404);
        }
        if ($game["status"] !== "waiting") {
            respond(["error" => "Game is not accepting players"], 400);
        }
        // Check if player is already in the game
        $stmt = $pdo->prepare("SELECT * FROM game_player WHERE game_id = :game_id AND player_id = :player_id");
        $stmt->execute([
            ":game_id" => $game_id,
            ":player_id" => $player_id
        ]);
        if ($stmt->fetch(PDO::FETCH_ASSOC)) {
            respond(["error" => "Player already in game"], 400);
        }
        // Check if game is full
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM game_player WHERE game_id = :game_id");
        $stmt->execute([":game_id" => $game_id]);
        $player_count = $stmt->fetchColumn();
        if ($player_count >= $game["max_players"]) {
            respond(["error" => "Game is full"], 400);
        }
        // Figure out turn order for new player (max existing turn_order for current game id+ 1)
        $stmt = $pdo->prepare("SELECT MAX(turn_order) AS max_turn_order FROM game_player WHERE game_id = :game_id");
        $stmt->execute([":game_id" => $game_id]);
        $max_turn_order = $stmt->fetchColumn();
        $turn_order = $max_turn_order !== null ? $max_turn_order + 1 : 0;
        // Add player to game
        $stmt = $pdo->prepare("INSERT INTO game_player (game_id, player_id, turn_order, is_out, joined_at, has_placed_ships) VALUES (:game_id, :player_id, :turn_order, 0, NOW(), 0)");
        $stmt->execute([
            ":game_id" => $game_id,
            ":player_id" => $player_id,
            ":turn_order" => $turn_order
        ]);

        respond(["status" => "joined"]);
    }
}

// GET /api/games/{id}
if ($method === "GET" && preg_match("#^/api/games/(\d+)$#", $path, $m)) {
    $game_id = $m[1]; // Extract game ID from URL

    $stmt = $pdo->prepare("SELECT 
            g.game_id,
            g.grid_size,
            g.status,
            g.current_turn_index,
            COUNT(gp.player_id) AS active_players
            FROM game g
            LEFT JOIN game_player gp ON g.game_id = gp.game_id
            WHERE g.game_id = :game_id
            GROUP BY g.game_id");
    $stmt->execute([":game_id" => $game_id]);
    $game = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$game) {
        respond(["error" => "Game not found"], 404);
    } else {
        respond($game);
    }
}

// POST /api/games/{id}/place
if ($method === "POST" && preg_match("#^/api/games/(\d+)/place$#", $path, $m)) {
    $data = json_input();

    if (!isset($data["player_id"], $data["ships"])) {
        respond(["error" => "Invalid request"], 400);
    }

    if (count($data["ships"]) !== 3) {
        respond(["error" => "Exactly 3 ships required"], 400);
    }
    //check that "row" and "col" are present for each ship, they are within the grid bounds, and that no two ships occupy the same cell
    $positions = [];
    $game_id = $m[1]; // Extract game ID from URL
    // Get grid size for the game
    $stmt = $pdo->prepare("SELECT grid_size FROM game WHERE game_id = :game_id");
    $stmt->execute([":game_id" => $game_id]);
    $game = $stmt->fetch(PDO::FETCH_ASSOC);
    
    foreach ($data["ships"] as $ship) {
        if (!isset($ship["row"], $ship["col"])) {
            respond(["error" => "Each ship must have row and col"], 400);
        }
        if ($ship["row"] < 0 || $ship["row"] >= $game["grid_size"] || $ship["col"] < 0 || $ship["col"] >= $game["grid_size"]) {
            respond(["error" => "Ship positions must be within grid bounds"], 400);
        }
        $pos_key = $ship["row"] . "," . $ship["col"];
        if (in_array($pos_key, $positions)) {
            respond(["error" => "Ships cannot occupy the same cell"], 400);
        }
        $positions[] = $pos_key;
    }

    //create a record in "ship" table with game_id, player_id, x_cord, y_cord, and is_hit as false
    $stmt = $pdo->prepare("INSERT INTO ship (game_id, player_id, x_cord, y_cord, is_hit) VALUES (:game_id, :player_id, :x_cord, :y_cord, 0)");
    foreach ($data["ships"] as $ship) {
        $stmt->execute([
            ":game_id" => $game_id,
            ":player_id" => $data["player_id"],
            ":x_cord" => $ship["row"],
            ":y_cord" => $ship["col"]
        ]);
    }

    //set has_placed_ships to true for the player in game_player table
    $stmt = $pdo->prepare("UPDATE game_player SET has_placed_ships = 1 WHERE game_id = :game_id AND player_id = :player_id");
    $stmt->execute([
        ":game_id" => $game_id,
        ":player_id" => $data["player_id"]
    ]);

    respond(["status" => "ships placed"]);
}

// POST /api/games/{id}/fire
if ($method === "POST" && preg_match("#^/api/games/(\d+)/fire$#", $path, $m)) {
    $data = json_input();
    $game_id = $m[1]; // Extract game ID from URL

    if (!isset($data["player_id"], $data["row"], $data["col"])) {
        respond(["error" => "Invalid request"], 400);
    }

    // Check if it's the player's turn, if the game is active, and if the player is not out
    $stmt = $pdo->prepare("SELECT 
            g.status,
            g.current_turn_index,
            gp.turn_order,
            gp.is_out
            FROM game g
            JOIN game_player gp ON g.game_id = gp.game_id
            WHERE g.game_id = :game_id AND gp.player_id = :player_id"); 
    $stmt->execute([
        ":game_id" => $game_id,
        ":player_id" => $data["player_id"]
    ]);
    $player_info = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$player_info) {
        respond(["error" => "Player not in game"], 404);
    }
    if ($player_info["status"] !== "active") {
        respond(["error" => "Game is not active"], 400);
    }
    if ($player_info["is_out"]) {
        respond(["error" => "Player is out"], 400);
    }
    if ($player_info["current_turn_index"] != $player_info["turn_order"]) {
        respond(["error" => "Not player's turn"], 400);
    }

    // Check if the shot is a hit or miss, ignoring your ships
    $stmt = $pdo->prepare("SELECT * FROM ship WHERE game_id = :game_id AND player_id != :player_id AND x_cord = :row AND y_cord = :col");
    $stmt->execute([
        ":game_id" => $game_id,
        ":player_id" => $data["player_id"],
        ":row" => $data["row"],
        ":col" => $data["col"]
    ]);
    $ship = $stmt->fetch(PDO::FETCH_ASSOC);
    $is_hit = $ship ? true : false;

    // If it's a hit, update the ship's is_hit to true and check if all ships for the owner of the hit ship are now hit, if so mark that player as out in game_player table
    if ($is_hit) {
        $stmt = $pdo->prepare("UPDATE ship SET is_hit = 1 WHERE ship_id = :ship_id");
        $stmt->execute([":ship_id" => $ship["ship_id"]]);

        // Check if all ships for the owner of the hit ship are now hit
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM ship WHERE game_id = :game_id AND player_id = :owner_id AND is_hit = 0");
        $stmt->execute([
            ":game_id" => $game_id,
            ":owner_id" => $ship["player_id"]
        ]);
        $remaining_ships = $stmt->fetchColumn();
        if ($remaining_ships == 0) {
            // Mark that player as out in game_player table
            $stmt = $pdo->prepare("UPDATE game_player SET is_out = 1 WHERE game_id = :game_id AND player_id = :owner_id");
            $stmt->execute([
                ":game_id" => $game_id,
                ":owner_id" => $ship["player_id"]
            ]);

            // Check if only one player is left, if so mark game as finished
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM game_player WHERE game_id = :game_id AND is_out = 0");
            $stmt->execute([":game_id" => $game_id]);
            $remaining_players = $stmt->fetchColumn();
            if ($remaining_players == 1) {
                $stmt = $pdo->prepare("UPDATE game SET status = 'finished' WHERE game_id = :game_id");
                $stmt->execute([":game_id" => $game_id]);
            }
        }
    }

    //create a record in "move" table with game_id, player_id, x_cord, y_cord, result (enum with 'hit' or 'miss'), and made_at (timestamp)
    $stmt = $pdo->prepare("INSERT INTO move (game_id, player_id, x_cord, y_cord, result, made_at) VALUES (:game_id, :player_id, :x_cord, :y_cord, :result, NOW())");
    $stmt->execute([
        ":game_id" => $game_id,
        ":player_id" => $data["player_id"],
        ":x_cord" => $data["row"],
        ":y_cord" => $data["col"],
        ":result" => $is_hit ? "hit" : "miss"
    ]);

    // Update current_turn_index to the next active player in the game, wrapped around to the lowest if last player just went
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM game_player WHERE game_id = :game_id AND is_out = 0");
    $stmt->execute([":game_id" => $game_id]);
    $total_active_players = $stmt->fetchColumn();
    if ($total_active_players > 1) {
        $stmt = $pdo->prepare("SELECT turn_order FROM game_player WHERE game_id = :game_id AND player_id = :player_id");
        $stmt->execute([
            ":game_id" => $game_id,
            ":player_id" => $data["player_id"]
        ]);
        $current_turn_order = $stmt->fetchColumn();

        // Find the next active player
        $stmt = $pdo->prepare("SELECT turn_order FROM game_player WHERE game_id = :game_id AND is_out = 0 AND turn_order > :current_turn_order ORDER BY turn_order ASC LIMIT 1");
        $stmt->execute([
            ":game_id" => $game_id,
            ":current_turn_order" => $current_turn_order
        ]);
        $next_turn_order = $stmt->fetchColumn();

        // If there is no next player with a higher turn order, wrap around to the lowest turn order
        if ($next_turn_order === false) {
            $stmt = $pdo->prepare("SELECT turn_order FROM game_player WHERE game_id = :game_id AND is_out = 0 ORDER BY turn_order ASC LIMIT 1");
            $stmt->execute([":game_id" => $game_id]);
            $next_turn_order = $stmt->fetchColumn();
        }

        // Update current_turn_index to the next player's turn order
        $stmt = $pdo->prepare("UPDATE game SET current_turn_index = :next_turn_order WHERE game_id = :game_id");
        $stmt->execute([
            ":next_turn_order" => $next_turn_order,
            ":game_id" => $game_id
        ]);
    }

    // Build response with result of shot ('hit' or 'miss'), next_player_id (null if game finished), game_status, and winner_id (if game finished)
    $response = [
        "result" => $is_hit ? "hit" : "miss",
        "game_status" => $total_active_players > 1 ? "active" : "finished"
    ];
    if ($response["game_status"] === "finished") {
        // Get winner_id
        $stmt = $pdo->prepare("SELECT player_id FROM game_player WHERE game_id = :game_id AND is_out = 0");
        $stmt->execute([":game_id" => $game_id]);
        $winner_id = $stmt->fetchColumn();
        $response["winner_id"] = (int)$winner_id;

        // Update players' total_wins, total_losses, total_shots, and total_hits
        // Winner gets a win and shots/hits from their moves, losers get a loss and shots/hits from their moves
        $stmt = $pdo->prepare("SELECT player_id FROM game_player WHERE game_id = :game_id");
        $stmt->execute([":game_id" => $game_id]);
        $players = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($players as $player) {
            $player_id = $player["player_id"];
            // Get shots and hits for this player
            $stmt = $pdo->prepare("SELECT COUNT(*) AS shots, SUM(result = 'hit') AS hits FROM move WHERE game_id = :game_id AND player_id = :player_id");
            $stmt->execute([
                ":game_id" => $game_id,
                ":player_id" => $player_id
            ]);
            $stats = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($player_id == $winner_id) {
                // Update winner's stats
                $stmt = $pdo->prepare("UPDATE player SET total_wins = total_wins + 1, total_shots = total_shots + :shots, total_hits = total_hits + :hits WHERE player_id = :player_id");
                $stmt->execute([
                    ":shots" => $stats["shots"],
                    ":hits" => $stats["hits"],
                    ":player_id" => $player_id
                ]);
            } else {
                // Update loser's stats
                $stmt = $pdo->prepare("UPDATE player SET total_losses = total_losses + 1, total_shots = total_shots + :shots, total_hits = total_hits + :hits WHERE player_id = :player_id");
                $stmt->execute([
                    ":shots" => $stats["shots"],
                    ":hits" => $stats["hits"],
                    ":player_id" => $player_id
                ]);
            }
        }
    } else {
        // Get next_player_id
        $stmt = $pdo->prepare("SELECT player_id FROM game_player WHERE game_id = :game_id AND turn_order = :next_turn_order");
        $stmt->execute([
            ":game_id" => $game_id,
            ":next_turn_order" => $next_turn_order
        ]);
        $next_player_id = $stmt->fetchColumn();
        $response["next_player_id"] = (int)$next_player_id;
    }
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