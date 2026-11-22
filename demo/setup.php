<?php

declare(strict_types=1);

require __DIR__ . '/storage.php';

$client = demoS3();
$bucket = (string) getenv('S3_BUCKET');
if (!$client->doesBucketExistV2($bucket)) {
    $client->createBucket(['Bucket' => $bucket]);
}
