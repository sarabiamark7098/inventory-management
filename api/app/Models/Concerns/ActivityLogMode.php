<?php

namespace App\Models\Concerns;

/**
 * A narrow switch for how the activity log reads a freshly inserted record.
 *
 * `spatie/laravel-activitylog` re-reads every model it logs — `fresh()` inside
 * `resolveModelForLogging()` — so that an update is recorded as the database
 * actually stored it. On a single write that is a sensible safeguard. On an
 * import it is one extra SELECT per row, a third of all the queries a bulk
 * insert issues, and it re-reads a row written moments earlier in the same
 * transaction.
 *
 * Inside `SyncService::pushBatch()` the re-read provably cannot change
 * anything: that path only ever inserts, and it sets every column explicitly,
 * so no database default or cast can produce a value the in-memory model does
 * not already hold. Nothing else touches a row that did not exist a moment ago.
 *
 * It is deliberately a single global rather than a parameter threaded through
 * Eloquent's event system, which offers no way to pass one. It applies only to
 * `created` events, only while a batch is running, and `pushBatch()` restores
 * it in a `finally` so a failure cannot leave it on.
 */
final class ActivityLogMode
{
    private static bool $trustInMemoryInserts = false;

    public static function trustsInMemoryInserts(): bool
    {
        return self::$trustInMemoryInserts;
    }

    /** Runs $work with the re-read skipped, and always puts it back. */
    public static function duringTrustedBatch(callable $work): mixed
    {
        $previous = self::$trustInMemoryInserts;
        self::$trustInMemoryInserts = true;

        try {
            return $work();
        } finally {
            self::$trustInMemoryInserts = $previous;
        }
    }
}
