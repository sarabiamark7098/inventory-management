<?php

namespace Tests\Unit;

use App\Domain\InventoryStatus;
use App\Domain\LoanCalculator;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

/** Pure loan rules — no database, no framework bootstrap. */
class LoanCalculatorTest extends TestCase
{
    private function at(string $iso): CarbonImmutable
    {
        return CarbonImmutable::parse($iso);
    }

    public function test_category_default_loan_days_set_the_due_date(): void
    {
        $issued = $this->at('2026-08-11T09:00:00Z');

        $this->assertTrue(
            LoanCalculator::expectedReturn($issued, 14)->equalTo($this->at('2026-08-25T09:00:00Z')),
        );
        $this->assertTrue(LoanCalculator::expectedReturn($issued, 0)->equalTo($issued));
    }

    public function test_negative_loan_durations_are_clamped_not_applied_backwards(): void
    {
        $issued = $this->at('2026-08-11T09:00:00Z');

        $this->assertTrue(LoanCalculator::expectedReturn($issued, -5)->equalTo($issued));
    }

    public function test_overlapping_loan_windows_are_detected_symmetrically(): void
    {
        $aStart = $this->at('2026-09-01T09:00:00Z');
        $aEnd = $this->at('2026-09-20T09:00:00Z');
        $bStart = $this->at('2026-09-15T09:00:00Z');
        $bEnd = $this->at('2026-09-25T09:00:00Z');

        $this->assertTrue(LoanCalculator::overlaps($aStart, $aEnd, $bStart, $bEnd));
        $this->assertTrue(LoanCalculator::overlaps($bStart, $bEnd, $aStart, $aEnd));

        $farStart = $this->at('2026-09-21T09:00:00Z');
        $farEnd = $this->at('2026-09-25T09:00:00Z');
        $this->assertFalse(LoanCalculator::overlaps($aStart, $aEnd, $farStart, $farEnd));
    }

    public function test_a_loan_fully_contained_in_another_still_overlaps(): void
    {
        $outerStart = $this->at('2026-09-01T09:00:00Z');
        $outerEnd = $this->at('2026-09-30T09:00:00Z');
        $innerStart = $this->at('2026-09-14T09:00:00Z');
        $innerEnd = $this->at('2026-09-16T09:00:00Z');

        $this->assertTrue(LoanCalculator::overlaps($outerStart, $outerEnd, $innerStart, $innerEnd));
        $this->assertTrue(LoanCalculator::overlaps($innerStart, $innerEnd, $outerStart, $outerEnd));
    }

    public function test_dual_checkout_resolves_to_the_earlier_creation_time(): void
    {
        $early = $this->at('2026-08-11T09:00:00Z');
        $late = $this->at('2026-08-12T09:00:00Z');

        // Ids deliberately ordered against the timestamps to prove time wins.
        $this->assertTrue(LoanCalculator::incomingCheckoutWins('bbb', $early, 'aaa', $late));
        $this->assertFalse(LoanCalculator::incomingCheckoutWins('aaa', $late, 'bbb', $early));
    }

    public function test_simultaneous_checkouts_break_the_tie_deterministically_on_id(): void
    {
        $same = $this->at('2026-08-11T09:00:00Z');

        $this->assertTrue(LoanCalculator::incomingCheckoutWins('aaa', $same, 'bbb', $same));
        $this->assertFalse(LoanCalculator::incomingCheckoutWins('bbb', $same, 'aaa', $same));
        // Both sides must never claim victory at once.
        $this->assertNotSame(
            LoanCalculator::incomingCheckoutWins('aaa', $same, 'bbb', $same),
            LoanCalculator::incomingCheckoutWins('bbb', $same, 'aaa', $same),
        );
    }

    public function test_issued_equipment_is_out_of_the_loan_pool(): void
    {
        $this->assertFalse(InventoryStatus::canBeBorrowed('ISSUED'));
        $this->assertFalse(InventoryStatus::canBeBorrowed('MAINTENANCE'));
        $this->assertFalse(InventoryStatus::canBeBorrowed('DECOMMISSIONED'));
        $this->assertFalse(InventoryStatus::canBeBorrowed('LOST'));

        $this->assertTrue(InventoryStatus::canBeBorrowed('AVAILABLE'));
        // Busy, not withdrawn — a future loan is still legitimate.
        $this->assertTrue(InventoryStatus::canBeBorrowed('BORROWED'));
    }

    public function test_an_asset_on_loan_must_be_returned_before_it_is_issued(): void
    {
        $this->assertFalse(InventoryStatus::canTransitionEquipment('BORROWED', 'ISSUED'));
        $this->assertTrue(InventoryStatus::canTransitionEquipment('BORROWED', 'AVAILABLE'));
        $this->assertTrue(InventoryStatus::canTransitionEquipment('AVAILABLE', 'ISSUED'));
        $this->assertTrue(InventoryStatus::canTransitionEquipment('ISSUED', 'AVAILABLE'));
    }

    public function test_equipment_status_machine_blocks_illegal_jumps(): void
    {
        $this->assertTrue(InventoryStatus::canTransitionEquipment('AVAILABLE', 'BORROWED'));
        $this->assertTrue(InventoryStatus::canTransitionEquipment('BORROWED', 'AVAILABLE'));
        $this->assertFalse(InventoryStatus::canTransitionEquipment('BORROWED', 'DECOMMISSIONED'));
        $this->assertFalse(InventoryStatus::canTransitionEquipment('DECOMMISSIONED', 'BORROWED'));
        // Idempotent writes from the sync engine must never be rejected.
        $this->assertTrue(InventoryStatus::canTransitionEquipment('LOST', 'LOST'));
    }
}
