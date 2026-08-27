<?php

namespace App\Models;

use App\Models\Concerns\RecordsActivity;
use App\Domain\InventoryStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BorrowingTransaction extends Model
{
    use HasFactory, HasUuids, RecordsActivity;

    /** Groups this model's entries in the activity log. */
    public const ACTIVITY_LOG_NAME = 'borrowings';

    protected $table = 'borrowing_transactions';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected $casts = [
        'issue_date' => 'datetime',
        'expected_return_date' => 'datetime',
        'actual_return_date' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    public function equipment(): BelongsTo
    {
        return $this->belongsTo(Equipment::class);
    }

    /** Loans that physically hold an asset right now. */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('deleted_at')
            ->whereIn('status', InventoryStatus::ACTIVE_LOAN_STATUSES);
    }

    public function toSyncArray(): array
    {
        return [
            'id' => $this->id,
            'equipmentId' => $this->equipment_id,
            'borrowerName' => $this->borrower_name,
            'borrowerEmail' => $this->borrower_email,
            'issueDate' => $this->issue_date?->toIso8601ZuluString('millisecond'),
            'expectedReturnDate' => $this->expected_return_date?->toIso8601ZuluString('millisecond'),
            'actualReturnDate' => $this->actual_return_date?->toIso8601ZuluString('millisecond'),
            'status' => $this->status,
            'notes' => $this->notes,
            'conflictReason' => $this->conflict_reason,
            'createdAt' => $this->created_at?->toIso8601ZuluString('millisecond'),
            'updatedAt' => $this->updated_at?->toIso8601ZuluString('millisecond'),
            'deletedAt' => $this->deleted_at?->toIso8601ZuluString('millisecond'),
        ];
    }
}
