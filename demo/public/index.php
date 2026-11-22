<?php

declare(strict_types=1);

require dirname(__DIR__) . '/storage.php';

header("Content-Security-Policy: default-src 'none'; style-src 'self'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'");
header('X-Content-Type-Options: nosniff');
session_start();
$_SESSION['csrf'] ??= bin2hex(random_bytes(32));

/** Escape dynamic values for HTML text and quoted attributes. */
function html(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

$files = demoFilesystem();
$driveIssue = 'Credentials required';
if (demoHasDriveCredentials()) {
    try {
        demoMountDrive($files);
    } catch (Throwable) {
        $driveIssue = 'Connection failed';
    }
}
$mounts = array_column($files->listMounts(), null, 'scheme');
$sources = [
    'app' => ['provider' => 'MinIO · S3-compatible', 'name' => 'Main application storage'],
    'drive' => ['provider' => 'Google Drive · OAuth', 'name' => 'Google Drive test folder'],
];
$result = null;
$error = null;
$folderListing = null;
$folderPath = '';
$recursive = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $scheme = $_POST['scheme'] ?? null;
    $csrf = $_POST['csrf'] ?? null;
    $action = $_POST['action'] ?? null;
    if (!is_string($csrf) || !hash_equals($_SESSION['csrf'], $csrf)) {
        http_response_code(400);
        $error = 'The form expired or the request exceeded the upload limit. Reload and try again.';
    } elseif (!is_string($scheme) || !isset($mounts[$scheme]) || !in_array($action, ['probe', 'upload', 'browse'], true)) {
        http_response_code(400);
        $error = 'Choose a configured storage source and a valid operation.';
    } else {
        try {
            if ($action === 'browse') {
                $folderPath = $_POST['folder'] ?? '';
                if (!is_string($folderPath)) {
                    $folderPath = '';
                    throw new InvalidArgumentException('Expected a folder path.');
                }
                $recursive = ($_POST['recursive'] ?? null) === '1';
                $uri = $scheme . ':///' . $folderPath;
                $entries = [];
                foreach ($files->listContents($uri, $recursive) as $entry) {
                    if (count($entries) === 100) {
                        break;
                    }
                    $entries[] = ['path' => $entry->path(), 'type' => $entry->isDir() ? 'Folder' : 'File'];
                }
                $folderListing = ['uri' => $uri, 'entries' => $entries];
            } elseif ($action === 'probe') {
                $info = demoProbe($files, $scheme);
            } else {
                $upload = $_FILES['file'] ?? [];
                if (($upload['error'] ?? null) !== UPLOAD_ERR_OK) {
                    throw new InvalidArgumentException('Choose a file that PHP received successfully.');
                }
                $tempPath = $upload['tmp_name'];
                $uri = $scheme . ':///virtualfs-upload-' . bin2hex(random_bytes(12)) . '.bin';
                $files->saveUpload($upload, $uri);
                try {
                    $info = $files->metadata($uri);
                    if (!is_string($hash = hash_file('sha256', $tempPath)) || $hash !== hash('sha256', $files->read($uri))) {
                        throw new RuntimeException('Upload readback validation failed.');
                    }
                } finally {
                    $files->delete($uri);
                }
            }
            if ($action !== 'browse') {
                $result = ['scheme' => $scheme, 'action' => $action, 'metadata' => $info];
            }
        } catch (Throwable $exception) {
            http_response_code(422);
            $error = 'The operation failed for ' . $mounts[$scheme]['name'] . ' (' . $exception::class . '). Check the path, storage connection and folder permissions.';
        }
    }
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Storage lab · Simple VirtualFS</title>
    <link rel="stylesheet" href="/style.css">
</head>
<body>
<header class="masthead"><div class="wrap">Simple VirtualFS <span>Local demo</span></div></header>
<main class="wrap">
    <h1>Storage lab</h1>
    <p class="intro">Run a round-trip test on S3-compatible storage or Google Drive. Tests remove their temporary files after verification.</p>

    <?php if ($error !== null): ?>
        <p class="notice failure" role="alert"><?= html($error) ?></p>
    <?php elseif ($result !== null): ?>
        <section class="notice success" aria-labelledby="result-title">
            <h2 id="result-title"><?= $result['action'] === 'upload' ? 'Upload verified' : 'Round trip verified' ?></h2>
            <dl>
                <div><dt>Mount</dt><dd><?= html($result['scheme']) ?>:///</dd></div>
                <div><dt>MIME type</dt><dd><?= html($result['metadata']['mimeType']) ?></dd></div>
                <div><dt>Stored size</dt><dd><?= $result['metadata']['size'] ?> bytes</dd></div>
                <div><dt>Modified (UTC)</dt><dd><?= gmdate('Y-m-d H:i:s', $result['metadata']['lastModified']) ?></dd></div>
            </dl>
        </section>
    <?php endif; ?>

    <section aria-labelledby="sources-title">
        <h2 id="sources-title">Storage sources</h2>
        <div class="cards">
            <?php foreach ($sources as $scheme => $source): $mount = $mounts[$scheme] ?? null; ?>
                <article class="card">
                    <p class="provider"><?= html($source['provider']) ?></p>
                    <h3><?= html($mount['name'] ?? $source['name']) ?></h3>
                    <p><code><?= html($scheme) ?>:///</code><?= ' · ' . html($mount['type'] ?? $driveIssue) ?></p>
                    <?php if ($mount !== null): ?>
                        <form method="post">
                            <input type="hidden" name="csrf" value="<?= html($_SESSION['csrf']) ?>">
                            <input type="hidden" name="scheme" value="<?= html($scheme) ?>">
                            <button type="submit" name="action" value="probe">Run round-trip test</button>
                        </form>
                    <?php else: ?>
                        <?php if (demoHasDriveCredentials()): ?>
                            <p>Check the OAuth values, folder path and Drive permissions.</p>
                        <?php else: ?>
                            <p>Add the OAuth values to <code>demo/.env</code> and restart Compose.</p>
                        <?php endif; ?>
                    <?php endif; ?>
                </article>
            <?php endforeach; ?>
        </div>
    </section>

    <section class="card operation" aria-labelledby="browse-title">
        <h2 id="browse-title">Browse virtual folders</h2>
        <form method="post">
            <input type="hidden" name="csrf" value="<?= html($_SESSION['csrf']) ?>">
            <div class="fields">
                <div>
                    <label for="browse-scheme">Storage source</label>
                    <select id="browse-scheme" name="scheme">
                        <?php foreach ($mounts as $mount): ?>
                            <option value="<?= html($mount['scheme']) ?>" <?= $mount['scheme'] === ($scheme ?? null) ? 'selected' : '' ?>><?= html($mount['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label for="folder">Folder path</label>
                    <input id="folder" name="folder" type="text" value="<?= html($folderPath) ?>" aria-describedby="folder-help">
                    <p id="folder-help">Leave blank to browse the mount root.</p>
                </div>
            </div>
            <label for="recursive"><input id="recursive" type="checkbox" name="recursive" value="1" <?= $recursive ? 'checked' : '' ?>> Include subfolders</label>
            <button type="submit" name="action" value="browse">List contents</button>
        </form>
        <?php if ($folderListing !== null): ?>
            <h3>Contents of <code><?= html($folderListing['uri']) ?></code></h3>
            <?php if ($folderListing['entries'] === []): ?>
                <p>No entries returned.</p>
            <?php else: ?>
                <ul>
                    <?php foreach ($folderListing['entries'] as $entry): ?>
                        <li><?= html($entry['type']) ?> <code><?= html($entry['path']) ?></code></li>
                    <?php endforeach; ?>
                </ul>
                <?php if (count($folderListing['entries']) === 100): ?>
                    <p>Showing up to 100 entries.</p>
                <?php endif; ?>
            <?php endif; ?>
        <?php endif; ?>
    </section>

    <section class="card operation" aria-labelledby="upload-title">
        <h2 id="upload-title">Test an upload</h2>
        <p>Choose a file up to 16 MiB. The test compares the original and retrieved bytes.</p>
        <form method="post" enctype="multipart/form-data">
            <input type="hidden" name="csrf" value="<?= html($_SESSION['csrf']) ?>">
            <div class="fields">
                <div><label for="scheme">Storage source</label>
                    <select id="scheme" name="scheme">
                        <?php foreach ($mounts as $mount): ?>
                            <option value="<?= html($mount['scheme']) ?>"><?= html($mount['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div><label for="file">File</label><input id="file" name="file" type="file" required></div>
            </div>
            <button type="submit" name="action" value="upload">Upload and verify</button>
        </form>
    </section>
</main>
</body>
</html>
