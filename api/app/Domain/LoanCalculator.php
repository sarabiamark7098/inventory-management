<?php

namespace App\Domain;

use Carbon\CarbonInterface;

/**
 * Pure loan rules. No database access, so they are cheap to unit test and are
 * mirrored verbatim by the client in `web/src/domain/loan.js`.
 */
final class LoanCalculator
{
    /** Auto-calculated return date from the category default loan duration (US-1.3). */
    public static function expectedReturn(CarbonInterface $issuedAt, int $loanDays): CarbonInterface
    {
        return $issuedAt->copy()->addDays(max(0, $loanDays));
    }

    /**
     * Two loans collide when their [issue, expectedReturn] windows touch.
     * Inclusive on both ends: handing an asset over at the exact moment another
     * loan is due back is still a double booking.
     */
    public static function overlaps(
        CarbonInterface $aStart,
        CarbonInterface $aEnd,
        CarbonInterface $bStart,
        CarbonInterface $bEnd,
    ): bool {
        return $aStart->lessThanOrEqualTo($bEnd) && $bStart->lessThanOrEqualTo($aEnd);
    }

    /**
     * Decides the winner of a dual offline checkout: the loan that was
     * physically created first keeps the asset, with the id as a tie-break so
     * every client and the server independently reach the same verdict.
     */
    public static function incomingCheckoutWins(
        string $incomingId,
        CarbonInterface $incomingCreatedAt,
        string $rivalId,
        CarbonInterface $rivalCreatedAt,
    ): bool {
        if (! $incomingCreatedAt->equalTo($rivalCreatedAt)) {
            return $incomingCreatedAt->lessThan($rivalCreatedAt);
        }

        return strcmp($incomingId, $rivalId) < 0;
    }
}
