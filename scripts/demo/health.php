<?php

$result = @file_get_contents('http://127.0.0.1:8000/health');
$health = json_decode($result ?: '{}', true);
exit(($health['status'] ?? '') === 'ok' && ($health['database'] ?? '') === 'ok' ? 0 : 1);
