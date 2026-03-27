<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\MetaConnection;
use App\Services\MetaService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class MetaServiceSendMessageTest extends TestCase
{
    private string $sourceImagePath;

    private string $normalizedImagePath;

    public function test_send_message_normalizes_webp_attachment_to_jpg_for_meta(): void
    {
        Http::fake([
            'https://graph.facebook.com/*/me/messages' => Http::response([
                'message_id' => 'mid.test-1',
            ], 200),
        ]);

        MetaConnection::query()->create([
            'provider' => 'meta',
            'name' => 'Dream v doma',
            'facebook_page_id' => '103823131052820',
            'access_token' => 'page-token',
            'verify_token' => 'verify-token',
            'webhook_secret' => 'webhook-secret',
            'is_active' => true,
        ]);

        $customer = Customer::query()->create([
            'first_name' => 'Test',
            'fb_user_id' => 'recipient-100',
        ]);

        $attachmentUrl = 'chat/tests/meta_fake.webp';

        $result = app(MetaService::class)->sendMessage(
            $customer,
            '',
            [[
                'type' => 'image',
                'url' => $attachmentUrl,
            ]],
            'messenger'
        );

        $this->assertSame('mid.test-1', $result['message_id'] ?? null);
        $this->assertFileExists($this->normalizedImagePath);

        Http::assertSent(function ($request) {
            $payloadUrl = data_get($request->data(), 'message.attachment.payload.url');
            $path = parse_url((string) $payloadUrl, PHP_URL_PATH) ?: '';

            return str_contains((string) $request->url(), '/me/messages')
                && str_ends_with($path, '.jpg')
                && !str_ends_with($path, '.webp');
        });
    }

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('app.url', 'http://localhost');

        $this->createMinimalSchema();
        $this->createSourceImageFixture();
    }

    protected function tearDown(): void
    {
        @unlink($this->sourceImagePath);
        @unlink($this->normalizedImagePath);

        parent::tearDown();
    }

    private function createMinimalSchema(): void
    {
        if (!Schema::hasTable('meta_connections')) {
            Schema::create('meta_connections', function (Blueprint $table) {
                $table->id();
                $table->string('name')->nullable();
                $table->string('provider', 32)->default('meta');
                $table->string('facebook_page_id')->nullable();
                $table->longText('access_token')->nullable();
                $table->string('verify_token')->nullable();
                $table->string('webhook_secret')->nullable();
                $table->boolean('webhook_subscribed')->default(false);
                $table->json('webhook_fields')->nullable();
                $table->boolean('is_active')->default(true);
                $table->text('last_error')->nullable();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('customers')) {
            Schema::create('customers', function (Blueprint $table) {
                $table->id();
                $table->string('first_name')->nullable();
                $table->string('last_name')->nullable();
                $table->string('phone')->nullable();
                $table->string('email')->nullable();
                $table->text('note')->nullable();
                $table->string('fb_user_id')->nullable();
                $table->string('fb_profile_pic')->nullable();
                $table->string('instagram_user_id')->nullable();
                $table->string('instagram_username')->nullable();
                $table->timestamps();
            });
        }
    }

    private function createSourceImageFixture(): void
    {
        $sourceDir = public_path('chat/tests');
        if (!is_dir($sourceDir)) {
            mkdir($sourceDir, 0777, true);
        }

        $image = imagecreatetruecolor(4, 4);
        $background = imagecolorallocate($image, 212, 170, 155);
        imagefill($image, 0, 0, $background);

        ob_start();
        imagejpeg($image, null, 90);
        $binary = ob_get_clean();
        imagedestroy($image);

        $this->sourceImagePath = $sourceDir . '/meta_fake.webp';
        file_put_contents($this->sourceImagePath, $binary);

        $attachmentUrl = 'chat/tests/meta_fake.webp';
        $relativePath = 'meta/compatible/' . date('Y/m') . '/' . sha1('messenger|' . $attachmentUrl) . '.jpg';
        $this->normalizedImagePath = public_path('chat/' . $relativePath);
    }
}
