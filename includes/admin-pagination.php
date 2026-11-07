<?php
declare(strict_types=1);

const ADMIN_PAGE_SIZE = 20;

/** Jedne granice stron dla zapytań SQL; wywołuj po COUNT(*) z tymi samymi filtrami. */
function admin_pagination(int $total, int $requestedPage): array
{
    $pages = max(1, (int) ceil($total / ADMIN_PAGE_SIZE));
    $page = min(max(1, $requestedPage), $pages);
    return ['total' => $total, 'page' => $page, 'pages' => $pages,
        'limit' => ADMIN_PAGE_SIZE, 'offset' => ($page - 1) * ADMIN_PAGE_SIZE];
}

/** Ścieżka pochodzi z kodu widoku, a query zawiera wyłącznie zwalidowane filtry. */
function render_admin_pagination(array $listing, string $path, string $label, array $query = []): void
{
    if ($listing['pages'] <= 1) { return; }
    $query = array_filter($query, static fn($value): bool => $value !== '');
    $url = static fn(int $page): string => $path . '?' . http_build_query(
        array_replace($query, ['page' => $page]), '', '&', PHP_QUERY_RFC3986
    );
    ?>
    <nav class="category-pagination" aria-label="<?= e($label) ?>">
        <p class="pagination-summary">Strona <?= e((string) $listing['page']) ?> z <?= e((string) $listing['pages']) ?>
            <small>Wyniki <?= e((string) ($listing['offset'] + 1)) ?>–<?= e((string) ($listing['offset'] + count($listing['rows']))) ?> z <?= e((string) $listing['total']) ?></small>
        </p>
        <div class="pagination-links">
            <?php if ($listing['page'] > 2): ?><a href="<?= e($url(1)) ?>">Pierwsza</a><?php endif; ?>
            <?php if ($listing['page'] > 1): ?><a rel="prev" href="<?= e($url($listing['page'] - 1)) ?>">← Poprzednia</a><?php endif; ?>
            <?php if ($listing['page'] < $listing['pages']): ?><a rel="next" href="<?= e($url($listing['page'] + 1)) ?>">Następna →</a><?php endif; ?>
            <?php if ($listing['page'] < $listing['pages'] - 1): ?><a href="<?= e($url($listing['pages'])) ?>">Ostatnia</a><?php endif; ?>
        </div>
    </nav>
    <?php
}
