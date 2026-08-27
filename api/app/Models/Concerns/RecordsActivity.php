<?php

namespace App\Models\Concerns;

use Spatie\Activitylog\Contracts\Activity;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * Records every change to a model in the activity log (US-2.10).
 *
 * Wraps spatie/laravel-activitylog with the two things it cannot know about
 * this system:
 *
 *  1. **The log name.** Entries are grouped by the vocabulary the API and the
 *     client already share — `equipment`, `categories`, `offices`, `borrowings`,
 *     `users` — rather than by class name or table name, so the History screen's
 *     filter matches the words used everywhere else. `borrowing_transactions`
 *     is the only table whose name differs from its API noun.
 *
 *  2. **What "deleted" means.** Nothing here uses Laravel's SoftDeletes trait;
 *     `deleted_at` is an ordinary column the write path sets and clears. To
 *     Eloquent, archiving an asset and restoring it are both plain `updated`
 *     events, indistinguishable from renaming it — and a real `deleted` event
 *     only ever fires on a purge. Reading the column's transition is what
 *     separates the three.
 *
 * The operation vocabulary is deliberately the same one the API returns and the
 * client renders: INSERT, UPDATE, DELETE, RESTORE, PURGE.
 */
trait RecordsActivity
{
    use LogsActivity;

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName(static::ACTIVITY_LOG_NAME)
            ->logAll()
            // An update that only moved the row's own bookkeeping is not an
            // activity anyone wants to read.
            ->logExcept(['updated_at', 'created_at'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }

    /**
     * Never treat clearing `deleted_at` as a soft-delete restore.
     *
     * The package suppresses the `updated` event that clears that column, on
     * the reasonable assumption that SoftDeletes will fire its own `restored`
     * event a moment later and logging both would double up. Nothing here uses
     * SoftDeletes, so that second event never comes and the suppression means a
     * restore is the one operation the log silently misses — which is precisely
     * the operation an auditor is looking for after an asset reappears.
     */
    protected function isRestoring(): bool
    {
        return false;
    }

    /**
     * Replaces the package's sentence-shaped description with the operation.
     *
     * Spatie writes "updated" for anything that is not a create or a hard
     * delete. Here that single word covers an edit, an archive and a restore,
     * which are three different things to anyone auditing a property record.
     */
    public function beforeActivityLogged(Activity $activity, string $eventName): void
    {
        $operation = $this->activityOperation($eventName);

        // Written to both columns on purpose. `description` is the field the
        // package requires and renders; `event` is an indexed varchar, which is
        // what the History screen's operation filter needs to stay cheap.
        $activity->description = $operation;
        $activity->event = $operation;
    }

    protected function activityOperation(string $eventName): string
    {
        if ($eventName === 'created') {
            return 'INSERT';
        }

        // A real row deletion. Only equipment can reach this, via the archive's
        // purge; for a model with no archive of its own it is an ordinary delete.
        if ($eventName === 'deleted') {
            return array_key_exists('deleted_at', $this->getAttributes()) ? 'PURGE' : 'DELETE';
        }

        if (array_key_exists('deleted_at', $this->getChanges())) {
            $before = $this->getOriginal('deleted_at');
            $after = $this->getAttribute('deleted_at');

            if ($before === null && $after !== null) {
                return 'DELETE';
            }
            if ($before !== null && $after === null) {
                return 'RESTORE';
            }
        }

        return 'UPDATE';
    }
}
