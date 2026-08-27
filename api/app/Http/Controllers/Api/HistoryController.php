<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BorrowingTransaction;
use App\Models\Category;
use App\Models\Equipment;
use App\Models\Office;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Spatie\Activitylog\Models\Activity;

/**
 * Activity history: everything anyone has done, newest first (US-2.10).
 *
 * Reads the log written by spatie/laravel-activitylog. Entries are produced by
 * the `RecordsActivity` trait on the model, not by any controller, which is what
 * makes the log complete: a write from the REST API, a console command or a
 * seeder is recorded the same way, and there is no code path that can quietly
 * skip it by forgetting a call.
 *
 * Read-only by construction. No route edits or deletes an entry — an audit trail
 * an operator can tidy up is not an audit trail.
 */
class HistoryController extends Controller
{
    /** A page big enough to scan, small enough not to ship the whole log. */
    private const PER_PAGE = 50;

    private const MAX_PER_PAGE = 200;

    /** The API's nouns, and the label the History screen shows for each. */
    private const ENTITY_LABELS = [
        'equipment' => 'Equipment',
        'categories' => 'Category',
        'offices' => 'Office',
        'borrowings' => 'Loan',
        'users' => 'Account',
    ];

    private const OPERATIONS = ['INSERT', 'UPDATE', 'DELETE', 'RESTORE', 'PURGE'];

    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
            'entityType' => ['nullable', 'string', 'in:'.implode(',', array_keys(self::ENTITY_LABELS))],
            'operation' => ['nullable', 'string', 'in:'.implode(',', self::OPERATIONS)],
            'actor' => ['nullable', 'string', 'max:255'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'page' => ['nullable', 'integer', 'min:1'],
            'perPage' => ['nullable', 'integer', 'min:1', 'max:'.self::MAX_PER_PAGE],
        ]);

        $query = $this->visibleTo($request)->with('causer');

        if ($type = $request->query('entityType')) {
            $query->where('log_name', $type);
        }
        if ($operation = $request->query('operation')) {
            $query->where('event', $operation);
        }
        if ($actor = $request->query('actor')) {
            // Matches whether the account still exists or only the recorded
            // address survives, so filtering by a person does not silently drop
            // everything they did before their account was removed.
            $query->where(fn ($q) => $q
                ->whereHas('causer', fn ($u) => $u->where('email', $actor))
                ->orWhere(fn ($legacy) => $legacy->whereNull('causer_id')
                    ->whereJsonContains('properties->actor_label', $actor)));
        }
        if ($from = $request->date('from')) {
            $query->where('created_at', '>=', $from);
        }
        if ($to = $request->date('to')) {
            // A date with no time means "up to the end of that day", which is
            // what someone picking it on a calendar expects.
            $query->where('created_at', '<=', $to->endOfDay());
        }
        if ($search = $request->query('search')) {
            $like = '%'.$search.'%';
            $subjects = $this->subjectsMatching($search, $request);

            $query->where(fn ($q) => $q->where('subject_id', 'like', $like)
                // The snapshot half, which names the record on the entries that
                // carry a full one — an INSERT, or the PURGE of a record that no
                // longer exists to be looked up.
                ->orWhere('attribute_changes', 'like', $like)
                ->orWhere('properties', 'like', $like)
                // And every entry about a record whose identifiers match, which
                // is the half the snapshot cannot answer: an edit stores only
                // the fields that changed, so searching an asset tag would
                // otherwise miss every edit ever made to that asset.
                ->orWhereIn('subject_id', $subjects));
        }

        $perPage = (int) ($request->query('perPage') ?: self::PER_PAGE);
        $page = $query->orderByDesc('created_at')->orderByDesc('id')
            ->paginate($perPage, ['*'], 'page', (int) ($request->query('page') ?: 1));

        $entries = collect($page->items());
        $labels = $this->resolveLabels($entries);

        return response()->json([
            'data' => $entries->map(fn (Activity $entry) => $this->present($entry, $labels))->all(),
            'meta' => [
                'page' => $page->currentPage(),
                'perPage' => $page->perPage(),
                'total' => $page->total(),
                'lastPage' => $page->lastPage(),
            ],
            'filters' => $this->filterOptions($request),
        ]);
    }

    /**
     * One entry, with the fields it actually changed.
     *
     * The package records `old` and `attributes` per update, so the change is
     * read straight off the entry rather than reconstructed by comparing it with
     * its neighbour. Entries carried over from the previous log have no `old`
     * half, and say so instead of implying every field was new.
     */
    public function show(Request $request, string $id): JsonResponse
    {
        $entry = $this->visibleTo($request)->with('causer')->find($id);

        if (! $entry) {
            return response()->json(['error' => 'No such history entry.'], 404);
        }

        $tracked = $entry->attribute_changes?->toArray() ?? [];
        $after = $tracked['attributes'] ?? null;
        $before = $tracked['old'] ?? null;

        // A destroyed row has no "after". The package moves its final state
        // into `old`, which is the snapshot to show — and there is nothing to
        // diff it against, so the entry offers the record instead of a change.
        $destroyed = $after === null && $before !== null;

        return response()->json([
            'entry' => $this->present($entry, $this->resolveLabels(collect([$entry]))) + [
                'snapshot' => $destroyed ? $before : ($after ?? []),
                'changes' => $destroyed ? [] : $this->diff($before, $after ?? [], $entry->event),
                // Whether the entry can say what changed, as opposed to only
                // what the record held. Entries carried over from the old log
                // have no `old` half and say so rather than implying every
                // field was new.
                'comparable' => ! $destroyed && ($before !== null || $entry->event === 'INSERT'),
            ],
        ]);
    }

    /* ------------------------------------------------------------------ */

    private function present(Activity $entry, array $labels): array
    {
        return [
            'id' => (string) $entry->id,
            'entityType' => $entry->log_name,
            'entityLabel' => self::ENTITY_LABELS[$entry->log_name] ?? $entry->log_name,
            'entityId' => $entry->subject_id,
            'recordLabel' => $labels[$entry->log_name][$entry->subject_id] ?? null,
            'operation' => $entry->event ?? $entry->description,
            'actor' => $this->actor($entry),
            'occurredAt' => $entry->created_at?->toIso8601ZuluString('millisecond'),
        ];
    }

    /**
     * Who performed this, resolved as far as it still can be.
     *
     * The account is the better answer when it still exists, because it carries
     * the current display name. When it does not, the address recorded at the
     * time is all that is left, and saying so plainly is more useful to an
     * auditor than an empty column.
     */
    private function actor(Activity $entry): array
    {
        if ($entry->causer instanceof User) {
            return [
                'id' => $entry->causer->id,
                'name' => $entry->causer->name,
                'email' => $entry->causer->email,
                'deleted' => false,
            ];
        }

        $recorded = $entry->getProperty('actor_label');

        // A write with no signed-in user behind it — a seeder, a console
        // command — is not a person, and labelling it a deleted account
        // would be a lie.
        if ($recorded === null) {
            return ['id' => null, 'name' => 'System', 'email' => null, 'deleted' => false, 'system' => true];
        }

        return ['id' => null, 'name' => $recorded, 'email' => $recorded, 'deleted' => true];
    }

    /**
     * Account activity is admin-only; everything else is visible to anyone
     * signed in.
     *
     * A manager seeing who last edited an asset is the point of the feature. A
     * manager reading the log of who was promoted, demoted or deactivated is
     * account administration, which they cannot perform and should not audit.
     */
    private function visibleTo(Request $request): Builder
    {
        $query = Activity::query();

        if (! $request->user()?->isAdmin()) {
            $query->where('log_name', '!=', 'users');
        }

        return $query;
    }

    /**
     * Ids of the records whose own identifiers match the search term.
     *
     * Searching the log's snapshots alone is not enough to answer "what has
     * happened to this asset": entries record what changed, and an edit to a
     * location or a PAR holder carries no asset tag at all. Resolving the term
     * against the live tables first turns a search for a tag into a search for
     * that record's whole history.
     *
     * @return array<int, string>
     */
    private function subjectsMatching(string $search, Request $request): array
    {
        $like = '%'.$search.'%';

        $ids = Equipment::query()
            ->where(fn ($q) => $q->where('asset_tag', 'like', $like)
                ->orWhere('property_number', 'like', $like)
                ->orWhere('serial_number', 'like', $like)
                ->orWhere('monitor_serial_number', 'like', $like)
                ->orWhere('model_name', 'like', $like))
            ->pluck('id')
            ->concat(Category::query()->where('name', 'like', $like)->pluck('id'))
            ->concat(Office::query()->where(fn ($q) => $q->where('name', 'like', $like)
                ->orWhere('code', 'like', $like))->pluck('id'))
            ->concat(BorrowingTransaction::query()->where(fn ($q) => $q
                ->where('borrower_name', 'like', $like)
                ->orWhere('borrower_email', 'like', $like))->pluck('id'));

        // Only an admin can see account entries, so only an admin's search may
        // resolve an account into one.
        if ($request->user()?->isAdmin()) {
            $ids = $ids->concat(
                User::query()->where(fn ($q) => $q->where('name', 'like', $like)
                    ->orWhere('email', 'like', $like))->pluck('id')->map(fn ($id) => (string) $id)
            );
        }

        // A record that has been destroyed cannot be resolved from any table,
        // and its earlier entries carry only the fields that changed. Its name
        // does survive on its INSERT and PURGE snapshots, so those are used to
        // recover the id and with it the rest of its history.
        $ids = $ids->concat(
            Activity::query()
                ->whereIn('event', ['INSERT', 'PURGE'])
                ->where('attribute_changes', 'like', $like)
                ->distinct()
                ->pluck('subject_id')
        );

        return $ids->filter()->unique()->values()->all();
    }

    /**
     * The identifying name of each record a page of entries refers to.
     *
     * Resolved from the live tables so a renamed office reads under its current
     * name, and falling back to the entry's own snapshot for records that no
     * longer exist — a purged asset has nothing left but its PURGE entry, and
     * that is exactly when someone is reading the history.
     *
     * @param  Collection<int, Activity>  $entries
     * @return array<string, array<string, string|null>>
     */
    private function resolveLabels(Collection $entries): array
    {
        $lookup = [
            'equipment' => fn ($ids) => Equipment::whereIn('id', $ids)->pluck('asset_tag', 'id'),
            'categories' => fn ($ids) => Category::whereIn('id', $ids)->pluck('name', 'id'),
            'offices' => fn ($ids) => Office::whereIn('id', $ids)->pluck('name', 'id'),
            'borrowings' => fn ($ids) => BorrowingTransaction::whereIn('id', $ids)->pluck('borrower_name', 'id'),
            'users' => fn ($ids) => User::whereIn('id', $ids)->pluck('name', 'id'),
        ];

        $labels = [];

        foreach ($entries->groupBy('log_name') as $type => $group) {
            $live = isset($lookup[$type])
                ? $lookup[$type]($group->pluck('subject_id')->unique())->all()
                : [];

            foreach ($group as $entry) {
                $labels[$type][$entry->subject_id] = $live[$entry->subject_id]
                    ?? $this->labelFromSnapshot($type, self::snapshotOf($entry));
            }
        }

        return $this->fillGapsFromTheLog($labels);
    }

    /**
     * Names the records that neither the live table nor the entry itself could.
     *
     * An entry only carries the fields that changed, so "archived" on its own
     * says nothing about which asset — normally harmless, because the row is
     * still there to look up. After a purge it is not, and the entries leading
     * up to the disposal are exactly the ones an auditor wants to read. The
     * record's name is still in the log, on its INSERT or its PURGE, so it is
     * fetched from there rather than shown as a dash.
     *
     * @param  array<string, array<string, string|null>>  $labels
     * @return array<string, array<string, string|null>>
     */
    private function fillGapsFromTheLog(array $labels): array
    {
        $missing = [];

        foreach ($labels as $type => $byId) {
            foreach ($byId as $id => $label) {
                if ($label === null) {
                    $missing[$type][] = $id;
                }
            }
        }

        if ($missing === []) {
            return $labels;
        }

        // One query for the whole page. INSERT and PURGE are the events that
        // carry a full snapshot; newest first, so the most recent name wins.
        $candidates = Activity::query()
            ->whereIn('event', ['INSERT', 'PURGE'])
            ->where(function (Builder $q) use ($missing) {
                foreach ($missing as $type => $ids) {
                    $q->orWhere(fn ($t) => $t->where('log_name', $type)->whereIn('subject_id', $ids));
                }
            })
            ->orderByDesc('id')
            ->get();

        foreach ($candidates as $entry) {
            if (($labels[$entry->log_name][$entry->subject_id] ?? null) !== null) {
                continue;
            }

            $labels[$entry->log_name][$entry->subject_id] =
                $this->labelFromSnapshot($entry->log_name, self::snapshotOf($entry));
        }

        return $labels;
    }

    /**
     * The record's state as the entry saw it.
     *
     * An update stores it under `attributes`; a destroyed row has no "after" at
     * all and the package files its final state under `old` instead.
     */
    private static function snapshotOf(Activity $entry): ?array
    {
        $tracked = $entry->attribute_changes?->toArray() ?? [];

        return $tracked['attributes'] ?? $tracked['old'] ?? null;
    }

    private function labelFromSnapshot(string $type, $attributes): ?string
    {
        $attributes = $attributes instanceof Collection ? $attributes->toArray() : $attributes;

        if (! is_array($attributes)) {
            return null;
        }

        // Snapshots written by the model trait use column names; those carried
        // over from the old log use the API's camelCase wire format.
        $keys = match ($type) {
            'equipment' => ['asset_tag', 'assetTag'],
            'borrowings' => ['borrower_name', 'borrowerName'],
            default => ['name'],
        };

        foreach ($keys as $key) {
            if (isset($attributes[$key])) {
                return (string) $attributes[$key];
            }
        }

        return null;
    }

    /**
     * Field-level changes, from the package's own before/after pair.
     *
     * An INSERT has nothing to compare against, so every set field is reported
     * as new. Timestamps are dropped: they move on every write and would drown
     * the change that matters.
     *
     * @return array<int, array{field: string, from: mixed, to: mixed}>
     */
    private function diff(?array $before, array $after, ?string $operation): array
    {
        $noise = ['id', 'updated_at', 'created_at', 'updatedAt', 'createdAt'];

        if ($operation === 'INSERT' || $before === null) {
            $before = [];
        }

        return collect(array_keys($before + $after))
            ->reject(fn ($field) => in_array($field, $noise, true))
            ->sort()
            ->values()
            ->map(fn ($field) => [
                'field' => $field,
                'from' => $before[$field] ?? null,
                'to' => $after[$field] ?? null,
            ])
            ->reject(fn ($change) => $change['from'] === $change['to'])
            // A blank string and a null both read as "empty" on screen, so a
            // write that turned one into the other is not worth reporting.
            ->reject(fn ($change) => in_array($change['from'], [null, ''], true)
                && in_array($change['to'], [null, ''], true))
            ->values()
            ->all();
    }

    /**
     * The values actually present in the log, so the filter dropdowns offer only
     * choices that can return something.
     */
    private function filterOptions(Request $request): array
    {
        $causerIds = $this->visibleTo($request)
            ->whereNotNull('causer_id')
            ->distinct()
            ->pluck('causer_id');

        $actors = User::whereIn('id', $causerIds)
            ->orderBy('name')
            ->get()
            ->map(fn (User $u) => ['value' => $u->email, 'label' => $u->name, 'deleted' => false]);

        // Entries whose account is gone, or that were carried over from the old
        // log, keep only the address recorded at the time.
        $orphaned = $this->visibleTo($request)
            ->whereNull('causer_id')
            ->whereNotNull('properties->actor_label')
            ->distinct()
            ->pluck('properties->actor_label')
            ->filter()
            ->map(fn ($label) => ['value' => $label, 'label' => $label, 'deleted' => true]);

        return [
            'actors' => $actors->concat($orphaned)->unique('value')->values()->all(),
            'entityTypes' => collect(self::ENTITY_LABELS)
                ->reject(fn ($label, $type) => $type === 'users' && ! $request->user()?->isAdmin())
                ->map(fn ($label, $type) => ['value' => $type, 'label' => $label])
                ->values()
                ->all(),
            'operations' => collect(self::OPERATIONS)
                ->map(fn ($op) => ['value' => $op, 'label' => ucfirst(strtolower($op))])
                ->all(),
        ];
    }
}
