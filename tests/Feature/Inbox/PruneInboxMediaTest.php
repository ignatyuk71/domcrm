<?php

namespace Tests\Feature\Inbox;

use Tests\TestCase;

class PruneInboxMediaTest extends TestCase
{
    private array $paths = [];

    protected function tearDown(): void
    {
        foreach ($this->paths as $p) {
            @unlink($p);
        }
        parent::tearDown();
    }

    private function makeFile(string $dir, string $name, int $ageDays): string
    {
        $path = public_path($dir);
        if (!is_dir($path)) {
            @mkdir($path, 0775, true);
        }
        $full = $path . '/' . $name;
        file_put_contents($full, 'x');
        touch($full, now()->subDays($ageDays)->getTimestamp());
        $this->paths[] = $full;

        return $full;
    }

    public function test_old_files_are_deleted_and_fresh_kept(): void
    {
        $old = $this->makeFile('inbox-media', 'prune_old.jpg', 200);
        $oldCtx = $this->makeFile('inbox-context', 'prune_old_ctx.jpg', 200);
        $fresh = $this->makeFile('inbox-media', 'prune_fresh.jpg', 5);

        $this->artisan('inbox:prune-media')->assertExitCode(0);

        $this->assertFileDoesNotExist($old);
        $this->assertFileDoesNotExist($oldCtx);
        $this->assertFileExists($fresh);
    }

    public function test_days_guard_never_goes_below_30(): void
    {
        $file = $this->makeFile('inbox-media', 'prune_guard.jpg', 10);

        $this->artisan('inbox:prune-media --days=0')->assertExitCode(0);

        $this->assertFileExists($file, '--days=0 не сміє зносити свіжі файли');
    }

    public function test_gitignore_is_never_deleted(): void
    {
        $gi = public_path('inbox-media/.gitignore');
        $existed = file_exists($gi);
        if (!$existed) {
            file_put_contents($gi, "*\n!.gitignore\n");
        }
        touch($gi, now()->subDays(400)->getTimestamp());

        $this->artisan('inbox:prune-media')->assertExitCode(0);

        $this->assertFileExists($gi);
    }
}
