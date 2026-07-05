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

    /** @param resource $stream */
    public function writeStream(string $path, $stream): string
    {
        $this->fs->writeStream($path, $stream);

        return $this->url($path);
    }

    public function write(string $path, string $contents): string
    {
        $this->fs->write($path, $contents);

        return $this->url($path);
    }

    public function delete(string $path): void
    {
        if ($this->fs->fileExists($path)) {
            $this->fs->delete($path);
        }
    }

    public function url(string $path): string
    {
        return $this->publicUrl . '/' . ltrim($path, '/');
    }
}
