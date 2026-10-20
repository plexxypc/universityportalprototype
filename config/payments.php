<?php

declare(strict_types=1);

return [

    /*
    | The active adapter. demo, remita, interswitch, or anything else.
    | A value this process cannot use fails when a payment is started.
    | It does not stop the process from booting.
    */
    'provider' => env('PAYMENT_PROVIDER', 'demo'),

    /*
    | Production refuses the Demo Gateway unless this is true.
    | Local and testing may use the demo adapter without it.
    */
    'allow_demo' => filter_var(env('PAYMENT_ALLOW_DEMO', false), FILTER_VALIDATE_BOOL),

    'verify_interval_seconds' => (int) env('PAYMENT_VERIFY_INTERVAL_SECONDS', 5),

    'poll_budget_seconds' => (int) env('PAYMENT_POLL_BUDGET_SECONDS', 20),

    'http_timeout_seconds' => (int) env('PAYMENT_HTTP_TIMEOUT_SECONDS', 5),

    /*
    | Smallest partial payment, in kobo. The whole remaining balance is
    | allowed even when it is smaller than this.
    */
    'minimum_instalment_kobo' => (int) env('PAYMENT_MINIMUM_INSTALMENT_KOBO', 100_000),

    'pending_minutes' => 30,

    'notification_max_bytes' => 4096,

    'notification_rate_per_minute' => (int) env('PAYMENT_NOTIFY_RATE_PER_MINUTE', 30),

    'interswitch' => [
        'merchant_code' => env('INTERSWITCH_MERCHANT_CODE'),
        'pay_item_id' => env('INTERSWITCH_PAY_ITEM_ID'),
        'client_id' => env('INTERSWITCH_CLIENT_ID'),
        'secret' => env('INTERSWITCH_SECRET'),
    ],

];
