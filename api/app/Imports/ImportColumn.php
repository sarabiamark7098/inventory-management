<?php

namespace App\Imports;

/**
 * One column an import profile understands.
 *
 * A column is matched to a CSV header **by name, not by position**, so the
 * operator can reorder, omit or add columns freely. `$aliases` carries the
 * spellings the field office's own workbook uses, which is why pasting its
 * header row works without editing: `Date Aquired` (misspelt in the source
 * workbook since at least 2024) matches `Date Acquired`.
 *
 * `$targets` is the mechanism that handles the workbook's combined columns.
 * Most columns name one payload field. `PROPERTY NO / SN No.` names two, and a
 * value of `FO11-CO1-24-0012 / DTL9X8` is split across them in order. That one
 * list covers both cases, so there is no separate code path for it.
 */
final class ImportColumn
{
    /**
     * @param  string  $label  The header written into the generated template.
     * @param  list<string>  $targets  Payload keys this column feeds, in the order a
     *                                 combined value splits across them. A key of
     *                                 `spec:foo` lands in `specifications.foo`;
     *                                 `division` and `section` resolve against the
     *                                 office register.
     * @param  list<string>  $aliases  Other accepted headers, including the source
     *                                 workbook's own spellings.
     * @param  bool  $required  Whether a missing header, or a blank cell, is an error.
     * @param  bool  $template  False for columns that are accepted but not offered —
     *                          the combined ones, which exist only so the workbook's
     *                          header row imports as-is.
     * @param  string|null  $soleTarget  For a combined column, which field takes a
     *                                    cell that has no separator. Defaults to the
     *                                    first target. The monitor column needs this:
     *                                    its unseparated values are monitor serials,
     *                                    not property numbers.
     * @param  string  $kind  How the value is read: text, identifier, date, status,
     *                        condition. `identifier` is the one that strips
     *                        placeholders like `N/A`, because a literal there would
     *                        occupy a unique index.
     */
    public function __construct(
        public readonly string $label,
        public readonly array $targets,
        public readonly array $aliases = [],
        public readonly bool $required = false,
        public readonly bool $template = true,
        public readonly ?string $soleTarget = null,
        public readonly string $kind = 'text',
        public readonly ?string $example = null,
        public readonly ?string $note = null,
    ) {}

    /** Where a combined cell with no separator in it belongs. */
    public function soleTarget(): string
    {
        return $this->soleTarget ?? $this->targets[0];
    }

    /** True when this column packs several fields into one cell. */
    public function isCombined(): bool
    {
        return count($this->targets) > 1;
    }

    /** Every header spelling that resolves to this column. */
    public function acceptedHeaders(): array
    {
        return [$this->label, ...$this->aliases];
    }

    public function toArray(): array
    {
        return [
            'label' => $this->label,
            'targets' => $this->targets,
            'aliases' => $this->aliases,
            'required' => $this->required,
            'inTemplate' => $this->template,
            'kind' => $this->kind,
            'example' => $this->example,
            'note' => $this->note,
        ];
    }
}
