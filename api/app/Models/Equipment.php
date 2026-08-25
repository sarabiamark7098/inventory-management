<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Equipment extends Model
{
    use HasFactory, HasUuids;

    protected $table = 'equipment';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected $casts = [
        'specifications' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(BorrowingTransaction::class);
    }

    public function toSyncArray(): array
    {
        return [
            'id' => $this->id,
            'assetTag' => $this->asset_tag,
            'serialNumber' => $this->serial_number,
            'categoryId' => $this->category_id,
            'modelName' => $this->model_name,
            'brand' => $this->brand,
            'status' => $this->status,
            'condition' => $this->condition,
            'location' => $this->location,
            'specifications' => is_array($this->specifications) ? $this->specifications : [],
            'createdAt' => $this->created_at?->toIso8601ZuluString('millisecond'),
            'updatedAt' => $this->updated_at?->toIso8601ZuluString('millisecond'),
            'deletedAt' => $this->deleted_at?->toIso8601ZuluString('millisecond'),
        ];
    }
}
