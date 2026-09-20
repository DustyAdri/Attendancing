<?php
// GET /api/get_seating.php
// Returns the full seating plan.
declare(strict_types=1);
require_once __DIR__ . '/db.php';

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { jsonResponse([]); }
if ($_SERVER['REQUEST_METHOD'] !== 'GET')      { errorResponse('Method not allowed', 405); }

try {
    $db = getDB();

    $stmt = $db->query(
        "SELECT
            table_id,
            table_number,
            zone,
            location_x,
            location_y,
            seat_id,
            seat_number,
            attendance_id,
            guest_name,
            guest_id,
            id_number,
            program,
            year,
            COALESCE(status, 'vacant') AS status,
            timestamp_in,
            timestamp_out
         FROM v_seating_plan
         ORDER BY table_number, seat_number"
    );
    $rows = $stmt->fetchAll();

    // Group by table
    $tables = [];
    foreach ($rows as $row) {
        $tid = $row['table_id'];
        if (!isset($tables[$tid])) {
            $tables[$tid] = [
                'id'           => (int)$row['table_id'],
                'table_number' => $row['table_number'],
                'zone'         => $row['zone'],
                'location_x'   => (float)$row['location_x'],
                'location_y'   => (float)$row['location_y'],
                'seats'        => [],
            ];
        }
        $tables[$tid]['seats'][] = [
            'seat_id'       => (int)$row['seat_id'],
            'seat_number'   => (int)$row['seat_number'],
            'attendance_id' => $row['attendance_id'] ? (int)$row['attendance_id'] : null,
            'guest_name'    => $row['guest_name'],
            'guest_id'      => $row['guest_id'] ? (int)$row['guest_id'] : null,
            'id_number'     => $row['id_number'],
            'program'       => $row['program'],
            'year'          => $row['year'] ? (int)$row['year'] : null,
            'status'        => $row['status'],
            'timestamp_in'  => $row['timestamp_in'],
            'timestamp_out' => $row['timestamp_out'],
        ];
    }

    jsonResponse([
        'success' => true,
        'tables'  => array_values($tables),
    ]);

} catch (PDOException $e) {
    error_log('[get_seating] PDO error: ' . $e->getMessage());
    errorResponse('Database error', 500);
} catch (Throwable $e) {
    error_log('[get_seating] Error: ' . $e->getMessage());
    errorResponse('Server error', 500);
}
