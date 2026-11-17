<?php

$trusted = ($argv[1] ?? '') === '--trusted';
$curl = curl_init('http://127.0.0.1:8000/');
curl_setopt_array($curl, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 15,
    CURLOPT_HTTPHEADER => [
        'X-Forwarded-Proto: https',
        'X-Forwarded-Host: demo.example.test',
        'X-Forwarded-Port: 443',
    ],
]);
$html = curl_exec($curl);
$passed = curl_getinfo($curl, CURLINFO_RESPONSE_CODE) === 200
    && str_contains($html, 'https://demo.example.test/build/assets/') === $trusted;
curl_close($curl);
if (! $passed) {
    fwrite(STDERR, "Demo proxy behavior did not match its explicit configuration.\n");
    exit(1);
}
fwrite(STDOUT, $trusted ? "PASS HTTPS forwarding in opted-in development container.\n" : "PASS default demo ignores forwarded proxy headers.\n");
