<?php
// src/config/app.php
$settingsFile = __DIR__ . '/../../public/assests/settings.json';

$appConfig = [
    'brand_name' => 'Project Alpha',
'logo_path'  => null,
    // User info for documents
    'from_name' => null,
    'from_address_line1' => null,
    'from_address_line2' => null,
    'from_city' => null,
    'from_state' => null,
    'from_postal' => null,
    'from_country' => null,
    'from_email' => null,
    'from_phone' => null,
    // Terms for contracts
'terms' => null,
    'net_terms_days' => 30,
];

if (is_readable($settingsFile)) {
    $json = @file_get_contents($settingsFile);
    if ($json !== false) {
        $data = json_decode($json, true);
        if (is_array($data)) {
            $appConfig = array_merge($appConfig, array_intersect_key($data, $appConfig));
        }
    }
}
