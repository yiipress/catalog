<?php

declare(strict_types=1);

/**
 * One extension in a list: name, badges, description, current release, categories.
 *
 * @var array<string, mixed> $extension A record of `$data['catalog']['extensions']`.
 * @var Closure(string, int, ?string, bool): string $h
 * @var Closure(string): string $url
 */
$release = $extension['release'] ?? null;
?>
<li class="extension-card" data-type="<?= $h((string) $extension['type']) ?>">
    <h3>
        <a href="<?= $h($url((string) $extension['url'])) ?>"><?= $h((string) $extension['name']) ?></a>
<?php if ($extension['official'] ?? false): ?>
        <span class="badge badge-official" title="Maintained by the YiiPress project">official</span>
<?php endif; ?>
<?php if (($extension['status'] ?? '') === 'abandoned'): ?>
        <span class="badge badge-abandoned">abandoned</span>
<?php endif; ?>
    </h3>
    <p class="extension-id"><code><?= $h((string) $extension['id']) ?></code><?php if (is_array($release)): ?> · <?= $h((string) $release['tag']) ?><?php endif; ?></p>
    <p><?= $h((string) $extension['description']) ?></p>
<?php if (($extension['categories'] ?? []) !== []): ?>
    <ul class="categories">
<?php foreach ($extension['categories'] as $category): ?>
        <li><a href="<?= $h($url('categories/' . $category . '/')) ?>"><?= $h((string) $category) ?></a></li>
<?php endforeach; ?>
    </ul>
<?php endif; ?>
</li>
