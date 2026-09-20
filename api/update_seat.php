<?php
// POST /api/update_seat.php
// Body (JSON): { seat_id, guest_name, status, guest_id? }
//   status: "present" | "absent" | "vacant"  (vacant removes the attendance row)
declare(strict_types=1);
require_once __DIR__ . '/db.php';

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { jsonResponse([]); }
if ($_SERVER['REQUEST_METHOD'] !== 'POST')    { errorResponse('Method not allowed', 405); }

$raw = file_get_contents('php://input');
if (!$raw) { errorResponse('Empty request body'); }

try {
    $body = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
} catch (JsonException) {
    errorResponse('Invalid JSON');
}

$seatId      = isset($body['seat_id'])   ? filter_var($body['seat_id'],  FILTER_VALIDATE_INT) : false;
$status      = $body['status']   ?? '';
$guestName   = trim($body['guest_name'] ?? '');
$guestId     = isset($body['guest_id']) && $body['guest_id'] !== null
               ? filter_var($body['guest_id'], FILTER_VALIDATE_INT) : null;
if ($guestId === false) $guestId = null;

// Optional explicit timestamps from the client (ISO 8601); fall back to NOW() if absent
$tsIn  = !empty($body['timestamp_in'])  ? date('Y-m-d H:i:s', strtotime($body['timestamp_in']))  : null;
$tsOut = !empty($body['timestamp_out']) ? date('Y-m-d H:i:s', strtotime($body['timestamp_out'])) : null;

if (!$seatId || $seatId < 1) { errorResponse('Valid seat_id is required'); }
if (!in_array($status, ['present', 'absent', 'vacant'], true)) {
    errorResponse('status must be "present", "absent", or "vacant"');
}

try {
    $db = getDB();

    // Verify the seat exists
    $check = $db->prepare('SELECT id FROM seats WHERE id = :seat_id');
    $check->execute([':seat_id' => $seatId]);
    if (!$check->fetch()) { errorResponse('Seat not found', 404); }

    if ($status === 'vacant') {
        $del = $db->prepare('DELETE FROM attendance WHERE seat_id = :seat_id');
        $del->execute([':seat_id' => $seatId]);

        jsonResponse([
            'success'    => true,
            'action'     => 'removed',
            'seat_id'    => $seatId,
            'status'     => 'vacant',
            'guest_name' => null,
        ]);
    }

    // If guest_id supplied, verify it exists and use stored name as fallback
    if ($guestId !== null) {
        $guestCheck = $db->prepare(
            'SELECT CONCAT(first_name," ",last_name) AS full_name FROM guests WHERE id = :id'
        );
        $guestCheck->execute([':id' => $guestId]);
        $guestRow = $guestCheck->fetch();
        if (!$guestRow) { errorResponse("Guest ID {$guestId} not found", 404); }
        if ($guestName === '') $guestName = $guestRow['full_name'];
        $guestId = (int)$guestId;
    }

    // Use client-supplied timestamps when provided, otherwise fall back to NOW()
    $tsInExpr  = $tsIn  ? ':ts_in'  : 'NOW()';
    $tsOutExpr = $tsOut ? ':ts_out' : 'NULL';

    $upsert = $db->prepare(
        "INSERT INTO attendance
             (seat_id, guest_id, guest_name, status, timestamp_in, timestamp_out, updated_at)
         VALUES
             (:seat_id, :guest_id, :guest_name, :status, {$tsInExpr}, {$tsOutExpr}, NOW())
         ON DUPLICATE KEY UPDATE
             guest_id      = VALUES(guest_id),
             guest_name    = VALUES(guest_name),
             status        = VALUES(status),
             timestamp_in  = CASE WHEN VALUES(status) = 'present'
                               THEN COALESCE(VALUES(timestamp_in), NOW())
                               ELSE timestamp_in END,
             timestamp_out = CASE WHEN VALUES(status) = 'absent'
                               THEN COALESCE(VALUES(timestamp_out), NOW())
                               ELSE timestamp_out END,
             updated_at    = NOW()"
    );
    $params = [
        ':seat_id'    => $seatId,
        ':guest_id'   => $guestId,
        ':guest_name' => $guestName ?: null,
        ':status'     => $status,
    ];
    if ($tsIn)  $params[':ts_in']  = $tsIn;
    if ($tsOut) $params[':ts_out'] = $tsOut;
    $upsert->execute($params);

    $fetch = $db->prepare(
        'SELECT id, status, timestamp_in, timestamp_out
         FROM attendance WHERE seat_id = :seat_id LIMIT 1'
    );
    $fetch->execute([':seat_id' => $seatId]);
    $row = $fetch->fetch();

    jsonResponse([
        'success'       => true,
        'action'        => 'upserted',
        'attendance_id' => (int)$row['id'],
        'seat_id'       => $seatId,
        'guest_id'      => $guestId,
        'guest_name'    => $guestName ?: null,
        'status'        => $row['status'],
        'timestamp_in'  => $row['timestamp_in'],
        'timestamp_out' => $row['timestamp_out'],
    ]);

} catch (PDOException $e) {
    error_log('[update_seat] PDO error: ' . $e->getMessage());
    errorResponse('Database error', 500);
} catch (Throwable $e) {
    error_log('[update_seat] Error: ' . $e->getMessage());
    errorResponse('Server error', 500);
}
