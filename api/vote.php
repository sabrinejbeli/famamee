<?php
// =============================================
// Fama Mee — API de vote (POST /api/vote.php)
// =============================================
// Body JSON attendu :
//   { "zone_name": "Tunis", "vote_type": "famma"|"mafamech",
//     "latitude": 36.8, "longitude": 10.18 }
//
// Réponse succès :
//   { "success": true, "zone_counts": {...}, "global_counts": {...}, "message": "..." }
// Réponse déjá voté :
//   { "success": false, "already_voted": true, "previous_vote": "famma", "message": "..." }
// =============================================

require_once __DIR__ . '/config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['error' => 'Méthode non autorisée.'], 405);
}

// --- Lecture + validation des données ---
$raw  = file_get_contents('php://input');
$data = json_decode($raw, true);

$zone_name = isset($data['zone_name']) ? mb_substr(trim($data['zone_name']), 0, 255) : '';
$vote_type = $data['vote_type'] ?? '';
$latitude  = isset($data['latitude'])  ? (float)$data['latitude']  : null;
$longitude = isset($data['longitude']) ? (float)$data['longitude'] : null;

if ($zone_name === '' || !in_array($vote_type, ['famma', 'mafamech'], true)) {
    jsonResponse(['error' => 'Paramètres invalides.'], 400);
}

$ip         = getClientIP();
$user_agent = mb_substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 512);

try {
    $db = getDB();

    // -- 1. Vérification : l'IP a-t-elle déjà voté pour cette zone ? --
    $check = $db->prepare(
        'SELECT id, vote_type FROM votes WHERE zone_name = ? AND ip_address = ? LIMIT 1'
    );
    $check->execute([$zone_name, $ip]);
    $existing = $check->fetch();

    if ($existing) {
        jsonResponse([
            'success'       => false,
            'already_voted' => true,
            'previous_vote' => $existing['vote_type'],
            'message'       => 'Vous avez déjà signalé cette zone. Un seul vote par zone est autorisé.',
        ]);
    }

    // -- 2. Insertion du vote --
    $insert = $db->prepare(
        'INSERT INTO votes (zone_name, vote_type, ip_address, latitude, longitude, user_agent)
         VALUES (?, ?, ?, ?, ?, ?)'
    );
    $insert->execute([$zone_name, $vote_type, $ip, $latitude, $longitude, $user_agent]);

    // -- 3. Compteurs pour la zone --
    $zoneStmt = $db->prepare(
        'SELECT vote_type, COUNT(*) AS cnt FROM votes WHERE zone_name = ? GROUP BY vote_type'
    );
    $zoneStmt->execute([$zone_name]);
    $zone_counts = ['famma' => 0, 'mafamech' => 0];
    foreach ($zoneStmt->fetchAll() as $row) {
        $zone_counts[$row['vote_type']] = (int)$row['cnt'];
    }

    // -- 4. Compteurs globaux --
    $globalStmt = $db->query(
        'SELECT vote_type, COUNT(*) AS cnt FROM votes GROUP BY vote_type'
    );
    $global_counts = ['famma' => 0, 'mafamech' => 0];
    foreach ($globalStmt->fetchAll() as $row) {
        $global_counts[$row['vote_type']] = (int)$row['cnt'];
    }

    $msg = $vote_type === 'famma'
        ? "Merci ! \"{$zone_name}\" est marquée avec de l'eau. 💧"
        : "Merci ! \"{$zone_name}\" est marquée sans eau. 🚱";

    jsonResponse([
        'success'       => true,
        'vote_type'     => $vote_type,
        'zone_counts'   => $zone_counts,
        'global_counts' => $global_counts,
        'message'       => $msg,
    ]);

} catch (PDOException $e) {
    // Violation de clé unique = déjà voté (double clic rapide)
    if ($e->getCode() === '23000') {
        jsonResponse([
            'success'       => false,
            'already_voted' => true,
            'message'       => 'Vous avez déjá voté pour cette zone.',
        ]);
    }
    error_log('[famamee] vote.php: ' . $e->getMessage());
    jsonResponse(['error' => 'Erreur serveur. Réessayez plus tard.'], 500);
}
