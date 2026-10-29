<?php

declare(strict_types=1);

/**
 * The home page: every listed extension, one section per type, with a type filter.
 *
 * @var array<string, mixed> $data
 * @var string $entryTitle
 * @var string $content
 * @var Closure(string, array): string $partial
 * @var Closure(string, int, ?string, bool): string $h
 * @var Closure(string): string $url
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
$types = $catalog['types'] ?? [];
$extensions = $catalog['extensions'] ?? [];
ob_start();
?>
<h1><?= $h($entryTitle) ?></h1>
<div class="content"><?= $content ?></div>
<nav class="type-filter" aria-label="Extension types">
    <a href="#all" data-filter="" aria-current="true">All <span class="count"><?= count($extensions) ?></span></a>
<?php foreach ($types as $name => $type): ?>
    <a href="<?= $h($url($type['path'] . '/')) ?>" data-filter="<?= $h((string) $name) ?>"><?= $h((string) $type['title']) ?> <span class="count"><?= count($type['extensions'] ?? []) ?></span></a>
<?php endforeach; ?>
</nav>
<?php foreach ($types as $name => $type): ?>
<section class="type-section" data-type="<?= $h((string) $name) ?>">
    <h2><a href="<?= $h($url($type['path'] . '/')) ?>"><?= $h((string) $type['title']) ?></a></h2>
<?= $partial('type_section', ['typeName' => (string) $name, 'type' => $type, 'extensions' => $extensions]) ?>
</section>
<?php endforeach; ?>
<?php if (isset($catalog['generated_at'])): ?>
<p class="generated">Release data as of <time datetime="<?= $h((string) $catalog['generated_at']) ?>"><?= $h(substr((string) $catalog['generated_at'], 0, 10)) ?></time>.</p>
<?php endif; ?>
<script>
(function () {
    var links = document.querySelectorAll('.type-filter a');
    links.forEach(function (link) {
        link.addEventListener('click', function (event) {
            var type = link.getAttribute('data-filter');
            event.preventDefault();
            links.forEach(function (other) { other.removeAttribute('aria-current'); });
            link.setAttribute('aria-current', 'true');
            document.querySelectorAll('.type-section').forEach(function (section) {
                section.hidden = type !== '' && section.getAttribute('data-type') !== type;
            });
        });
    });
})();
</script>
<?php
echo $partial('page', ['title' => $siteTitle, 'body' => (string) ob_get_clean()] + $frame);
