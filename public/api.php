<?php
// Point d'entrée léger interrogé par le navigateur pour savoir si une salle, la liste des salles ou une war a changé.
require __DIR__ . '/../src/bootstrap.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
$me = current_member();
session_write_close(); // libère le verrou de session : les requêtes de polling ne se bloquent pas entre elles
if (!$me) {
    http_response_code(401);
    echo json_encode(['error' => 'auth']);
    exit;
}
auto_archive();
if (isset($_GET['w'])) {
    echo json_encode(['v' => war_version((int) $_GET['w'])]);
    discord_sync_soon((int) $_GET['w']); // ne fait rien s'il n'y a rien de nouveau
} elseif (isset($_GET['r'])) {
    $room = room_by_slug((string) $_GET['r']);
    echo json_encode(['v' => $room ? (string) $room['version'] : '0']);
} else {
    echo json_encode(['v' => rooms_signature()]);
}
