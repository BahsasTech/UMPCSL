<?php
/**
 * UMPCSL application configuration.
 *
 * Copy this file to config.php and adjust the values for your environment.
 * config.php is git-ignored so credentials are never committed.
 */
return [
    'app' => [
        'name'      => 'Unique Multi Purposes Cooperative Society Limited',
        'short_name'=> 'UMPCSL',
        'url'       => 'http://localhost:8000',
        'currency'  => '₦',
        'timezone'  => 'Africa/Lagos',
    ],
    'db' => [
        'host'     => '127.0.0.1',
        'port'     => 3306,
        'database' => 'umpcsl',
        'username' => 'root',
        'password' => '',
        'charset'  => 'utf8mb4',
    ],
    // Online payment gateways. Values here are defaults; they can be overridden
    // at runtime from Admin -> Online Payments -> Gateway settings (in `settings`).
    'payments' => [
        'currency'        => 'NGN',
        'default_gateway' => 'test', // test | paystack | remita
        'test_enabled'    => true,   // built-in simulator for development
        'paystack' => [
            'enabled'    => false,
            'public_key' => '',
            'secret_key' => '',
        ],
        'remita' => [
            'enabled'         => false,
            'merchant_id'     => '',
            'service_type_id' => '',
            'api_key'         => '',
            'api_hash_key'    => '',
            'base_url'        => 'https://remitademo.net',
        ],
    ],
    'uploads' => [
        'photos_dir'   => __DIR__ . '/../public/uploads/photos',
        'products_dir' => __DIR__ . '/../public/uploads/products',
    ],
    // Notification providers. Values here are defaults; they can be overridden
    // at runtime from Admin -> Notifications -> Settings (stored in `settings`).
    'notifications' => [
        'email' => [
            'enabled'      => false,
            'driver'       => 'log', // log | mail | smtp
            'from_address' => 'no-reply@umpcsl.com',
            'from_name'    => 'UMPCSL',
            'smtp'         => [
                'host'       => 'smtp.gmail.com',
                'port'       => 587,
                'username'   => '',
                'password'   => '',
                'encryption' => 'tls', // tls | ssl | none
            ],
        ],
        'sms' => [
            'enabled'     => false,
            'driver'      => 'log', // log | generic_http | termii | twilio
            'sender_id'   => 'UMPCSL',
            'api_key'     => '',
            'generic_url' => '',    // e.g. https://gateway/send?to={to}&from={sender}&msg={message}&key={api_key}
            'url_method'  => 'GET',  // GET | POST for generic_http
            'termii_url'  => 'https://api.ng.termii.com/api/sms/send',
            'twilio'      => ['sid' => '', 'token' => '', 'from' => ''],
        ],
    ],
];
