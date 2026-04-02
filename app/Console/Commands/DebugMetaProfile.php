<?php

namespace App\Console\Commands;

use App\Models\ChatContact;
use App\Services\ChatService;
use App\Services\MetaService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

class DebugMetaProfile extends Command
{
    protected $signature = 'debug:meta-profile {id : External User ID from Meta} {--platform=messenger}';
    protected $description = 'Debug Meta profile fetching and avatar caching for a specific user';

    public function handle(ChatService $chatService, MetaService $metaService)
    {
        $userId = $this->argument('id');
        $platform = $this->option('platform');

        $this->info("=== DEBUG START: {$platform} / {$userId} ===");

        // 1. Перевірка наявності контакту в БД
        $contact = ChatContact::where('external_user_id', $userId)
            ->where('platform', $platform)
            ->first();

        if (!$contact) {
            $this->error("❌ Contact not found in DB.");
            if (!$this->confirm('Create temporary contact for debugging?')) {
                return;
            }
            // Спроба знайти підключення
            $connection = \App\Models\MetaConnection::where('is_active', true)->first();
            if (!$connection) {
                $this->error("No active MetaConnection found.");
                return;
            }
            $contact = new ChatContact([
                'external_user_id' => $userId,
                'platform' => $platform,
                'meta_connection_id' => $connection->id
            ]);
            $contact->save();
            $this->info("Created temp contact ID: {$contact->id}");
        } else {
            $this->info("✅ Found Contact ID: {$contact->id}");
            $this->line("   Current Avatar Path: " . ($contact->avatar_path ?? 'NULL'));
            $this->line("   Current Original URL: " . ($contact->avatar_original_url ?? 'NULL'));
        }

        // 2. Перевірка Meta API
        $this->info("\n--- STEP 1: Fetching Profile from Meta API ---");
        
        // RAW REQUEST (Direct check)
        $connection = \App\Models\MetaConnection::where('is_active', true)->first();
        if ($connection) {
            $graphVer = config('services.meta.graph_version', 'v24.0');
            $fields = $platform === 'instagram'
                ? 'name,username,profile_pic'
                : 'first_name,last_name,name,profile_pic,picture.type(large)';
            $url = "https://graph.facebook.com/{$graphVer}/{$userId}";

            $this->line("Performing RAW HTTP GET to: {$url}");
            try {
                $rawResp = Http::withToken($connection->access_token)->get($url, ['fields' => $fields]);
                $this->line("HTTP Status: " . $rawResp->status());
                $this->line("Raw Body: " . $rawResp->body());
            } catch (\Exception $e) {
                $this->error("Raw request exception: " . $e->getMessage());
            }
            $this->line("------------------------------------------------");
        }

        try {
            $profile = $metaService->getContactProfile($userId, $platform);
            $this->line("MetaService Processed Profile: " . json_encode($profile, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            if (empty($profile['profile_pic'])) {
                $this->error("❌ 'profile_pic' is MISSING in Meta response!");
                $this->warn("Possible causes: App Permissions (pages_messaging), User Privacy Settings, or Expired Page Token.");
            } else {
                $this->info("✅ 'profile_pic' found: {$profile['profile_pic']}");
                
                // 3. Перевірка завантаження
                $this->info("\n--- STEP 2: Testing Image Download ---");
                $response = Http::timeout(5)->get($profile['profile_pic']);
                if ($response->successful()) {
                    $this->info("✅ Download HTTP 200 OK. Size: " . strlen($response->body()) . " bytes");
                    $this->line("   Content-Type: " . $response->header('Content-Type'));
                } else {
                    $this->error("❌ Download Failed: HTTP " . $response->status());
                }
            }
        } catch (\Throwable $e) {
            $this->error("❌ Meta API Exception: " . $e->getMessage());
        }

        // 4. Перевірка прав доступу до диска
        $this->info("\n--- STEP 3: Testing Storage Write Permissions ---");
        try {
            $testFile = 'debug_test_' . time() . '.txt';
            Storage::disk('chat_uploads')->put($testFile, 'test');
            if (Storage::disk('chat_uploads')->exists($testFile)) {
                $this->info("✅ Write success to disk 'chat_uploads'. Path: " . Storage::disk('chat_uploads')->path($testFile));
                Storage::disk('chat_uploads')->delete($testFile);
            } else {
                $this->error("❌ File was supposed to be written but not found.");
            }
        } catch (\Throwable $e) {
            $this->error("❌ Storage Exception: " . $e->getMessage());
            $this->warn("Check 'chat_uploads' disk config (should be public/chat) and folder permissions.");
        }

        // 5. Запуск реального сервісу
        $this->info("\n--- STEP 4: Running ChatService::syncContactProfile() ---");
        try {
            $updatedContact = $chatService->syncContactProfile($contact, $metaService);
            
            $this->line("Updated Contact Data:");
            $this->table(
                ['Field', 'Value'],
                [
                    ['display_name', $updatedContact->display_name],
                    ['avatar_path', $updatedContact->avatar_path],
                    ['avatar_original_url', \Illuminate\Support\Str::limit($updatedContact->avatar_original_url, 50)],
                ]
            );

            if ($updatedContact->avatar_path) {
                $this->info("✅ SUCCESS: Avatar saved to database.");
            } else {
                $this->error("❌ FAILURE: Avatar path is still empty.");
            }
        } catch (\Throwable $e) {
            $this->error("❌ Service Exception: " . $e->getMessage());
        }
    }
}
