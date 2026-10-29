<?php

declare(strict_types=1);

/**
 * One extension type: its listed extensions, or the text shown while there are none.
 *
 * @var array<string, mixed> $data
 * @var string $collectionName
 * @var string $collectionTitle
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
$catalog = $data['catalog'] ?? [];
$typeName = '';
$type = ['title' => $collectionTitle, 'extensions' => [], 'installable' => false];
foreach ($catalog['types'] ?? [] as $name => $candidate) {
    if (($candidate['path'] ?? '') === $collectionName) {
        [$typeName, $type] = [(string) $name, $candidate];
    }
}
ob_start();
?>
<h1><?= $h($collectionTitle) ?></h1>
<?= $partial('type_section', ['typeName' => $typeName, 'type' => $type, 'extensions' => $catalog['extensions'] ?? []]) ?>
<?php
echo $partial('page', ['title' => $collectionTitle . ' — ' . $siteTitle, 'body' => (string) ob_get_clean()] + $frame);
