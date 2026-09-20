<?php
// POST /api/update_table.php
// Body (JSON): { table_db_id, table_number }
// Renames a table's display number, enforcing uniqueness.
declare(strict_types=1);
require_once __DIR__ . '/db.php';

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { jsonResponse([]); }
if ($_SERVER['REQUEST_METHOD'] !== 'POST')    { errorResponse('Method not allowed', 405); }

$raw = file_get_contents('php://input');
if (!$raw) { errorResponse('Empty request body'); }

try { $body = json_decode($raw, true, 512, JSON_THROW_ON_ERROR); }
catch (JsonException) { errorResponse('Invalid JSON'); }

$tableDbId   = isset($body['table_db_id'])  ? filter_var($body['table_db_id'],  FILTER_VALIDATE_INT) : false;
$tableNumber = trim($body['table_number'] ?? '');

if (!$tableDbId || $tableDbId < 1){ errorResponse('Valid table_db_id is required'); }
if ($tableNumber === '')           { errorResponse('table_number cannot be empty'); }
if (strlen($tableNumber) > 10)    { errorResponse('table_number max length is 10 characters'); }

try {
    $db = getDB();

    $check = $db->prepare('SELECT id, table_number FROM `tables` WHERE id = :id');
    $check->execute([':id' => $tableDbId]);
    $existing = $check->fetch();
    if (!$existing) { errorResponse('Table not found', 404); }

    if ($existing['table_number'] === $tableNumber) {
        jsonResponse(['success' => true, 'action' => 'no_change', 'table_number' => $tableNumber]);
    }

    $dup = $db->prepare(
        'SELECT id FROM `tables` WHERE table_number = :table_number AND id != :id'
    );
    $dup->execute([':table_number' => $tableNumber, ':id' => $tableDbId]);
    if ($dup->fetch()) { errorResponse("Table number \"{$tableNumber}\" already exists", 409); }

    $upd = $db->prepare('UPDATE `tables` SET table_number = :table_number WHERE id = :id');
    $upd->execute([':table_number' => $tableNumber, ':id' => $tableDbId]);

    jsonResponse([
        'success'      => true,
        'action'       => 'renamed',
        'table_db_id'  => $tableDbId,
        'old_number'   => $existing['table_number'],
        'table_number' => $tableNumber,
    ]);

} catch (PDOException $e) {
    error_log('[update_table] PDO error: ' . $e->getMessage());
    errorResponse('Database error', 500);
} catch (Throwable $e) {
    error_log('[update_table] Error: ' . $e->getMessage());
    errorResponse('Server error', 500);
}
