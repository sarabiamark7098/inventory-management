<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** Append-only audit log of every mutation accepted by /api/sync/push. */
class SyncChange extends Model
{
    use HasUuids;

    protected $table = 'sync_changes';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = [
        'data' => 'array',
        'client_timestamp' => 'datetime',
        'synced_at' => 'datetime',
    ];
}
