<x-mail::message>
# Your sign-in code

Hello {{ $name }},

Use this one-time code to sign in to the ICT Equipment &amp; Borrowing
Management System:

<x-mail::panel>
# {{ $code }}
</x-mail::panel>

It expires in **{{ $ttlMinutes }} minutes** and can only be used once.

If you did not try to sign in, you can ignore this email — nobody can access
your account without this code.

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
