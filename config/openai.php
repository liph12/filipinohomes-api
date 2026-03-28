<?php

return [
    'guest_limit' => env('OPENAI_GUEST_LIMIT', 20),
    'auth_limit' => env('OPENAI_AUTH_LIMIT', 50),
    'auth_limit_create' => env('OPENAI_AUTH_LIMIT_CREATE', 5),
];