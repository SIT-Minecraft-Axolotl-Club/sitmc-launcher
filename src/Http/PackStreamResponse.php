<?php

namespace SITMinecraft\Launcher\Http;

use Illuminate\Http\Response;

/**
 * Streams a stored pack to the launcher without reading it into memory.
 *
 * Blessing Skin runs a global middleware that calls `cookie()` on every
 * response, and only Laravel's own response classes offer that method, so the
 * controller cannot return a plain Symfony `StreamedResponse`. This subclass
 * keeps the Laravel response contract and sends the file in chunks, which is
 * what makes multi-hundred-megabyte packs safe to serve from PHP.
 */
final class PackStreamResponse extends Response
{
    private const CHUNK_SIZE = 262144;

    private string $path;

    public function __construct(string $path, string $downloadName)
    {
        $this->path = $path;

        parent::__construct('', 200, [
            'Content-Type' => 'application/zip',
            'Content-Length' => (string) filesize($path),
            'Content-Disposition' => 'attachment; filename="'.addslashes($downloadName).'"',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function sendContent(): static
    {
        $handle = fopen($this->path, 'rb');

        if ($handle === false) {
            return $this;
        }

        try {
            while (! feof($handle)) {
                $chunk = fread($handle, self::CHUNK_SIZE);

                if ($chunk === false) {
                    break;
                }

                echo $chunk;
            }
        } finally {
            fclose($handle);
        }

        return $this;
    }
}
