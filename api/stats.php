<?php
// =============================================
// Fama Mee — API statistiques (GET /api/stats.php)
// =============================================
// Paramètres optionnels :
//   ?zone=Tunis          → statistiques d'une zone
//   ?top=20              → N zones les plus actives (défaut 50)
//   ?ip_check=Tunis      → vérifie si l'IP a déjà voté pour cette zone
//
// Réponse :
// {
//   "global": { "famma":10, "mafamech":5, "total":15, "zones":8, "users":12 },
//   "zone_votes": { "Tunis": {"famma":3,"mafamech":1}, ... },
//   "top_zones": [ {"name":"Tunis","famma":3,"mafamech":1,"total":4,"status":"famma"}, ... ],
//   "already_voted": false,          // seulement si ?ip_check= fourni
//   "recent_24h": 6,
//   "last_updated": "2025-06-01T12:00:00+01:00"
// }
// =============================================

require_once __DIR__ . '/config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    jsonResponse(['error' => 'Méthode non autorisée.'], 405);
}

$ip       = getClientIP();
$topN     = max(1, min(200, (int)($_GET['top']     ?? 50)));
$ipCheck  = isset($_GET['ip_check']) ? trim($_GET['ip_check']) : null;
$zoneOnly = isset($_GET['zone'])     ? trim($_GET['zone'])     : null;

try {
    $db = getDB();

    // --- Statistiques globales ---
    $gStmt = $db->query(
        'SELECT
            SUM(vote_type="famma")    AS total_famma,
            SUM(vote_type="mafamech") AS total_mafamech,
            COUNT(*)                   AS total_votes,
            COUNT(DISTINCT zone_name)  AS total_zones,
            COUNT(DISTINCT ip_address) AS total_users,
            MAX(voted_at)              AS last_vote_at
         FROM votes'
    );
    $g = $gStmt->fetch();
    $global = [
        'famma'    => (int)($g['total_famma']    ?? 0),
        'mafamech' => (int)($g['total_mafamech'] ?? 0),
        'total'    => (int)($g['total_votes']    ?? 0),
        'zones'    => (int)($g['total_zones']    ?? 0),
        'users'    => (int)($g['total_users']    ?? 0),
    ];

    // --- Votes par zone ---
    $zStmt = $db->query(
        'SELECT zone_name, vote_type, COUNT(*) AS cnt FROM votes GROUP BY zone_name, vote_type'
    );
    $zoneVotes = [];
    foreach ($zStmt->fetchAll() as $row) {
        $n = $row['zone_name'];
        if (!isset($zoneVotes[$n])) $zoneVotes[$n] = ['famma' => 0, 'mafamech' => 0];
        $zoneVotes[$n][$row['vote_type']] = (int)$row['cnt'];
    }

    // --- Top zones ---
    $topZones = [];
    foreach ($zoneVotes as $name => $vc) {
        $total = $vc['famma'] + $vc['mafamech'];
        $topZones[] = [
            'name'     => $name,
            'famma'    => $vc['famma'],
            'mafamech' => $vc['mafamech'],
            'total'    => $total,
            'status'   => $vc['famma'] >= $vc['mafamech'] ? 'famma' : 'mafamech',
        ];
    }
    usort($topZones, fn($a, $b) => $b['total'] - $a['total']);
    $topZones = array_slice($topZones, 0, $topN);

    // --- Activité récente (24h) ---
    $r24 = $db->query(
        'SELECT COUNT(*) AS cnt FROM votes WHERE voted_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)'
    )->fetchColumn();

    // --- Vérification IP pour une zone ---
    $alreadyVoted = null;
    $previousVote = null;
    if ($ipCheck !== null) {
        $ipStmt = $db->prepare(
            'SELECT vote_type FROM votes WHERE zone_name = ? AND ip_address = ? LIMIT 1'
        );
        $ipStmt->execute([$ipCheck, $ip]);
        $ipRow = $ipStmt->fetch();
        $alreadyVoted = (bool)$ipRow;
        if ($ipRow) $previousVote = $ipRow['vote_type'];
    }

    $response = [
        'global'       => $global,
        'zone_votes'   => $zoneVotes,
        'top_zones'    => $topZones,
        'recent_24h'   => (int)$r24,
        'last_updated' => date('c'),
    ];
    if ($alreadyVoted !== null) {
        $response['already_voted'] = $alreadyVoted;
        $response['previous_vote'] = $previousVote;
    }

    // Cache 30 secondes (CDN / proxy)
    header('Cache-Control: public, max-age=30');
    jsonResponse($response);

} catch (PDOException $e) {
    error_log('[famamee] stats.php: ' . $e->getMessage());
    jsonResponse(['error' => 'Erreur serveur.'], 500);
}
