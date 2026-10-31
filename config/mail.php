<?php

// Notification emails are sent by App\Services\Mailer (Brevo or SMTP). This file only satisfies the framework.
return [
    'default' => 'log',
    'mailers' => ['log' => ['transport' => 'log', 'channel' => null], 'array' => ['transport' => 'array']],
    'from' => ['address' => env('MAIL_FROM_ADDRESS', 'no-reply@drfeyselclinic.com'), 'name' => env('MAIL_FROM_NAME', 'Dr. Feysel Tena Medicine')],
];
