# API Data Context

Last reviewed: 2026-06-28

This directory contains static JSON data used by interface fallbacks or examples. Executable authenticated API handlers live under `src/controllers/api/` and are routed through `public/index.php`.

Do not place secrets, production exports, or customer information here. Anything copied into the web image may become publicly retrievable depending on routing and server configuration.

When changing a static data shape, locate every reader and update its fallback behavior and tests.
