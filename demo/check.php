<?php

declare(strict_types=1);

require __DIR__ . '/storage.php';

$files = demoFilesystem();
$failed = false;
foreach (['app', 'drive'] as $scheme) {
    if ($scheme === 'drive' && !demoHasDriveCredentials()) {
        fwrite(STDERR, "FAIL drive: Configure the Google Drive OAuth values in demo/.env.\n");
        $failed = true;
        continue;
    }
    try {
        if ($scheme === 'drive') {
            demoMountDrive($files);
        }
        $info = demoProbe($files, $scheme);
        printf("PASS %s:/// — %s (%d bytes)\n", $scheme, $info['mimeType'], $info['size']);
    } catch (Throwable $error) {
        fwrite(STDERR, "FAIL $scheme: " . $error::class . "\n");
        $failed = true;
    }
}
exit($failed ? 1 : 0);
