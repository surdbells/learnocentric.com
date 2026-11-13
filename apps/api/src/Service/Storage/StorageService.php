<?php

declare(strict_types=1);

namespace App\Service\Storage;

use League\Flysystem\Filesystem;
use League\Flysystem\Local\LocalFilesystemAdapter;

/**
 * File storage via Flysystem. Local adapter by default (swap the adapter for
 * S3/other in the DI factory without touching callers).
 */
class StorageService
{
    private Filesystem $fs;

    public function __construct(
        private readonly string $rootPath,
        private readonly string $publicUrl,
    ) {
        $this->fs = new Filesystem(new LocalFilesystemAdapter($this->rootPath));
    }

    /**
     * Store the stream and return the bare storage PATH (not a URL). Files are
     * referenced by path and served through the backend (see FileServeAction).
     *
     * @param resource $stream
     */
    public function writeStream(string $path, $stream): string
    {
        $this->fs->writeStream($path, $stream);

        return $path;
    }

    /** Store contents and return the bare storage path. */
    public function write(string $path, string $contents): string
    {
        $this->fs->write($path, $contents);

        return $path;
    }

    public function delete(string $path): void
    {
        if ($this->fs->fileExists($path)) {
            $this->fs->delete($path);
        }
    }

    /**
     * Remove an uploaded file once the record that owned it is gone, unless
     * something else still points at it. Never throws: a file that can't be
     * removed must not undo the delete the user asked for.
     *
     * @param callable():bool $stillReferenced
     */
    public function deleteOrphan(?string $path, callable $stillReferenced): bool
    {
        if ($path === null || $path === '' || preg_match('#^[a-z][a-z0-9+.-]*://#i', $path)) {
            return false; // nothing stored locally (e.g. an external link)
        }
        try {
            if ($stillReferenced()) {
                return false;
            }
            $this->delete($path);
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    public function fileExists(string $path): bool
    {
        return $this->fs->fileExists($path);
    }

    /** @return resource */
    public function readStream(string $path)
    {
        return $this->fs->readStream($path);
    }

    public function mimeType(string $path): string
    {
        try {
            return $this->fs->mimeType($path) ?: 'application/octet-stream';
        } catch (\Throwable) {
            return 'application/octet-stream';
        }
    }

    public function fileSize(string $path): int
    {
        try {
            return (int) $this->fs->fileSize($path);
        } catch (\Throwable) {
            return 0;
        }
    }
}
