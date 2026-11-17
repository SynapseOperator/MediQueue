<?php

$root = dirname(__DIR__);
$excluded = ['vendor', 'storage', 'node_modules', '.git', '.local'];
$iterator = new RecursiveIteratorIterator(
    new RecursiveCallbackFilterIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        fn (SplFileInfo $entry): bool => ! $entry->isDir() || ! in_array($entry->getFilename(), $excluded, true),
    ),
);

$files = [$root.'/artisan'];
foreach ($iterator as $file) {
    if ($file->isFile() && $file->getExtension() === 'php') {
        $files[] = $file->getPathname();
    }
}
sort($files);

$failures = 0;
foreach ($files as $file) {
    $process = proc_open([PHP_BINARY, '-l', $file], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if ($process === false) {
        fwrite(STDERR, "Could not start PHP syntax check.\n");
        exit(1);
    }

    $output = stream_get_contents($pipes[1]).stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    if (proc_close($process) !== 0) {
        $failures++;
        fwrite(STDERR, $output);
    }
}

printf("PHP syntax: %d files checked, %d failures.\n", count($files), $failures);
exit($failures === 0 ? 0 : 1);
