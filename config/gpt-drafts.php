<?php

return [
    // Окремі змінні: налаштування робочого агента не читаються.
    'api_key' => env('GPT_DRAFT_API_KEY', ''),
    'model' => env('GPT_DRAFT_MODEL', ''),
    'connection' => env('GPT_DRAFT_QUEUE_CONNECTION', 'database'),
    'queue' => 'gpt-drafts',
    'timeout' => 20,
    'deadline' => 60,
    'max_rounds' => 4,
    'max_tool_calls' => 8,
    'max_input_chars' => 65000,
];
