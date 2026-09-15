<?php

return [
    // Port the phone gateway (tools/gateway.mjs) listens on with HTTPS for same-Wi-Fi phones.
    'phone_port' => (int) env('ATTENDANCE_PHONE_PORT', 8443),

    // Laptop mode: admin pages open only on the laptop itself (never through the phone gateway or
    // tunnel), in addition to requiring a login. Set to false on a production server.
    'admin_localhost_only' => (bool) env('ATTENDANCE_ADMIN_LOCALHOST_ONLY', true),

    // Enables /install?token=... (first-time setup on hosting without a terminal). Leave empty
    // afterwards: the page then does not exist.
    'install_token' => (string) env('INSTALL_TOKEN', ''),
];
