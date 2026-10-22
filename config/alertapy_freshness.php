<?php

return [
    'default' => ['live' => 2, 'recent' => 12, 'aging' => 48, 'stale' => 168],
    'traffic_accident' => ['live' => 1, 'recent' => 6, 'aging' => 12, 'stale' => 48],
    'electricity' => ['live' => 2, 'recent' => 8, 'aging' => 24, 'stale' => 72],
    'water' => ['live' => 4, 'recent' => 12, 'aging' => 36, 'stale' => 96],
    'weather_flood' => ['live' => 1, 'recent' => 6, 'aging' => 18, 'stale' => 48],
    'fire' => ['live' => 2, 'recent' => 8, 'aging' => 24, 'stale' => 72],
    'roads' => ['live' => 6, 'recent' => 24, 'aging' => 72, 'stale' => 168],
    'territorial_news' => ['live' => 12, 'recent' => 48, 'aging' => 120, 'stale' => 336],
];
