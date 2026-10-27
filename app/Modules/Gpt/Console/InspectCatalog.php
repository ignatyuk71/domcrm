<?php

namespace App\Modules\Gpt\Console;

use App\Modules\Gpt\Domain\DraftFailure;
use App\Modules\Gpt\Infrastructure\CatalogReader;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;

class InspectCatalog extends Command
{
    protected $signature = 'gpt:inspect-catalog {--group= : ID групи для перевірки зв’язків}';

    protected $description = 'Перевірити читання наявної галереї для GPT без записів у БД та викликів API';

    public function handle(CatalogReader $catalog): int
    {
        try {
            if (! $this->option('group')) {
                $this->table(['ID групи', 'Модель'], array_map(fn ($g) => [$g['id'], $g['name']], $catalog->groups()));
            } else {
                $group = $catalog->describe((int) $this->option('group'));
                $this->line($group['name']);
                $this->table(['ID товару', 'Назва', 'Колір', 'SKU'], array_map(fn ($p) => [$p['id'], $p['title'], $p['color'], $p['sku']], $group['products']));
                $this->line('Розмічених фото: '.count($group['photos']));
            }
        } catch (DraftFailure $error) {
            $this->error($error->getMessage());

            return self::FAILURE;
        } catch (QueryException) {
            $this->error('Немає доступу до бази каталогу. Перевірте підключення до MySQL; дані не змінювалися.');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
