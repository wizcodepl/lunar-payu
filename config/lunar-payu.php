<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Driver name
    |--------------------------------------------------------------------------
    |
    | The key under which the driver registers itself in Lunar's
    | `PaymentManager`. This is what you use in `config/lunar.php` under
    | `payments.types.<key>.driver` and what the storefront passes to
    | `Payments::driver(<key>)`.
    |
    */
    'driver' => 'payu',

    /*
    |--------------------------------------------------------------------------
    | Credentials
    |--------------------------------------------------------------------------
    |
    | Issued in the PayU merchant panel under "My shops → REST API".
    |   - `pos_id`         : numeric Point Of Sale ID (`merchantPosId`)
    |   - `client_id`      : OAuth `client_id` (often the same as `pos_id`)
    |   - `client_secret`  : OAuth `client_secret`
    |   - `second_key`     : the "Second key" used to sign webhook bodies
    |                        (HMAC-SHA256). PayU also calls this "MD5 key"
    |                        in older docs — same value, just renamed.
    |
    */
    'pos_id' => env('PAYU_POS_ID', ''),
    'client_id' => env('PAYU_CLIENT_ID', ''),
    'client_secret' => env('PAYU_CLIENT_SECRET', ''),
    'second_key' => env('PAYU_SECOND_KEY', ''),

    /*
    |--------------------------------------------------------------------------
    | Base URL
    |--------------------------------------------------------------------------
    |
    | Single switch between PayU environments. Defaults to production.
    | For local / staging set:
    |
    |   PAYU_BASE_URL=https://secure.snd.payu.com
    |
    | PayU also runs regional production instances — `secure.payu.com` is the
    | Polish one, see PayU docs for `secure.payu.cz`, `secure.payu.ro`, etc.
    |
    */
    'base_url' => env('PAYU_BASE_URL', 'https://secure.payu.com'),

    /*
    |--------------------------------------------------------------------------
    | Webhook path
    |--------------------------------------------------------------------------
    |
    | Path under your application root where PayU posts notifications.
    | Must match the URL configured in the PayU merchant panel.
    |
    */
    'webhook_path' => env('PAYU_WEBHOOK_PATH', 'payu/notify'),

    /*
    |--------------------------------------------------------------------------
    | Return URLs
    |--------------------------------------------------------------------------
    |
    | Where PayU sends the customer back after the hosted payment page.
    | Both URLs receive the order id as a query string and your storefront
    | should resolve final state from the order, not from the URL.
    |
    */
    'return_url_success' => env('PAYU_RETURN_URL_SUCCESS', ''),
    'return_url_error' => env('PAYU_RETURN_URL_ERROR', ''),

    /*
    |--------------------------------------------------------------------------
    | Log channel
    |--------------------------------------------------------------------------
    |
    | Channel used for incoming-notification log lines. Defaults to the
    | application's `stack`. Override for dedicated PSP logs.
    |
    */
    'log_channel' => env('PAYU_LOG_CHANNEL', 'stack'),
];
