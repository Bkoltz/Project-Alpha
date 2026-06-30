<?php
// src/link_resolvers/link.php
//
// This file originally defined a Doctrine ORM entity for links.
// The application does not use Doctrine ORM - it uses plain PDO with MySQL.
//
// The link table is defined in:
// - database/baseline.sql
// - database/migrations/007_public_links_module.sql
//
// Links are managed through:
// - LinkResolverService (src/services/LinkResolverService.php)
// - Direct database queries via PDO
// - links_section.php component (src/views/components/links_section.php)
//
// This file is kept for reference but is not actively used.
