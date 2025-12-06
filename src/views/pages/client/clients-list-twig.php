<?php
// src/views/pages/client/clients-list-twig.php
// Example of using Twig templates for list view
require_once __DIR__ . '/../../../config/db.php';
require_once __DIR__ . '/../../../utils/twig.php';

// Fetch clients
$stmt = $pdo->query("
    SELECT 
        id, 
        name, 
        email, 
        phone, 
        organization, 
        state,
        CONCAT('/?page=client/clients-edit&id=', id) as edit_url,
        CONCAT('/?page=client/clients-view&id=', id) as view_url
    FROM clients 
    WHERE archived = 0 
    ORDER BY name ASC
");
$clients = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Define columns for the list view
$columns = [
    ['key' => 'name', 'label' => 'Name', 'format' => 'text'],
    ['key' => 'organization', 'label' => 'Organization', 'format' => 'text'],
    ['key' => 'email', 'label' => 'Email', 'format' => 'text'],
    ['key' => 'phone', 'label' => 'Phone', 'format' => 'text'],
    ['key' => 'state', 'label' => 'State', 'format' => 'text'],
];

// Define actions
$actions = [
    ['label' => 'View', 'url_key' => 'view_url', 'class' => 'btn'],
    ['label' => 'Edit', 'url_key' => 'edit_url', 'class' => 'btn-primary'],
];

// Render the template
display_template('components/list-view.html.twig', [
    'title' => 'Clients',
    'items' => $clients,
    'columns' => $columns,
    'actions' => $actions,
    'create_url' => '/?page=client/clients-create',
    'create_label' => 'Add Client',
    'search_placeholder' => 'Search clients by name, email, or organization...',
]);
