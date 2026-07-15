<?php
require_once __DIR__ . '/../config/db.php';

try {
    $deletedFinalized = $pdo->exec(
        'DELETE p FROM mileage_tracking_points p
         JOIN mileage_tracking_sessions s ON s.id=p.session_id
         WHERE s.status="finalized" AND s.finalized_at < DATE_SUB(NOW(),INTERVAL 90 DAY)'
    );
    $deletedDiscarded = $pdo->exec(
        'DELETE p FROM mileage_tracking_points p
         JOIN mileage_tracking_sessions s ON s.id=p.session_id
         WHERE s.status="discarded"'
    );
    echo sprintf("Purged %d finalized and %d discarded mileage GPS points.\n", (int)$deletedFinalized, (int)$deletedDiscarded);
} catch (Throwable $e) {
    fwrite(STDERR, '[MileageRetention] ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
