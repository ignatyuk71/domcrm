<?php

// Скидання PHP OPcache після деплою (Laravel optimize:clear його НЕ чистить,
// тому FPM міг крутити стару версію коду). Викликається з деплой-воркфлоу.
// Простий токен — щоб ендпоінт не смикали сторонні; сам по собі безпечний.

if (($_GET['token'] ?? '') !== 'dom-oc-2026') {
    http_response_code(403);
    exit('forbidden');
}

if (function_exists('opcache_reset') && opcache_reset()) {
    echo 'opcache-reset-ok';
} else {
    echo 'opcache-not-available';
}
