<?php

namespace SITMinecraft\Launcher\Manifest;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use RuntimeException;

/**
 * Stores the `.mrpack` files an administrator uploads.
 *
 * The launcher refuses to install a pack whose SHA-1 it cannot check, so the
 * digest is always computed here, from the file that actually landed on disk,
 * and stored next to it.
 */
final class PackStore
{
    public const DIRECTORY = 'launcher-packs';

    public function __construct(private Filesystem $disk) {}

    /**
     * @return array{disk_path: string, original_name: string, sha1: string, size: int}
     */
    public function put(UploadedFile $file, string $instanceId, int $revision): array
    {
        if (! $file->isValid()) {
            throw new RuntimeException('upload-invalid');
        }

        $realPath = $file->getRealPath();

        if ($realPath === false || ! is_file($realPath)) {
            throw new RuntimeException('upload-unreadable');
        }

        $size = (int) filesize($realPath);

        if ($size <= 0) {
            throw new RuntimeException('upload-empty');
        }

        if (! $this->looksLikeZip($realPath)) {
            throw new RuntimeException('upload-not-zip');
        }

        $sha1 = sha1_file($realPath);

        if ($sha1 === false) {
            throw new RuntimeException('upload-unreadable');
        }

        $path = sprintf(
            '%s/%s-%d-%s.mrpack',
            self::DIRECTORY,
            $instanceId,
            $revision,
            substr($sha1, 0, 8)
        );

        $stream = fopen($realPath, 'rb');

        if ($stream === false) {
            throw new RuntimeException('upload-unreadable');
        }

        try {
            if (! $this->disk->writeStream($path, $stream)) {
                throw new RuntimeException('upload-store-failed');
            }
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        return [
            'disk_path' => $path,
            'original_name' => $file->getClientOriginalName(),
            'sha1' => $sha1,
            'size' => $size,
        ];
    }

    public function forget(?string $diskPath): void
    {
        $diskPath = trim((string) $diskPath);

        if ($diskPath !== '' && $this->disk->exists($diskPath)) {
            $this->disk->delete($diskPath);
        }
    }

    /**
     * Size of one stored pack, so the manifest can advertise an accurate length.
     */
    public function size(string $diskPath): ?int
    {
        return $this->disk->exists($diskPath) ? (int) $this->disk->size($diskPath) : null;
    }

    /**
     * Absolute path of one stored pack, when the disk has one. Disks without a
     * local path (object storage) return null, and the caller falls back to
     * buffering the file.
     */
    public function absolutePath(string $diskPath): ?string
    {
        try {
            $path = $this->disk->path($diskPath);
        } catch (\Throwable) {
            return null;
        }

        return is_file($path) ? $path : null;
    }

    /**
     * Reads one stored pack into memory. Only used for disks that cannot stream
     * from a local path.
     */
    public function contents(string $diskPath): ?string
    {
        return $this->disk->exists($diskPath) ? $this->disk->get($diskPath) : null;
    }

    /**
     * Reads the first bytes of a file manager uploaded file. Both a plain zip and
     * a modpack start with the local file header signature "PK\x03\x04", which
     * rejects the "renamed a text file" case without needing the zip extension.
     */
    private function looksLikeZip(string $path): bool
    {
        $handle = fopen($path, 'rb');

        if ($handle === false) {
            return false;
        }

        try {
            $signature = (string) fread($handle, 4);
        } finally {
            fclose($handle);
        }

        return $signature === "PK\x03\x04" || $signature === "PK\x05\x06";
    }
}
