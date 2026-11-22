<?php

declare(strict_types=1);

use Richie314\SimpleVirtualFS\Media;
use Richie314\SimpleVirtualFS\UploadedFiles;
use Richie314\SimpleVirtualFS\VirtualFilesystem;
use setasign\Fpdi\Fpdi;
use setasign\Fpdi\PdfParser\StreamReader;

require dirname(__DIR__) . '/vendor/autoload.php';

/** Fail the smoke test when the expected condition is false. */
function verify(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

/** Verify that the operation throws the expected exception type. */
function verifyException(string $class, callable $operation): void
{
    try {
        $operation();
    } catch (Throwable $error) {
        if ($error instanceof $class) {
            return;
        }
        throw $error;
    }

    throw new RuntimeException("Expected $class.");
}

$root = sys_get_temp_dir() . '/simple-vfs-' . bin2hex(random_bytes(8));
mkdir($root, 0700);
try {
    mkdir("$root/local", 0700);
    mkdir("$root/app", 0700);
    $files = new VirtualFilesystem();
    verifyException(InvalidArgumentException::class, fn () => $files->mountHttp('public', new \GuzzleHttp\Client()));
    $sourceKey = random_bytes(32);
    $files->mountLocal('LOCAL', "$root/local", name: 'Local reports');
    $files->mountLocal('app', "$root/app", $sourceKey);
    verify($files->listMounts() === [
        ['scheme' => 'local', 'type' => 'local', 'name' => 'Local reports'],
        ['scheme' => 'app', 'type' => 'local', 'name' => 'app:///'],
    ], 'Mount inventory must preserve order, labels and normalized schemes.');
    $files->setFileKey('LOCAL:///private.bin', random_bytes(32));

    $data = "example\0bytes";
    $files->write('local:///private.bin', $data);
    $raw = file_get_contents("$root/local/private.bin");
    verify(!str_contains($raw, $data), 'The file override must encrypt the stored bytes.');
    $files->write('local:///private.bin', $data);
    verify($raw !== file_get_contents("$root/local/private.bin"), 'Writes must use fresh nonces.');
    $files->mountLocal('alias', "$root/local");
    verifyException(RuntimeException::class, fn () => $files->read('alias:///private.bin'));
    verifyException(InvalidArgumentException::class, fn () => $files->move('local:///private.bin', 'alias:///private.bin'));
    verify($files->read('local:///private.bin') === $data, 'An aliased move must leave the source intact.');

    $files->createDirectory('app:///archive');
    $files->move('local:///private.bin', 'app:///archive/report.bin');
    verify(!$files->exists('local:///private.bin'), 'Move must remove the source.');
    verify($files->read('APP:///archive/report.bin') === $data, 'Move must use the destination key.');
    verify($files->size('app:///archive/report.bin') === strlen($data), 'Size must report plaintext bytes.');
    $info = $files->metadata('app:///archive/report.bin');
    verify($info['size'] === filesize("$root/app/archive/report.bin"), 'Metadata must report stored bytes.');
    verify($info['size'] > strlen($data), 'Stored size must include encryption overhead.');
    verify($info['lastModified'] === filemtime("$root/app/archive/report.bin"), 'Metadata must use the storage timestamp.');
    verify($info['mimeType'] === 'application/octet-stream', 'Encrypted files must use a generic MIME type.');
    verify(count($files->listContents('app:///archive')->toArray()) === 1, 'Listing must find the destination.');
    $rootEntries = $files->listContents('app:///')->toArray();
    verify(count($rootEntries) === 1 && $rootEntries[0]->isDir() && $rootEntries[0]->path() === 'archive', 'Root listing must show the top-level folder.');
    $tree = $files->listContents('APP:///', recursive: true)->toArray();
    verify(count($tree) === 2 && $tree[1]->isFile() && $tree[1]->path() === 'archive/report.bin', 'Recursive listing must include nested files with mount-relative paths.');

    $files->setFileKey('app:///archive/report.bin', random_bytes(32));
    verifyException(RuntimeException::class, fn () => $files->read('app:///archive/report.bin'));
    $files->setFileKey('app:///archive/report.bin', null);
    $ciphertext = file_get_contents("$root/app/archive/report.bin");
    file_put_contents("$root/app/archive/report.bin", substr($ciphertext, 0, -1));
    verifyException(RuntimeException::class, fn () => $files->read('app:///archive/report.bin'));
    $files->write('app:///archive/report.bin', $data);

    copy("$root/app/archive/report.bin", "$root/app/archive/swapped.bin");
    verifyException(RuntimeException::class, fn () => $files->read('app:///archive/swapped.bin'));
    verifyException(InvalidArgumentException::class, fn () => $files->read('local:///../app/archive/report.bin'));
    verifyException(InvalidArgumentException::class, fn () => $files->read('local:///%2e%2e/file'));
    $files->write('local:///example.png', 'plain text');
    verify($files->metadata('local:///example.png')['mimeType'] === 'image/png', 'Metadata MIME must fall back to the extension.');
    verify($files->mimeType('local:///example.png') === 'text/plain', 'Content MIME must still inspect the bytes.');

    verify(UploadedFiles::fromField(null) === [], 'An absent upload field must yield no entries.');
    $upload = ['error' => UPLOAD_ERR_OK, 'tmp_name' => "$root/local/example.png", 'name' => 'submitted.png'];
    verify(UploadedFiles::fromField($upload) === [$upload], 'A scalar upload must be preserved.');
    $batch = UploadedFiles::fromField([
        'error' => [2 => UPLOAD_ERR_OK, 5 => UPLOAD_ERR_NO_FILE],
        'tmp_name' => [5 => '', 2 => $upload['tmp_name']],
        'name' => [2 => $upload['name'], 5 => ''],
    ]);
    verify($batch[0] === $upload && $batch[1]['error'] === UPLOAD_ERR_NO_FILE, 'Multiple uploads must align by index.');
    verifyException(InvalidArgumentException::class, fn () => UploadedFiles::fromField([
        'error' => [0 => UPLOAD_ERR_OK],
        'tmp_name' => [1 => '/tmp/mismatched'],
    ]));
    verifyException(RuntimeException::class, fn () => $files->saveUpload($batch[1], 'app:///upload.png'));
    verifyException(RuntimeException::class, fn () => $files->saveUpload($upload, 'app:///upload.png'));
    verify(!$files->exists('app:///upload.png'), 'Rejected uploads must not create a file.');
    if (DIRECTORY_SEPARATOR === '/') {
        symlink("$root/app", "$root/local/escape");
        verifyException(RuntimeException::class, fn () => $files->read('local:///escape/archive/report.bin'));
    }

    $image = imagecreatetruecolor(40, 20);
    ob_start();
    imagepng($image);
    $files->write('local:///sample.png', ob_get_clean());
    $media = new Media($files);
    $media->downscaleImage('local:///sample.png', 'app:///sample.png', 10, 10);
    $dimensions = getimagesizefromstring($files->read('app:///sample.png'));
    verify([$dimensions[0], $dimensions[1]] === [10, 5], 'Downscale must preserve aspect ratio.');
    verify($files->mimeType('app:///sample.png') === 'image/png', 'MIME detection must inspect plaintext.');

    $media->imagesToPdf(['local:///sample.png', 'app:///sample.png'], 'app:///images.pdf');
    $pdf = new Fpdi();
    verify($pdf->setSourceFile(StreamReader::createByString($files->read('app:///images.pdf'))) === 2, 'Each image needs its own page.');
    $media->mergePdfs(['app:///images.pdf', 'app:///images.pdf'], 'local:///merged.pdf');
    verify($pdf->setSourceFile(StreamReader::createByString($files->read('local:///merged.pdf'))) === 4, 'Merge must include every source page.');

    echo "Storage, encryption and media checks passed.\n";
} finally {
    $entries = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );
    foreach ($entries as $entry) {
        if ($entry->isDir() && !$entry->isLink()) {
            rmdir($entry->getPathname());
        } else {
            unlink($entry->getPathname());
        }
    }
    rmdir($root);
}
