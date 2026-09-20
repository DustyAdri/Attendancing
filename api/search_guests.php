<?php
// GET /api/search_guests.php?q=searchterm&limit=10
// Returns guests matching the query, annotated with their current seat (if any)
declare(strict_types=1);
require_once __DIR__ . '/db.php';

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { jsonResponse([]); }
if ($_SERVER['REQUEST_METHOD'] !== 'GET')     { errorResponse('Method not allowed', 405); }

$q     = trim(filter_input(INPUT_GET, 'q', FILTER_DEFAULT) ?? '');
$limit = min(20, max(1, (int)(filter_input(INPUT_GET, 'limit', FILTER_VALIDATE_INT) ?: 10)));

try {
    $db = getDB();

    $pattern = '%' . str_replace(['%','_'], ['\\%','\\_'], $q) . '%';

    $stmt = $db->prepare(
        "SELECT
             g.id,
             g.first_name,
             g.last_name,
             CONCAT(g.first_name, ' ', g.last_name)      AS name,
             g.id_number,
             g.program,
             g.year,
             a.status                                     AS current_status,
             COALESCE(t.table_number, g.table_number)    AS current_table,
             COALESCE(s.seat_number,  g.seat_number)     AS current_seat
         FROM guests g
         LEFT JOIN attendance a ON a.guest_id  = g.id
         LEFT JOIN seats      s ON s.id        = a.seat_id
         LEFT JOIN `tables`   t ON t.id        = s.table_id
         WHERE (:q = '' OR CONCAT(g.first_name, ' ', g.last_name) LIKE :pattern
                        OR g.id_number LIKE :pattern2
                        OR g.program   LIKE :pattern3)
         ORDER BY g.last_name, g.first_name
         LIMIT :lim"
    );
    $stmt->bindValue(':q',       $q,       PDO::PARAM_STR);
    $stmt->bindValue(':pattern', $pattern, PDO::PARAM_STR);
    $stmt->bindValue(':pattern2',$pattern, PDO::PARAM_STR);
    $stmt->bindValue(':pattern3',$pattern, PDO::PARAM_STR);
    $stmt->bindValue(':lim',     $limit,   PDO::PARAM_INT);
    $stmt->execute();

    $guests = array_map(static function (array $row): array {
        return [
            'id'             => (int)$row['id'],
            'name'           => $row['name'],
            'id_number'      => $row['id_number'],
            'program'        => $row['program'],
            'year'           => (int)$row['year'],
            'current_status' => $row['current_status'],
            'current_table'  => $row['current_table'],
            'current_seat'   => $row['current_seat'] !== null ? (int)$row['current_seat'] : null,
        ];
    }, $stmt->fetchAll());

    jsonResponse(['success' => true, 'query' => $q, 'guests' => $guests]);

} catch (PDOException $e) {
    error_log('[search_guests] PDO error: ' . $e->getMessage());
    errorResponse('Database error', 500);
} catch (Throwable $e) {
    error_log('[search_guests] Error: ' . $e->getMessage());
    errorResponse('Server error', 500);
}
