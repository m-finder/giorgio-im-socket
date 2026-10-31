<?php

return [
    // Existing Eloquent user model; null resolves auth.providers.users.model.
    'user_model' => null,
    'user_name_column' => 'name',
    'routes' => [
        'enabled' => true,
        'prefix' => 'api/im',
        'middleware' => ['api'],
        'auth_middleware' => ['auth'],
    ],
    'socket_identity' => \GiorgioImSocket\Services\UserIdentity::class,
    'socket_ticket_ttl' => 60,
    // Default identity lifetime. Use an adapter for immediate session/token revocation.
    'socket_session_ttl' => 3600,
    'configure_socket' => true,
    'require_tls' => env('IM_REQUIRE_TLS', true),
    'allow_insecure_loopback' => env('IM_ALLOW_INSECURE_LOOPBACK', true),
    'socket_url' => env('IM_SOCKET_URL', 'ws://127.0.0.1:9501'),
    'signature_notices' => env('IM_SIGNATURE_NOTICES_ENABLED', true),
    'history_limit' => 50,
    'upload_max_kb' => 10240,
    'sensitive_words' => [
        'enabled' => env('IM_SENSITIVE_WORDS_ENABLED', false),
        // UTF-8 text file, one word per line; defaults to the dependency's basic dictionary.
        'path' => env('IM_SENSITIVE_WORDS_PATH', dirname((new \ReflectionClass(\DfaFilter\SensitiveHelper::class))->getFileName(), 3).'/tests/data/words.txt'),
        'words' => [], // Additional words; set path to null to use only this list.
    ],
    'disk' => env('IM_UPLOAD_DISK', 'local'),
];
