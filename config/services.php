<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Checkbox PRRO API Settings
    |--------------------------------------------------------------------------
    */
    'checkbox' => [
        'api_url' => env('CHECKBOX_API_URL'),
        'license_key' => env('CHECKBOX_LICENSE_KEY'),
        'login' => env('CHECKBOX_LOGIN') ?: env('CHECKBOX_CASHIER_LOGIN'),
        'password' => env('CHECKBOX_PASSWORD') ?: env('CHECKBOX_CASHIER_PASSWORD'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Nova Poshta API Settings
    |--------------------------------------------------------------------------
    */
    'nova_poshta' => [
        'api_key'          => env('NOVA_POSHTA_API_KEY'),
        
        // Дані відправника (ФОП/Компанія)
        'sender_ref'       => env('NP_SENDER_REF'),
        'contact_ref'      => env('NP_CONTACT_REF'),
        'sender_phone'     => env('NP_SENDER_PHONE'),
        
        // Локація відправки (Точка виїзду посилок)
        'sender_city'      => env('NP_SENDER_CITY'),
        'sender_warehouse' => env('NP_SENDER_WAREHOUSE'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Meta / Facebook / Instagram
    |--------------------------------------------------------------------------
    */
    'meta' => [
        'app_id' => env('META_APP_ID'),
        'app_secret' => env('META_APP_SECRET'),
        'graph_version' => env('META_GRAPH_VERSION', 'v24.0'),
        'scopes' => array_filter(array_map('trim', explode(',', (string) env(
            'META_SCOPES',
            'pages_show_list,pages_messaging,pages_manage_metadata,pages_read_engagement,instagram_basic,instagram_manage_messages,instagram_manage_comments,business_management'
        )))),
    ],

    /*
    |--------------------------------------------------------------------------
    | OpenAI
    |--------------------------------------------------------------------------
    */
    'openai' => [
        'api_key' => env('OPENAI_API_KEY'),
        'base_url' => env('OPENAI_BASE_URL', 'https://api.openai.com/v1'),
        'model' => env('OPENAI_MODEL', 'gpt-5-mini'),
        'timeout' => (int) env('OPENAI_TIMEOUT', 30),
        'store' => env('OPENAI_STORE', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Chat AI Agent
    |--------------------------------------------------------------------------
    */
    'chat_ai' => [
        'enabled' => env('CHAT_AI_ENABLED', true),
        'default_agent_code' => env('CHAT_AI_DEFAULT_AGENT_CODE', 'sales_assistant_v1'),
        'reply_delay_seconds' => (int) env('CHAT_AI_REPLY_DELAY_SECONDS', 12),
        'allow_assigned_conversations' => env('CHAT_AI_ALLOW_ASSIGNED_CONVERSATIONS', true),
        'max_messages' => (int) env('CHAT_AI_MAX_MESSAGES', 12),
    ],

];
