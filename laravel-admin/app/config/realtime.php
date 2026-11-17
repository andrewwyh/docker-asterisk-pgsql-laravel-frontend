<?php

return [
    'writes_enabled' => (bool) env('REALTIME_WRITES_ENABLED', false),
    'transports' => array_values(array_filter(explode(',', env('REALTIME_TRANSPORTS', 'transport-udp')))),
    'enabled' => (bool) env('REALTIME_ENABLED', false),
];
