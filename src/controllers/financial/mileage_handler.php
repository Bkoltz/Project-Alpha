<?php
// src/controllers/financial/mileage_handler.php
// POST controller for mileage log CRUD (create, update, delete)

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

error_reporting(E_ERROR | E_PARSE);
ini_set('display_errors', '0');

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../utils/csrf.php';
require_once __DIR__ . '/../../utils/audit.php';

$action = $_POST['action'] ?? null;
$response = ['success' => false, 'message' => ''];

// CSRF + auth checks
if (!csrf_validate()) {
    $response['message'] = 'Invalid request (CSRF validation failed)';
    header('Content-Type: application/json');
    echo json_encode($response);
    exit;
}

if (empty($_SESSION['user']['id'])) {
    $response['message'] = 'Authentication required';
    header('Content-Type: application/json');
    echo json_encode($response);
    exit;
}

try {
    $orgId = 1; // organization context (default for now)
    $userId = (int)$_SESSION['user']['id'];

    // Make sure mileage_logs table exists
    $pdo->exec("CREATE TABLE IF NOT EXISTS mileage_logs (
        id INT AUTO_INCREMENT PRIMARY KEY,
        organization_id INT NOT NULL DEFAULT 1,
        user_id INT NOT NULL,
        client_id INT DEFAULT NULL,
        project_id INT DEFAULT NULL,
        trip_date DATE NOT NULL,
        start_location VARCHAR(255) DEFAULT NULL,
        end_location VARCHAR(255) DEFAULT NULL,
        miles DECIMAL(8,2) NOT NULL,
        purpose ENUM('business','medical','moving','charitable','personal') NOT NULL DEFAULT 'business',
        description TEXT,
        round_trip TINYINT(1) NOT NULL DEFAULT 0,
        mileage_rate DECIMAL(5,3) NOT NULL DEFAULT 0.670,
        is_billable TINYINT(1) NOT NULL DEFAULT 0,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_org_trip_date (organization_id, trip_date)
    )");

    switch ($action) {
        case 'create':
            $tripDate = trim($_POST['trip_date'] ?? '');
            $startLocation = trim($_POST['start_location'] ?? '');
            $endLocation = trim($_POST['end_location'] ?? '');
            $milesRaw = $_POST['miles'] ?? '';
            $purpose = trim($_POST['purpose'] ?? '');
            $description = trim($_POST['description'] ?? '');
            $roundTrip = !empty($_POST['round_trip']) ? 1 : 0;
            $mileageRateRaw = $_POST['mileage_rate'] ?? '';
            $isBillable = !empty($_POST['is_billable']) ? 1 : 0;
            $clientId = isset($_POST['client_id']) && $_POST['client_id'] !== '' ? (int)$_POST['client_id'] : null;
            $projectId = isset($_POST['project_id']) && $_POST['project_id'] !== '' ? (int)$_POST['project_id'] : null;

            if ($tripDate === '' || $milesRaw === '') {
                throw new Exception('Trip date and miles are required');
            }
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $tripDate)) {
                throw new Exception('Trip date must be in YYYY-MM-DD format');
            }

            $miles = (float)$milesRaw;
            if ($miles <= 0) {
                throw new Exception('Miles must be greater than zero');
            }

            if ($purpose === '') {
                $purpose = 'business';
            }
            if (!in_array($purpose, ['business', 'medical', 'moving', 'charitable', 'personal'], true)) {
                throw new Exception('Invalid purpose');
            }

            $mileageRate = $mileageRateRaw !== '' ? (float)$mileageRateRaw : 0.670;
            if ($mileageRate < 0) {
                throw new Exception('Mileage rate cannot be negative');
            }

            $stmt = $pdo->prepare('
                INSERT INTO mileage_logs
                    (organization_id, user_id, client_id, project_id, trip_date, start_location, end_location, miles, purpose, description, round_trip, mileage_rate, is_billable)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ');
            $stmt->execute([
                $orgId,
                $userId,
                $clientId,
                $projectId,
                $tripDate,
                $startLocation === '' ? null : $startLocation,
                $endLocation === '' ? null : $endLocation,
                $miles,
                $purpose,
                $description === '' ? null : $description,
                $roundTrip,
                $mileageRate,
                $isBillable,
            ]);

            $newId = (int)$pdo->lastInsertId();

            audit_log($pdo, 'mileage.create', 'mileage_log', $newId, [
                'organization_id' => $orgId,
                'trip_date' => $tripDate,
                'miles' => $miles,
                'round_trip' => $roundTrip,
                'deductible_miles' => $roundTrip ? $miles * 2 : $miles,
            ]);

            $response['success'] = true;
            $response['message'] = 'Mileage entry logged successfully';
            $response['redirect'] = '/?page=financial/mileage-list&created=1';
            $response['id'] = $newId;
            break;

        case 'update':
            $id = (int)($_POST['id'] ?? 0);
            $tripDate = trim($_POST['trip_date'] ?? '');
            $startLocation = trim($_POST['start_location'] ?? '');
            $endLocation = trim($_POST['end_location'] ?? '');
            $milesRaw = $_POST['miles'] ?? '';
            $purpose = trim($_POST['purpose'] ?? '');
            $description = trim($_POST['description'] ?? '');
            $roundTrip = !empty($_POST['round_trip']) ? 1 : 0;
            $mileageRateRaw = $_POST['mileage_rate'] ?? '';
            $isBillable = !empty($_POST['is_billable']) ? 1 : 0;
            $clientId = isset($_POST['client_id']) && $_POST['client_id'] !== '' ? (int)$_POST['client_id'] : null;
            $projectId = isset($_POST['project_id']) && $_POST['project_id'] !== '' ? (int)$_POST['project_id'] : null;

            if ($id <= 0) {
                throw new Exception('Invalid mileage entry ID');
            }
            if ($tripDate === '' || $milesRaw === '') {
                throw new Exception('Trip date and miles are required');
            }
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $tripDate)) {
                throw new Exception('Trip date must be in YYYY-MM-DD format');
            }

            $miles = (float)$milesRaw;
            if ($miles <= 0) {
                throw new Exception('Miles must be greater than zero');
            }

            if ($purpose === '') {
                $purpose = 'business';
            }
            if (!in_array($purpose, ['business', 'medical', 'moving', 'charitable', 'personal'], true)) {
                throw new Exception('Invalid purpose');
            }

            $mileageRate = $mileageRateRaw !== '' ? (float)$mileageRateRaw : 0.670;
            if ($mileageRate < 0) {
                throw new Exception('Mileage rate cannot be negative');
            }

            $stmt = $pdo->prepare('SELECT id FROM mileage_logs WHERE id = ? AND organization_id = ?');
            $stmt->execute([$id, $orgId]);
            if (!$stmt->fetch()) {
                throw new Exception('Mileage entry not found');
            }

            $stmt = $pdo->prepare('
                UPDATE mileage_logs
                SET trip_date = ?,
                    start_location = ?,
                    end_location = ?,
                    miles = ?,
                    purpose = ?,
                    description = ?,
                    round_trip = ?,
                    mileage_rate = ?,
                    is_billable = ?,
                    client_id = ?,
                    project_id = ?
                WHERE id = ? AND organization_id = ?
            ');
            $stmt->execute([
                $tripDate,
                $startLocation === '' ? null : $startLocation,
                $endLocation === '' ? null : $endLocation,
                $miles,
                $purpose,
                $description === '' ? null : $description,
                $roundTrip,
                $mileageRate,
                $isBillable,
                $clientId,
                $projectId,
                $id,
                $orgId,
            ]);

            audit_log($pdo, 'mileage.update', 'mileage_log', $id, [
                'organization_id' => $orgId,
                'trip_date' => $tripDate,
                'miles' => $miles,
                'round_trip' => $roundTrip,
                'deductible_miles' => $roundTrip ? $miles * 2 : $miles,
            ]);

            $response['success'] = true;
            $response['message'] = 'Mileage entry updated successfully';
            $response['redirect'] = '/?page=financial/mileage-list&updated=1';
            $response['id'] = $id;
            break;

        case 'delete':
            $id = (int)($_POST['id'] ?? 0);

            if ($id <= 0) {
                throw new Exception('Invalid mileage entry ID');
            }

            $stmt = $pdo->prepare('SELECT id FROM mileage_logs WHERE id = ? AND organization_id = ?');
            $stmt->execute([$id, $orgId]);
            if (!$stmt->fetch()) {
                throw new Exception('Mileage entry not found');
            }

            $stmt = $pdo->prepare('DELETE FROM mileage_logs WHERE id = ? AND organization_id = ?');
            $stmt->execute([$id, $orgId]);

            audit_log($pdo, 'mileage.delete', 'mileage_log', $id, [
                'organization_id' => $orgId,
            ]);

            $response['success'] = true;
            $response['message'] = 'Mileage entry deleted successfully';
            $response['redirect'] = '/?page=financial/mileage-list&deleted=1';
            break;

        default:
            throw new Exception('Invalid action');
    }
} catch (Throwable $e) {
    $response['message'] = $e->getMessage();
    error_log('[mileage_handler] Error: ' . $e->getMessage());
}

header('Content-Type: application/json');
echo json_encode($response);
