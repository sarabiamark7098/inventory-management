<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Replace the free-text office fields with references to the office register,
 * and drop the PAR/Sub-PAR numbers.
 *
 * Division and section were typed by hand, so "Administrative Division" and
 * "Admin Division" were different offices as far as any report was concerned.
 * Pointing at a row instead makes them one thing that can be renamed once.
 *
 * Division is required on new registrations; section stays optional, because
 * plenty of offices assign equipment at division level.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('equipment', function (Blueprint $table) {
            $table->uuid('division_id')->nullable()->after('location');
            $table->uuid('section_id')->nullable()->after('division_id');

            $table->index('division_id');
            $table->index('section_id');

            // Restrict: an office still referenced by equipment must not vanish.
            $table->foreign('division_id')->references('id')->on('offices')->restrictOnDelete();
            $table->foreign('section_id')->references('id')->on('offices')->restrictOnDelete();
        });

        $this->backfillFromFreeText();

        // A section implies its division; fill that in where only the section matched.
        DB::statement(
            'UPDATE equipment e JOIN offices o ON o.id = e.section_id '.
            'SET e.division_id = o.division_id WHERE e.division_id IS NULL'
        );

        $this->preserveWhateverDidNotMatch();

        Schema::table('equipment', function (Blueprint $table) {
            $table->dropColumn(['division', 'section', 'par_number', 'sub_par_number']);
        });
    }

    /**
     * Match the old typed text against the register.
     *
     * Matching is forgiving on purpose. The register prefixes every name with
     * "FO " ("FO Administrative Division") while operators typed the bare name,
     * so an exact comparison matches nothing at all — which is silent, and looks
     * exactly like having no legacy data.
     */
    protected function backfillFromFreeText(): void
    {
        $normalise = fn (string $value) => preg_replace(
            '/\s+/', ' ',
            trim(preg_replace('/^\s*FO\s*-?\s*/i', '', mb_strtolower($value)))
        );

        $lookup = [];
        foreach (DB::table('offices')->get() as $office) {
            foreach ([$office->name, $office->code] as $candidate) {
                $lookup[$normalise($candidate)] = $office;
            }
        }

        foreach (DB::table('equipment')->select('id', 'division', 'section')->get() as $row) {
            $updates = [];

            foreach (['division' => 'division_id', 'section' => 'section_id'] as $source => $column) {
                $typed = $row->{$source};
                if (! $typed) {
                    continue;
                }
                $match = $lookup[$normalise($typed)] ?? null;
                if (! $match) {
                    continue;
                }
                // A division-level office cannot be somebody's section, or vice versa.
                $isDivision = $match->division_id === null;
                if (($column === 'division_id') === $isDivision) {
                    $updates[$column] = $match->id;
                }
            }

            if ($updates !== []) {
                DB::table('equipment')->where('id', $row->id)->update($updates);
            }
        }
    }

    /**
     * Anything still unmatched is appended to `remarks` before the columns go.
     *
     * A migration that silently discards data an operator typed is a migration
     * nobody can audit afterwards. Better an untidy remark than a lost office.
     */
    protected function preserveWhateverDidNotMatch(): void
    {
        $rows = DB::table('equipment')
            ->select('id', 'division', 'section', 'division_id', 'section_id', 'remarks')
            ->where(function ($q) {
                $q->where(fn ($q) => $q->whereNull('division_id')->whereNotNull('division'))
                    ->orWhere(fn ($q) => $q->whereNull('section_id')->whereNotNull('section'));
            })
            ->get();

        foreach ($rows as $row) {
            $lost = [];
            if ($row->division_id === null && $row->division) {
                $lost[] = "division: {$row->division}";
            }
            if ($row->section_id === null && $row->section) {
                $lost[] = "section: {$row->section}";
            }
            if ($lost === []) {
                continue;
            }

            $note = '[Office register migration] Unmatched '.implode('; ', $lost);
            DB::table('equipment')->where('id', $row->id)->update([
                'remarks' => trim(($row->remarks ? $row->remarks."\n" : '').$note),
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('equipment', function (Blueprint $table) {
            $table->string('section')->nullable()->after('location');
            $table->string('division')->nullable()->after('section');
            $table->string('par_number', 100)->nullable()->after('province');
            $table->string('sub_par_number', 100)->nullable()->after('par_issued_to');

            $table->dropForeign(['division_id']);
            $table->dropForeign(['section_id']);
            $table->dropIndex(['division_id']);
            $table->dropIndex(['section_id']);
            $table->dropColumn(['division_id', 'section_id']);
        });
    }
};
