<?php

declare(strict_types=1);

use Aws\S3\S3Client;
use Google\Client;
use Google\Service\Drive;
use Richie314\SimpleVirtualFS\VirtualFilesystem;

require_once dirname(__DIR__) . '/vendor/autoload.php';

/** Create the SDK client for the Compose-managed S3 endpoint. */
function demoS3(): S3Client
{
    return new S3Client([
        'version' => 'latest',
        'region' => 'us-east-1',
        'endpoint' => getenv('S3_ENDPOINT'),
        'use_path_style_endpoint' => true,
        'credentials' => ['key' => getenv('S3_ACCESS_KEY'), 'secret' => getenv('S3_SECRET_KEY')],
        'http' => ['connect_timeout' => 5, 'timeout' => 15],
    ]);
}

/** Check whether all three OAuth values are present without contacting Google. */
function demoHasDriveCredentials(): bool
{
    return (bool) (getenv('GOOGLE_CLIENT_ID') && getenv('GOOGLE_CLIENT_SECRET') && getenv('GOOGLE_REFRESH_TOKEN'));
}

/** Configure the Compose-managed S3 mount. */
function demoFilesystem(): VirtualFilesystem
{
    $files = new VirtualFilesystem();
    $files->mountS3('app', demoS3(), (string) getenv('S3_BUCKET'), name: 'Main application storage');

    return $files;
}

/** Authenticate with Google and add the dedicated Drive folder to the demo filesystem. */
function demoMountDrive(VirtualFilesystem $files): void
{
    $client = new Client();
    $client->setClientId((string) getenv('GOOGLE_CLIENT_ID'));
    $client->setClientSecret((string) getenv('GOOGLE_CLIENT_SECRET'));
    $client->setHttpClient(new \GuzzleHttp\Client(['connect_timeout' => 5, 'timeout' => 15]));
    $token = $client->fetchAccessTokenWithRefreshToken((string) getenv('GOOGLE_REFRESH_TOKEN'));
    if (empty($token['access_token'])) {
        throw new RuntimeException('Google Drive authorization failed.');
    }

    $folderId = (string) getenv('GOOGLE_DRIVE_FOLDER_ID');
    $root = $folderId === '' ? (string) getenv('GOOGLE_DRIVE_FOLDER') : '/';
    $options = $folderId === '' ? [] : ['sharedFolderId' => $folderId];
    $files->mountGoogleDrive('drive', new Drive($client), $root, $options, name: 'Google Drive test folder');
}

/**
 * Test a mount by writing, inspecting and reading a unique file, then deleting it.
 *
 * @return array{mimeType: string, lastModified: int, size: int} Metadata from the probe.
 */
function demoProbe(VirtualFilesystem $files, string $scheme): array
{
    $uri = $scheme . ':///virtualfs-probe-' . bin2hex(random_bytes(12)) . '.txt';
    $payload = "Simple VirtualFS round trip\n";
    $files->write($uri, $payload);
    try {
        $info = $files->metadata($uri);
        if ($info['size'] !== strlen($payload) || $files->read($uri) !== $payload) {
            throw new RuntimeException('The data read back differed from the probe.');
        }
        return $info;
    } finally {
        $files->delete($uri);
    }
}
