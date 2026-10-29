<?php

declare(strict_types=1);

/**
 * Tombstones: withdrawn IDs with the date and the reason. The IDs are never reused.
 *
 * @var array<string, mixed> $data
 * @var string $entryTitle
 * @var string $content
 * @var Closure(string, array): string $partial
 * @var Closure(string, int, ?string, bool): string $h
 */
$frame = [
    'siteTitle' => $siteTitle,
    'language' => $language ?? 'en',
    'nav' => $nav,
    'rootPath' => $rootPath,
    'metaTags' => $metaTags ?? null,
    'headAssets' => $headAssets ?? '',
    'ui' => $ui,
    'uiLanguage' => $uiLanguage ?? 'en',
    'uiLanguages' => $uiLanguages ?? ['en'],
    'uiCatalogs' => $uiCatalogs ?? ['en' => []],
    'icons' => $icons ?? [],
];
$withdrawn = $data['catalog']['withdrawn'] ?? [];
ob_start();
?>
<h1><?= $h($entryTitle) ?></h1>
<div class="content"><?= $content ?></div>
<?php if ($withdrawn === []): ?>
<p class="empty">No extension has been withdrawn.</p>
<?php else: ?>
<table class="withdrawn">
    <thead><tr><th>ID</th><th>Withdrawn</th><th>Reason</th></tr></thead>
    <tbody>
<?php foreach ($withdrawn as $entry): ?>
        <tr><td><code><?= $h((string) $entry['id']) ?></code></td><td><?= $h((string) ($entry['date'] ?? '')) ?></td><td><?= $h((string) ($entry['reason'] ?? '')) ?></td></tr>
<?php endforeach; ?>
    </tbody>
</table>
<?php endif; ?>
<?php
echo $partial('page', ['title' => $entryTitle . ' — ' . $siteTitle, 'body' => (string) ob_get_clean()] + $frame);
