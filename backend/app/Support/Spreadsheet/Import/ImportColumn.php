<?php

namespace App\Support\Spreadsheet\Import;

use App\Domain\Shared\Support\Messages;

/**
 * One column of an Excel import template ("Fill Here"). The column is identified by its position and
 * its stable id; the header may be written in English or Indonesian (the catalog texts of labelKey), so
 * a template downloaded in either language is read the same way.
 */
final class ImportColumn
{
    public const TEXT = 'text';

    public const DATE = 'date';

    public const NUMBER = 'number';

    public const INTEGER = 'integer';

    public const BOOLEAN = 'boolean';

    /**
     * @param  string  $labelKey  catalog key of the header text
     * @param  string|null  $helpKey  catalog key of the How To description (may carry params)
     */
    public function __construct(
        public readonly string $id,
        public readonly string $labelKey,
        public readonly bool $required = false,
        public readonly string $format = self::TEXT,
        public readonly int $maxLength = 255,
        public readonly ?string $helpKey = null,
        public readonly array $helpParams = [],
        public readonly int $width = 22,
    ) {}

    public function header(string $locale = 'en'): string
    {
        return Messages::text($this->labelKey, [], $locale);
    }

    /** @return list<string> lower-cased accepted header texts (every supported language) */
    public function acceptedHeaders(): array
    {
        return array_values(array_unique(array_map(fn (string $l) => mb_strtolower(trim($this->header($l))), ['en', 'id'])));
    }
}
