<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Paystack API Mode & Credentials
    |--------------------------------------------------------------------------
    | Supports Test Mode and Live Mode for Paystack Ghana (GHS).
    | You can configure these keys in this file, or directly in
    | Admin Settings -> Paystack Configuration.
    |
    | Test Mode Secret & Public Keys begin with 'sk_test_' and 'pk_test_'.
    | Never commit or expose your Secret Key in frontend code!
    */
    'test_mode' => (bool) ($_SERVER['PAYSTACK_TEST_MODE'] ?? $_ENV['PAYSTACK_TEST_MODE'] ?? getenv('PAYSTACK_TEST_MODE') ?: true),

    'public_key' => $_SERVER['PAYSTACK_PUBLIC_KEY'] ?? $_ENV['PAYSTACK_PUBLIC_KEY'] ?? getenv('PAYSTACK_PUBLIC_KEY') ?: 'pk_test_9b5ba49d293f4a6bd132a15970cac4f8ae4a1f8c',

    'secret_key' => $_SERVER['PAYSTACK_SECRET_KEY'] ?? $_ENV['PAYSTACK_SECRET_KEY'] ?? getenv('PAYSTACK_SECRET_KEY') ?: 'sk_test_PASTE_YOUR_SECRET_KEY_HERE',

    /*
    |--------------------------------------------------------------------------
    | Currency & Supported Payment Channels in Ghana
    |--------------------------------------------------------------------------
    | Paystack supports 'mobile_money' (MTN MoMo, Telecel Cash, AT Money)
    | and 'card' (Visa, Mastercard, Verve).
    */
    'currency' => 'GHS',
    'channels' => ['card', 'mobile_money'],

    /*
    |--------------------------------------------------------------------------
    | API Base URL & Timeout
    |--------------------------------------------------------------------------
    */
    'api_url' => 'https://api.paystack.co',
    'timeout' => 25,
];
