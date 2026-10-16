<?php

declare(strict_types=1);

namespace SendClipper;

/** What a download returns. */
final class DownloadedFile
{
    public function __construct(
        public readonly string $content,
        public readonly ?string $contentType,
        public readonly ?string $filename,
    ) {
    }
}
