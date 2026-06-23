<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * POST події у Meta-вебхук з валідним HMAC-підписом.
     * Вебхук тепер fail-closed (без підпису подія відхиляється), тож тести підписують payload.
     */
    protected function postMetaWebhook(array $payload)
    {
        $secret = (string) config('services.meta.app_secret');
        if ($secret === '') {
            $secret = 'test-secret';
            config(['services.meta.app_secret' => $secret]);
        }

        $body = json_encode($payload);
        $sig = 'sha256=' . hash_hmac('sha256', $body, $secret);

        return $this->call('POST', '/api/meta/webhook', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_HUB_SIGNATURE_256' => $sig,
        ], $body);
    }
}
