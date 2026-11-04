<?php

declare(strict_types=1);

namespace WeBRTeu\Factura\Data;

use WeBRTeu\Factura\Enums\AnafStatus;

final readonly class AnafStatusData
{
    /**
     * @param  array<array-key, mixed>  $raw
     */
    public function __construct(
        public AnafStatus $status,
        public ?string $uploadIndex = null,
        public ?string $downloadId = null,
        public array $raw = [],
    ) {}

    /**
     * @return array{status: string, upload_index: string|null, download_id: string|null}
     */
    public function toArray(): array
    {
        return ['status' => $this->status->value, 'upload_index' => $this->uploadIndex, 'download_id' => $this->downloadId];
    }
}
