<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Restricts accounts to the organisation's own email domains.
 *
 * Configured in `config/accounts.php`; an empty list disables the check so the
 * app stays usable for deployments outside DSWD.
 */
class AllowedEmailDomain implements ValidationRule
{
    public static function domains(): array
    {
        return config('accounts.allowed_email_domains', []);
    }

    public static function passes(?string $email): bool
    {
        $domains = self::domains();
        if ($domains === []) {
            return true;
        }

        $at = strrpos((string) $email, '@');
        if ($at === false) {
            return false;
        }

        return in_array(strtolower(substr((string) $email, $at + 1)), $domains, true);
    }

    /** "@dswd.gov.ph" or "@a.gov.ph or @b.gov.ph" */
    public static function describe(): string
    {
        $domains = self::domains();

        return collect($domains)->map(fn ($d) => '@'.$d)->join(', ', ' or ');
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! self::passes(is_string($value) ? $value : null)) {
            $fail('The :attribute must be a '.self::describe().' address.');
        }
    }
}
