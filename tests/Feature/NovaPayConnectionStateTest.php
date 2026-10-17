<?php

namespace Tests\Feature;

use App\Models\NovaPayConnection;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class NovaPayConnectionStateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Queue::fake();
        $this->actingAs(User::factory()->create(['role' => 'owner', 'is_active' => true]));
    }

    private function connection(string $name = 'complete'): NovaPayConnection
    {
        return NovaPayConnection::create([
            'login' => 'PRIVATE-LOGIN-'.$name,
            'refresh_token' => 'PRIVATE-REFRESH-'.$name,
            'public_certificate' => 'PRIVATE-CERTIFICATE-'.$name,
            'access_token' => 'PRIVATE-ACCESS-'.$name,
            'token_expires_at' => now()->addHour(),
            'verified_at' => now()->subDay()->startOfSecond(),
            'requires_auth' => true,
        ]);
    }

    public function test_owner_receives_only_truthful_presence_metadata_without_decrypting_or_changing_saved_credentials(): void
    {
        $expected = [];
        foreach (['empty' => [false, false], 'token-only' => [true, false], 'certificate-only' => [false, true], 'complete' => [true, true]] as $name => [$hasToken, $hasCertificate]) {
            $connection = $this->connection($name);
            // Порожнє значення перевіряємо як сире поле БД, без encrypted-cast.
            $empty = [];
            if (! $hasToken) $empty['refresh_token'] = '';
            if (! $hasCertificate) $empty['public_certificate'] = '';
            if ($empty) DB::table('novapay_connections')->where('id', $connection->id)->update($empty);
            $expected[$connection->id] = [
                'id' => $connection->id, 'verified_at' => $connection->verified_at->toIso8601String(), 'requires_auth' => true,
                'has_refresh_token' => $hasToken, 'has_public_certificate' => $hasCertificate,
            ];
        }
        $opaque = $this->connection('opaque');
        // Прапорець описує лише наявність збереженого поля; GET не повинен навіть розшифровувати його.
        DB::table('novapay_connections')->where('id', $opaque->id)->update([
            'refresh_token' => 'OPAQUE-REFRESH-NOT-DECRYPTABLE', 'public_certificate' => 'OPAQUE-CERTIFICATE-NOT-DECRYPTABLE',
            'requires_auth' => false, 'verified_at' => null,
        ]);
        $expected[$opaque->id] = [
            'id' => $opaque->id, 'verified_at' => null, 'requires_auth' => false,
            'has_refresh_token' => true, 'has_public_certificate' => true,
        ];
        $before = DB::table('novapay_connections')->orderBy('id')->get()->toArray();

        $response = $this->getJson('/finance/novapay/data')->assertOk();
        $this->assertSame($expected, collect($response->json('connections'))->keyBy('id')->all());
        $response->assertDontSee('PRIVATE-')->assertDontSee('OPAQUE-');
        foreach ($before as $stored) {
            foreach (['login', 'refresh_token', 'public_certificate', 'access_token'] as $field) {
                if ($stored->$field !== '') $response->assertDontSee($stored->$field);
            }
        }
        $this->assertEquals($before, DB::table('novapay_connections')->orderBy('id')->get()->toArray());
        $this->assertDatabaseCount('novapay_sync_runs', 0);
        $this->assertDatabaseCount('jobs', 0);
        Http::assertNothingSent();
        Queue::assertNothingPushed();
    }

    public static function incompletePairs(): array
    {
        return [
            'порожня форма' => [[], ['refresh_token', 'public_certificate']],
            'лише новий токен' => [['refresh_token' => 'NEW-REFRESH'], ['public_certificate']],
            'лише новий сертифікат' => [['public_certificate' => 'NEW-CERTIFICATE'], ['refresh_token']],
            'порожні поля' => [['refresh_token' => '', 'public_certificate' => ''], ['refresh_token', 'public_certificate']],
        ];
    }

    #[DataProvider('incompletePairs')]
    public function test_empty_or_partial_save_does_not_overwrite_existing_pair(array $payload, array $errors): void
    {
        $connection = $this->connection();
        $before = DB::table('novapay_connections')->where('id', $connection->id)->first();

        $this->postJson('/finance/novapay/connections', ['connection_id' => $connection->id, ...$payload])
            ->assertUnprocessable()->assertJsonValidationErrors($errors);

        $this->assertEquals($before, DB::table('novapay_connections')->where('id', $connection->id)->first());
        $this->assertDatabaseCount('novapay_connections', 1);
        $this->assertDatabaseCount('novapay_sync_runs', 0);
        $this->assertDatabaseCount('jobs', 0);
        Http::assertNothingSent();
        Queue::assertNothingPushed();
    }
}
