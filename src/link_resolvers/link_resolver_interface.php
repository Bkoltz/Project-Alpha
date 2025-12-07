<?php
// src/link_resolvers/link_resolver_interface.php
// 
// This file originally defined an interface for link resolvers.
// The application now uses direct database queries and service classes
// instead of a complex resolver pattern.
//
// Link resolution is handled by:
// - LinkResolverService (src/services/LinkResolverService.php) for auto-generation
// - Direct database queries in the links_section component
// - Individual resolver classes in auto_resolver/ folder for Dropbox, GDrive, and S3
//
// This file is kept for reference but is not actively used.
