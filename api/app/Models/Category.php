<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Category extends Model
{
    use HasFactory, HasUuids;

    protected $table = 'categories';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected $casts = [
        'custom_fields' => 'array',
        'default_loan_days' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    public function equipment(): HasMany
    {
        return $this->hasMany(Equipment::class);
    }

    /** Wire format shared with the offline client. */
    public function toSyncArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'customFields' => self::normalizeCustomFields($this->custom_fields),
            'defaultLoanDays' => (int) $this->default_loan_days,
            'createdAt' => $this->created_at?->toIso8601ZuluString('millisecond'),
            'updatedAt' => $this->updated_at?->toIso8601ZuluString('millisecond'),
            'deletedAt' => $this->deleted_at?->toIso8601ZuluString('millisecond'),
        ];
    }

    /** `custom_fields` must always be `{ fields: [...] }`, even for legacy rows. */
    public static function normalizeCustomFields(mixed $value): array
    {
        return is_array($value) && isset($value['fields']) && is_array($value['fields'])
            ? ['fields' => array_values($value['fields'])]
            : ['fields' => []];
    }
}
