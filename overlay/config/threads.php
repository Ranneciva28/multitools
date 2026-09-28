<?php
return [
        'worker_url' => env('THREADS_WORKER_URL','http://127.0.0.1:3487'),
        'worker_secret' => env('THREADS_WORKER_SECRET'),
        'orders_webhook_secret' => env('ORDERS_WEBHOOK_SECRET'),
        'telegram_token' => env('TELEGRAM_BOT_TOKEN'),
];
