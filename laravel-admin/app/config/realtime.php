<?php

return [
    'dialplan_writes_enabled' => (bool) env('DIALPLAN_WRITES_ENABLED', false),
    'playback_prompts' => array_values(array_filter(explode(',', env('DIALPLAN_PLAYBACK_PROMPTS', 'hello-world')))),
    'writes_enabled' => (bool) env('REALTIME_WRITES_ENABLED', false),
    'transports' => array_values(array_filter(explode(',', env('REALTIME_TRANSPORTS', 'transport-udp')))),
    'enabled' => (bool) env('REALTIME_ENABLED', false),
];
