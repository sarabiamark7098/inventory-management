<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A division or a section of the field office.
 *
 * Both live in this one table: `division_id` null means the office IS a
 * division, set means it is a section inside that division.
 */
class Office extends Model
{
    use HasUuids;

    protected $table = 'offices';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    public function division(): BelongsTo
    {
        return $this->belongsTo(self::class, 'division_id');
    }

    public function sections(): HasMany
    {
        return $this->hasMany(self::class, 'division_id');
    }

    public function scopeDivisions(Builder $query): Builder
    {
        return $query->whereNull('division_id');
    }

    public function scopeSections(Builder $query): Builder
    {
        return $query->whereNotNull('division_id');
    }

    public function isDivision(): bool
    {
        return $this->division_id === null;
    }

    public function toSyncArray(): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'divisionId' => $this->division_id,
            // Saves every consumer re-deriving the same thing from divisionId.
            'isDivision' => $this->division_id === null,
            'createdAt' => $this->created_at?->toIso8601ZuluString('millisecond'),
            'updatedAt' => $this->updated_at?->toIso8601ZuluString('millisecond'),
            'deletedAt' => $this->deleted_at?->toIso8601ZuluString('millisecond'),
        ];
    }
}
