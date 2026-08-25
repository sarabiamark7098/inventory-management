<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Allowed Email Domains
    |--------------------------------------------------------------------------
    |
    | Only addresses on these domains may hold an account or receive a sign-in
    | code. This is an organisational boundary, not a security one — the real
    | control is that accounts are admin-provisioned — but it stops a typo or a
    | personal address from quietly becoming a valid login.
    |
    | Comma separated in the environment, e.g.
    | ACCOUNT_EMAIL_DOMAINS=dswd.gov.ph,fo11.dswd.gov.ph
    |
    | Leave empty to allow any domain.
    |
    */

    'allowed_email_domains' => array_values(array_filter(array_map(
        fn ($domain) => strtolower(trim($domain)),
        explode(',', (string) env('ACCOUNT_EMAIL_DOMAINS', 'dswd.gov.ph')),
    ))),

];
