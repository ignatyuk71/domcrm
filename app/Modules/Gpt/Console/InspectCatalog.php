<?php

namespace App\Modules\Gpt\Console;

use App\Modules\Gpt\Domain\DraftFailure;
use App\Modules\Gpt\Infrastructure\CatalogReader;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;

class InspectCatalog extends Command
{
    protected $signature = 'gpt:inspect-catalog {--group= : ID групи для перевірки зв’язків}
        {--query= : Назва, колір, артикул або розмір товару} {--product= : ID товару для перевірки ціни та залишків}';

    protected $description = 'Перевірити читання наявної галереї для GPT без записів у БД та викликів API';

    public function handle(CatalogReader $catalog): int
    {
        try {
            if ($this->option('query') !== null || $this->option('product') !== null) {
                $offers = $this->option('product') !== null
                    ? [$catalog->offer((int) $this->option('product'))]
                    : $catalog->searchProducts((string) $this->option('query'))['products'];
                $this->table(['ID', 'Товар', 'Колір', 'Ціна', 'Залишок', 'Розміри'], array_map(fn ($p) => [
                    $p['id'], $p['title'], $p['color'], $p['sale_price'] !== null ? $p['sale_price'].' '.$p['currency'] : 'Не задано',
                    $p['stock_qty'], implode(', ', array_map(fn ($v) => $v['size'].': '.$v['stock_qty'], $p['variants'])),
                ], $offers));
            } elseif (! $this->option('group')) {
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
