<?php

declare(strict_types=1);

/**
 * The extensions of one type, or the text shown while there are none.
 *
 * @var string $typeName Type value: theme, processor, importer, plugin.
 * @var array<string, mixed> $type A record of `$data['catalog']['types']`.
 * @var array<string, array<string, mixed>> $extensions `$data['catalog']['extensions']`.
 * @var Closure(string, array): string $partial
 * @var Closure(string, int, ?string, bool): string $h
 * @var Closure(string): string $url
 */
$ids = $type['extensions'] ?? [];
?>
<?php if ($ids === []): ?>
<?php if ($type['installable'] ?? false): ?>
<p class="empty">No <?= $h(strtolower((string) $type['title'])) ?> are listed yet. <a href="<?= $h($url('submit/')) ?>">Submit yours</a>.</p>
<?php else: ?>
<p class="empty">YiiPress cannot install <?= $h(strtolower((string) $type['title'])) ?> yet. The catalog will list them once it can.</p>
<?php endif; ?>
<?php else: ?>
<ul class="extension-list">
<?php foreach ($ids as $id): ?>
<?php if (isset($extensions[$id])): ?>
<?= $partial('card', ['extension' => $extensions[$id]]) ?>
<?php endif; ?>
<?php endforeach; ?>
</ul>
<?php endif; ?>
