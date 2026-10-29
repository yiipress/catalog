<?php

declare(strict_types=1);

/**
 * The page frame of the catalog theme: the head and header of `minimal`, the catalog stylesheet, the body, the footer.
 *
 * @var string $title
 * @var string $body Rendered HTML.
 * @var string $siteTitle
 * @var string $language
 * @var ?YiiPress\Content\Model\Navigation $nav
 * @var string $rootPath
 * @var YiiPress\Build\MetaTags|null $metaTags
 * @var string $headAssets
 * @var YiiPress\I18n\UiText $ui
 * @var string $uiLanguage
 * @var list<string> $uiLanguages
 * @var array<string, array<string, string>> $uiCatalogs
 * @var list<YiiPress\Content\Model\SiteIcon> $icons
 * @var Closure(string, array): string $partial
 * @var Closure(string, int, ?string, bool): string $h
 * @var Closure(string): string $themeAsset
 */
$headAssets = '<link rel="stylesheet" href="' . $h($themeAsset('catalog.css')) . '">' . ($headAssets ?? '');
?>
<!DOCTYPE html>
<html lang="<?= $h($language) ?>">
<head>
<?= $partial('head', ['title' => $title, 'rootPath' => $rootPath, 'headAssets' => $headAssets, 'metaTags' => $metaTags, 'search' => false, 'ui' => $ui, 'uiLanguage' => $uiLanguage, 'uiLanguages' => $uiLanguages, 'uiCatalogs' => $uiCatalogs, 'icons' => $icons]) ?>
</head>
<body class="catalog">
<?= $partial('header', ['siteTitle' => $siteTitle, 'nav' => $nav, 'rootPath' => $rootPath, 'search' => false, 'ui' => $ui, 'uiLanguage' => $uiLanguage, 'uiLanguages' => $uiLanguages]) ?>
<main>
    <div class="container">
<?= $body ?>
    </div>
</main>
<?= $partial('footer', ['nav' => $nav, 'rootPath' => $rootPath, 'ui' => $ui, 'uiLanguage' => $uiLanguage]) ?>
</body>
</html>
