<?php

declare(strict_types=1);

namespace Richie314\SimpleVirtualFS;

use Aws\S3\S3ClientInterface;
use Google\Service\Drive;
use GuzzleHttp\Client as HttpClient;
use InvalidArgumentException;
use League\Flysystem\AwsS3V3\AwsS3V3Adapter;
use League\Flysystem\DirectoryListing;
use League\Flysystem\FileAttributes;
use League\Flysystem\Filesystem;
use League\Flysystem\FilesystemAdapter;
use League\Flysystem\Local\LocalFilesystemAdapter;
use League\Flysystem\UnableToReadFile;
use League\Flysystem\UnableToRetrieveMetadata;
use League\Flysystem\WebDAV\WebDAVAdapter;
use League\MimeTypeDetection\GeneratedExtensionToMimeTypeMap;
use Masbug\Flysystem\GoogleDriveAdapter;
use Netzarbeiter\FlysystemHttp\HttpAdapterPsr;
use RuntimeException;
use Sabre\DAV\Client as WebDavClient;

/** Routes virtual URIs to storage adapters and applies content encryption on this machine. */
final class VirtualFilesystem
{
    private const ENCRYPTED_SUFFIX = '.enc.bin';

    private array $filesystems = [];
    private array $localRoots = [];
    private array $sourceKeys = [];
    private array $fileKeys = [];
    private array $serverMimeTypes = [];
    /** @var array<string, array{scheme: string, type: string, name: string}> */
    private array $mountPoints = [];

    /**
     * Register a storage adapter under a unique, case-insensitive URI scheme.
     *
     * @param string $scheme Bare scheme name, for example `app`.
     * @param FilesystemAdapter $adapter Configured Flysystem 3 storage adapter.
     * @param string|null $key Optional source-wide encryption key of 32 raw bytes.
     * @param array<string, mixed> $options Flysystem defaults for this mount.
     * @param bool $serverMimeType Enable only when the adapter supplies MIME via metadata requests.
     * @param string|null $name Display name, defaulting to the mount URI.
     * @param string|null $type Optional storage type label, defaulting to the adapter class name.
     * @throws InvalidArgumentException If the scheme or key is invalid or the scheme is already mounted.
     */
    public function mount(
        string $scheme,
        FilesystemAdapter $adapter,
        #[\SensitiveParameter] ?string $key = null,
        array $options = [],
        bool $serverMimeType = false,
        ?string $name = null,
        ?string $type = null,
    ): void {
        $scheme = strtolower($scheme);
        if (!preg_match('/\A[a-z][a-z0-9+.-]*\z/', $scheme) || isset($this->filesystems[$scheme])) {
            throw new InvalidArgumentException('Invalid or already mounted scheme.');
        }
        if ($key !== null) {
            ContentEncryption::validateKey($key);
        }

        $this->filesystems[$scheme] = new Filesystem($adapter, $options);
        $this->sourceKeys[$scheme] = $key;
        $this->serverMimeTypes[$scheme] = $serverMimeType;
        $this->mountPoints[$scheme] = [
            'scheme' => $scheme,
            'type' => $type ?? $adapter::class,
            'name' => $name ?? "$scheme:///",
        ];
    }

    /**
     * Mount an existing local directory.
     * Requested paths cannot traverse symbolic links.
     *
     * @param string $scheme Bare scheme name.
     * @param string $root Existing directory on this machine.
     * @param string|null $key Optional source-wide encryption key of 32 raw bytes.
     * @param string|null $name Display name, defaulting to the mount URI.
     * @throws InvalidArgumentException If the directory, scheme or key is invalid.
     */
    public function mountLocal(
        string $scheme,
        string $root,
        #[\SensitiveParameter] ?string $key = null,
        ?string $name = null,
    ): void {
        $root = realpath($root);
        if ($root === false || !is_dir($root)) {
            throw new InvalidArgumentException('Expected an existing local root directory.');
        }

        $this->mount($scheme, new LocalFilesystemAdapter($root, linkHandling: LocalFilesystemAdapter::SKIP_LINKS), $key, name: $name, type: 'local');
        $this->localRoots[strtolower($scheme)] = $root;
    }

    /**
     * Mount an S3 or S3-compatible bucket using a configured AWS SDK client.
     *
     * @param string $scheme Bare scheme name.
     * @param S3ClientInterface $client Client configured for the endpoint, region and credentials.
     * @param string $bucket Bucket name.
     * @param string $prefix Optional key prefix within the bucket.
     * @param string|null $key Optional source-wide encryption key of 32 raw bytes.
     * @param string|null $name Display name, defaulting to the mount URI.
     * @param array<string, mixed> $options Flysystem defaults, such as an upload ACL.
     */
    public function mountS3(
        string $scheme,
        S3ClientInterface $client,
        string $bucket,
        string $prefix = '',
        #[\SensitiveParameter] ?string $key = null,
        array $options = [],
        ?string $name = null,
    ): void {
        $this->mount($scheme, new AwsS3V3Adapter($client, $bucket, $prefix), $key, $options, serverMimeType: true, name: $name, type: 's3');
    }

    /**
     * Mount a Google Drive folder through an authenticated Drive API service.
     *
     * @param string $scheme Bare scheme name.
     * @param Drive $drive Authenticated service with access to the target files.
     * @param string $root Folder path relative to the configured Drive or shared folder.
     * @param array<string, mixed> $options Drive adapter options such as sharedFolderId and teamDriveId.
     * @param string|null $key Optional source-wide encryption key of 32 raw bytes.
     * @param string|null $name Display name, defaulting to the mount URI.
     */
    public function mountGoogleDrive(
        string $scheme,
        Drive $drive,
        string $root = '/',
        array $options = [],
        #[\SensitiveParameter] ?string $key = null,
        ?string $name = null,
    ): void {
        $this->mount($scheme, new GoogleDriveAdapter($drive, $root, $options), $key, serverMimeType: true, name: $name, type: 'google-drive');
    }

    /**
     * Mount a WebDAV directory through a Sabre client.
     *
     * @param string $scheme Bare scheme name.
     * @param WebDavClient $client Client configured with the directory's base URL and authentication.
     * @param string|null $key Optional source-wide encryption key of 32 raw bytes.
     * @param string|null $name Display name, defaulting to the mount URI.
     */
    public function mountWebDav(
        string $scheme,
        WebDavClient $client,
        #[\SensitiveParameter] ?string $key = null,
        ?string $name = null,
    ): void {
        $this->mount($scheme, new WebDAVAdapter($client), $key, serverMimeType: true, name: $name, type: 'webdav');
    }

    /**
     * Mount a read-only HTTP or HTTPS endpoint through a configured Guzzle client.
     *
     * @param string $scheme Bare scheme name.
     * @param HttpClient $client Client configured with the endpoint's base URI and request options.
     * @param string|null $key Optional source-wide key for files already encrypted for this mount.
     * @param string|null $name Display name, defaulting to the mount URI.
     * @throws InvalidArgumentException If the client has no absolute HTTP or HTTPS base URI.
     */
    public function mountHttp(
        string $scheme,
        HttpClient $client,
        #[\SensitiveParameter] ?string $key = null,
        ?string $name = null,
    ): void {
        $base = $client->getConfig('base_uri');
        if ($base === null || !in_array($base->getScheme(), ['http', 'https'], true) || $base->getHost() === '') {
            throw new InvalidArgumentException('Expected an absolute HTTP or HTTPS base URI.');
        }

        $this->mount($scheme, new HttpAdapterPsr($client), $key, name: $name, type: $base->getScheme());
    }

    /**
     * List the registered mounts in registration order without contacting storage.
     *
     * @return list<array{scheme: string, type: string, name: string}> Bare scheme, storage type and display name.
     */
    public function listMounts(): array
    {
        return array_values($this->mountPoints);
    }

    /**
     * Set the content key for one virtual URI, or remove its override to restore the mount default.
     *
     * @param string $uri Exact file URI, including its mounted scheme.
     * @param string|null $key 32 raw bytes, or null to remove the override.
     * @throws InvalidArgumentException If the URI or key is invalid.
     */
    public function setFileKey(string $uri, #[\SensitiveParameter] ?string $key): void
    {
        [, , $uri] = $this->resolve($uri);
        if ($key === null) {
            unset($this->fileKeys[$uri]);
            return;
        }

        ContentEncryption::validateKey($key);
        $this->fileKeys[$uri] = $key;
    }

    /**
     * Read the entire file as bytes and authenticate and decrypt it when a key is configured.
     *
     * @throws RuntimeException If the encryption configuration or authentication fails.
     * @throws \League\Flysystem\UnableToReadFile If the adapter cannot read the file.
     */
    public function read(string $uri): string
    {
        [$fs, $path, $uri, $key, $localPath] = $this->resolve($uri);
        $preferredPath = $key === null ? $path : $this->encryptedPath($path, $localPath);
        try {
            $content = $fs->read($preferredPath);
        } catch (UnableToReadFile $error) {
            $existingPath = $this->existingFilePath($fs, $path, $key !== null, $localPath);
            if ($existingPath === null || $existingPath === $preferredPath) {
                throw UnableToReadFile::fromLocation($uri, previous: $error);
            }
            $content = $fs->read($existingPath);
        }

        return ContentEncryption::decode($content, $uri, $key);
    }

    /**
     * Write binary-safe content, replacing the destination and applying its configured encryption.
     *
     * @throws \League\Flysystem\UnableToWriteFile If the adapter cannot store the content.
     */
    public function write(string $uri, #[\SensitiveParameter] string $content): void
    {
        [$fs, $path, $uri, $key, $localPath] = $this->resolve($uri);
        $encryptedPath = $this->encryptedPath($path, $localPath);
        if ($key === null && $fs->fileExists($encryptedPath)) {
            throw new RuntimeException("Configure the encryption key before replacing $uri.");
        }

        // Remove an older unsuffixed file only after its encrypted replacement is stored.
        $replaceLegacy = $key !== null && $fs->fileExists($path);
        $fs->write($key === null ? $path : $encryptedPath, ContentEncryption::encode($content, $uri, $key));
        if ($replaceLegacy) {
            $fs->delete($path);
        }
    }

    /**
     * Check whether a virtual URI refers to a file.
     *
     * @throws \League\Flysystem\UnableToCheckFileExistence If the adapter cannot check the path.
     */
    public function exists(string $uri): bool
    {
        [$fs, $path, , $key, $localPath] = $this->resolve($uri);
        return $this->existingFilePath($fs, $path, $key !== null, $localPath) !== null;
    }

    /**
     * Delete a file at a virtual URI.
     *
     * @throws \League\Flysystem\UnableToDeleteFile If the adapter cannot delete the file.
     */
    public function delete(string $uri): void
    {
        [$fs, $path, , , $localPath] = $this->resolve($uri);
        $encryptedPath = $this->encryptedPath($path, $localPath);
        if ($fs->fileExists($encryptedPath)) {
            if ($fs->fileExists($path)) {
                $fs->delete($path);
            }
            $fs->delete($encryptedPath);
            return;
        }

        $fs->delete($path);
    }

    /**
     * Copy a virtual file, applying the destination's encryption policy and replacing any existing file there.
     * Identical virtual URIs are a no-op.
     */
    public function copy(string $from, string $to): void
    {
        [, , $source] = $this->resolve($from);
        [, , $destination] = $this->resolve($to);
        if ($source !== $destination) {
            $this->write($destination, $this->read($source));
        }
    }

    /**
     * Copy a virtual file, then delete its source.
     * A failed deletion can leave both copies.
     * Identical virtual URIs are a no-op.
     *
     * @throws InvalidArgumentException If distinct local paths refer to the same file.
     */
    public function move(string $from, string $to): void
    {
        [$sourceFs, $sourcePath, $source, $sourceKey, $sourceLocal] = $this->resolve($from);
        [$destinationFs, $destinationPath, $destination, $destinationKey, $destinationLocal] = $this->resolve($to);
        if ($source === $destination) {
            return;
        }
        if ($sourceLocal !== null && $destinationLocal !== null) {
            if ($this->existingFilePath($sourceFs, $sourcePath, $sourceKey !== null, $sourceLocal) === $sourcePath . self::ENCRYPTED_SUFFIX) {
                $sourceLocal .= self::ENCRYPTED_SUFFIX;
            }
            if ($this->existingFilePath($destinationFs, $destinationPath, $destinationKey !== null, $destinationLocal) === $destinationPath . self::ENCRYPTED_SUFFIX) {
                $destinationLocal .= self::ENCRYPTED_SUFFIX;
            }
            $sourceStat = @stat($sourceLocal);
            $destinationStat = @stat($destinationLocal);
            if ($sourceLocal === $destinationLocal
                || ($sourceStat !== false && $destinationStat !== false
                    && $sourceStat['ino'] !== 0 && $sourceStat['ino'] === $destinationStat['ino']
                    && $sourceStat['dev'] === $destinationStat['dev'])
            ) {
                throw new InvalidArgumentException('The source and destination refer to the same local file.');
            }
        }

        $this->copy($source, $destination);
        $this->delete($source);
    }

    /**
     * Return the plaintext size in bytes.
     * Encrypted files are read and authenticated to compute it.
     */
    public function size(string $uri): int
    {
        [$fs, $path, $canonical, $key, $localPath] = $this->resolve($uri);
        if ($key === null && $this->existingFilePath($fs, $path, false, $localPath) === $path . self::ENCRYPTED_SUFFIX) {
            throw new RuntimeException("Configure the encryption key before reading $canonical.");
        }
        return $key === null ? $fs->fileSize($path) : strlen($this->read($uri));
    }

    /** Read the file and detect its plaintext MIME type with PHP Fileinfo. */
    public function mimeType(string $uri): string
    {
        return (new \finfo(FILEINFO_MIME_TYPE))->buffer($this->read($uri)) ?: 'application/octet-stream';
    }

    /**
     * Create a directory using the mounted adapter's directory semantics.
     *
     * @throws \League\Flysystem\UnableToCreateDirectory If the adapter cannot create the directory.
     */
    public function createDirectory(string $uri): void
    {
        [$fs, $path] = $this->resolve($uri);
        $fs->createDirectory($path);
    }

    /**
     * List virtual folder entries without reading file bodies.
     * Pass the scheme root, such as `app:///`, to start at the root of the mount.
     *
     * @param string $uri Virtual folder URI, including its mounted scheme.
     * @param bool $recursive Include descendants when true.
     * @return DirectoryListing Single-use iterable of file and folder attributes with mount-relative paths.
     */
    public function listContents(string $uri, bool $recursive = false): DirectoryListing
    {
        [$fs, $path] = $this->resolve($uri, allowRoot: true);
        return new DirectoryListing($this->virtualEntries($fs->listContents($path, $recursive)));
    }

    /**
     * Return storage metadata without reading or decrypting the file body.
     * MIME comes from the server when enabled, otherwise from the filename extension.
     * For configured encrypted files, MIME is application/octet-stream and size includes encryption overhead.
     *
     * @param string $uri Virtual file URI.
     * @return array{mimeType: string, lastModified: int, size: int} Unix timestamp in seconds and stored size in bytes.
     * @throws UnableToRetrieveMetadata If the adapter cannot provide size or modification time.
     */
    public function metadata(string $uri): array
    {
        [$fs, $path, , $key, $localPath, $scheme] = $this->resolve($uri);
        $storedPath = $this->existingFilePath($fs, $path, $key !== null, $localPath) ?? $path;
        $encrypted = $key !== null || $storedPath !== $path;
        $path = $storedPath;
        $size = $fs->fileSize($path);
        $lastModified = $fs->lastModified($path);
        $mimeType = null;
        if (!$encrypted) {
            if ($this->serverMimeTypes[$scheme]) {
                try {
                    $mimeType = $fs->mimeType($path);
                } catch (UnableToRetrieveMetadata) {
                    // Some servers omit content type while still providing size and modification time.
                }
            }
            $mimeType = $mimeType ?: (new GeneratedExtensionToMimeTypeMap())->lookupMimeType(
                strtolower(pathinfo($path, PATHINFO_EXTENSION)),
            );
        }

        return ['mimeType' => $mimeType ?? 'application/octet-stream', 'lastModified' => $lastModified, 'size' => $size];
    }

    /** Translate an encrypted backing path while checking the final local component. */
    private function encryptedPath(string $path, ?string $localPath): string
    {
        if ($localPath !== null && is_link($localPath . self::ENCRYPTED_SUFFIX)) {
            throw new RuntimeException('Symbolic links are unsupported within local locations.');
        }
        return $path . self::ENCRYPTED_SUFFIX;
    }

    /** Select the encrypted or plain representation, falling back to the other existing file. */
    private function existingFilePath(Filesystem $fs, string $path, bool $encrypted, ?string $localPath): ?string
    {
        if (!$encrypted && $fs->fileExists($path)) {
            return $path;
        }
        $encryptedPath = $this->encryptedPath($path, $localPath);
        if ($fs->fileExists($encryptedPath)) {
            return $encryptedPath;
        }
        return $encrypted && $fs->fileExists($path) ? $path : null;
    }

    /**
     * Present stored file names as virtual paths and omit duplicate names during migration.
     *
     * @return iterable<\League\Flysystem\StorageAttributes>
     */
    private function virtualEntries(DirectoryListing $listing): iterable
    {
        $seen = [];
        foreach ($listing as $entry) {
            $path = $entry->path();
            $directory = $entry->isDir() ? $path : dirname($path);
            if (str_contains(strtolower($directory) . '/', self::ENCRYPTED_SUFFIX . '/')) {
                continue;
            }
            if ($entry->isDir()) {
                yield $entry;
                continue;
            }

            if (str_ends_with(strtolower($path), self::ENCRYPTED_SUFFIX)) {
                $path = substr($path, 0, -strlen(self::ENCRYPTED_SUFFIX));
                if ($path === '' || str_ends_with($path, '/') || str_ends_with(strtolower($path), self::ENCRYPTED_SUFFIX)) {
                    continue;
                }
                $entry = new FileAttributes($path, $entry->fileSize(), $entry->visibility(), $entry->lastModified(), 'application/octet-stream');
            }
            if ($path !== '' && !isset($seen[$path])) {
                $seen[$path] = true;
                yield $entry;
            }
        }
    }

    /**
     * Store one successful HTTP upload from the current request at a caller-chosen virtual URI.
     * The destination may be replaced.
     * PHP cleans up the temporary file at the end of the request.
     *
     * @param array{error: int, tmp_name: string} $file Scalar entry from $_FILES or UploadedFiles::fromField().
     * @param string $destination Virtual file URI with an application-generated name for untrusted uploads.
     * @throws InvalidArgumentException If the entry is malformed.
     * @throws RuntimeException If PHP reports an upload error or the temporary file cannot be verified or read.
     */
    public function saveUpload(array $file, string $destination): void
    {
        if (!is_int($file['error'] ?? null) || !is_string($file['tmp_name'] ?? null)) {
            throw new InvalidArgumentException('Expected a single PHP upload entry.');
        }
        if ($file['error'] !== UPLOAD_ERR_OK) {
            throw new RuntimeException('PHP reported an upload error.', $file['error']);
        }
        if (str_contains($file['tmp_name'], "\0") || !is_uploaded_file($file['tmp_name'])) {
            throw new RuntimeException('Expected a file uploaded in the current HTTP request.');
        }

        $contents = @file_get_contents($file['tmp_name']);
        if ($contents === false) {
            throw new RuntimeException('Could not read the uploaded file.');
        }
        $this->write($destination, $contents);
    }

    /**
     * Validate a virtual URI and resolve its adapter, relative path, canonical URI and encryption policy.
     *
     * @return array{Filesystem, string, string, ?string, ?string, string} Includes the local path and normalized scheme.
     */
    private function resolve(string $uri, bool $allowRoot = false): array
    {
        if (!preg_match('~\A([a-zA-Z][a-zA-Z0-9+.-]*):///(.*)\z~s', $uri, $parts)) {
            throw new InvalidArgumentException('Expected a virtual URI such as app:///documents/file.pdf.');
        }
        $scheme = strtolower($parts[1]);
        $path = rtrim($parts[2], '/');
        if (!isset($this->filesystems[$scheme])) {
            throw new InvalidArgumentException("Unknown storage scheme: $scheme");
        }

        if (preg_match('~[\\\\:%?#]|\p{C}~u', $path) !== 0 || str_starts_with($parts[2], '/')
            || (!$allowRoot && $path === '') || (!$allowRoot && $parts[2] !== $path)
        ) {
            throw new InvalidArgumentException('Invalid virtual path.');
        }
        $localPath = $this->localRoots[$scheme] ?? null;
        if ($localPath !== null) {
            clearstatcache(true);
            if (realpath($localPath) !== $localPath) {
                throw new RuntimeException('The local root has moved or changed.');
            }
        }
        foreach ($path === '' ? [] : explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..' || str_ends_with($segment, '.')
                || trim($segment) !== $segment || str_ends_with(strtolower($segment), self::ENCRYPTED_SUFFIX)
            ) {
                throw new InvalidArgumentException('Invalid virtual path segment.');
            }
            if ($localPath !== null) {
                $localPath = rtrim($localPath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $segment;
                if (is_link($localPath)) {
                    throw new RuntimeException('Symbolic links are unsupported within local locations.');
                }
            }
        }

        $canonical = "$scheme:///$path";
        return [
            $this->filesystems[$scheme],
            $path,
            $canonical,
            $this->fileKeys[$canonical] ?? $this->sourceKeys[$scheme],
            $localPath,
            $scheme,
        ];
    }
}
