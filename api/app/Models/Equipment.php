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
        // A calendar date, deliberately not a datetime — see the migration.
        'date_acquired' => 'date',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function divisionOffice(): BelongsTo
    {
        return $this->belongsTo(Office::class, 'division_id');
    }

    public function sectionOffice(): BelongsTo
    {
        return $this->belongsTo(Office::class, 'section_id');
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
            'propertyNumber' => $this->property_number,
            'dateAcquired' => $this->date_acquired?->toDateString(),
            'serialNumber' => $this->serial_number,
            'monitorSerialNumber' => $this->monitor_serial_number,
            'categoryId' => $this->category_id,
            'modelName' => $this->model_name,
            'brand' => $this->brand,
            'status' => $this->status,
            'condition' => $this->condition,
            'location' => $this->location,
            'divisionId' => $this->division_id,
            'sectionId' => $this->section_id,
            'province' => $this->province,
            'parIssuedTo' => $this->par_issued_to,
            'subParIssuedTo' => $this->sub_par_issued_to,
            'actualUser' => $this->actual_user,
            'position' => $this->position,
            'adUsername' => $this->ad_username,
            'anydeskNumber' => $this->anydesk_number,
            'specifications' => is_array($this->specifications) ? $this->specifications : [],
            'remarks' => $this->remarks,
            'createdAt' => $this->created_at?->toIso8601ZuluString('millisecond'),
            'updatedAt' => $this->updated_at?->toIso8601ZuluString('millisecond'),
            'deletedAt' => $this->deleted_at?->toIso8601ZuluString('millisecond'),
        ];
    }
}
