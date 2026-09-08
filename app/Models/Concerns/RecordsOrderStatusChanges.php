<?php

namespace App\Models\Concerns;

use App\Services\Orders\OrderStatusAudit;
use Illuminate\Database\Eloquent\Builder;

trait RecordsOrderStatusChanges
{
    private ?array $statusAuditContext = null;

    protected function performInsert(Builder $query)
    {
        // Початок першого статусу фіксується і для створення через saveQuietly().
        $this->status_changed_at = $this->freshTimestamp();

        return parent::performInsert($query);
    }

    /** Одноразовий контекст: не переходить до наступного збереження моделі. */
    public function updateWithStatusAudit(array $attributes, string $source, string $reason, array $metadata = []): bool
    {
        $previous = $this->statusAuditContext;
        $this->statusAuditContext = compact('source', 'reason', 'metadata');

        try {
            return $this->update($attributes);
        } finally {
            $this->statusAuditContext = $previous;
        }
    }

    protected function performUpdate(Builder $query)
    {
        // Статуси задаються до save(), а не всередині updating/saved observers.
        if (! $this->isDirty(['status', 'status_id'])) {
            return parent::performUpdate($query);
        }

        return $this->getConnection()->transaction(function () use ($query) {
            // Модель могла застаріти під час запиту до НП: читаємо фактичний стан під блокуванням.
            $before = $this->newModelQuery()->whereKey($this->getKey())->lockForUpdate()
                ->firstOrFail(['status', 'status_id']);
            $saved = parent::performUpdate($query);

            if ($saved) {
                $after = $this->newModelQuery()->whereKey($this->getKey())->lockForUpdate()
                    ->firstOrFail(['order_number', 'status', 'status_id', 'status_changed_at']);
                app(OrderStatusAudit::class)->record($this, $before, $after, $this->statusAuditContext);
            }

            return $saved;
        });
    }
}
