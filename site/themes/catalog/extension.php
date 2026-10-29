<?php

declare(strict_types=1);

/**
 * One extension: what its newest valid release says, how to install it, and which release fits which YiiPress.
 * The page reads its record from `$data['catalog']['extensions']` by `extra.id`.
 *
 * @var array<string, mixed> $data
 * @var array<string, mixed> $extra
 * @var string $entryTitle
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
$extension = $catalog['extensions'][$extra['id'] ?? ''] ?? null;
if (!is_array($extension)) {
    throw new RuntimeException(sprintf('No catalog data for "%s".', (string) ($extra['id'] ?? '')));
}
$engineVersions = $catalog['engine_versions'] ?? [];
$type = $catalog['types'][$extension['type']] ?? ['title' => $extension['type']];
$release = $extension['release'];
$theme = $extension['theme'] ?? null;
$processor = $extension['processor'] ?? null;
ob_start();
?>
<article class="extension">
    <p class="breadcrumbs"><a href="<?= $h($url($type['path'] . '/')) ?>"><?= $h((string) $type['title']) ?></a></p>
    <h1>
        <?= $h($entryTitle) ?>
<?php if ($extension['official']): ?>
        <span class="badge badge-official">Maintained by the YiiPress project</span>
<?php endif; ?>
    </h1>
    <p class="extension-id"><code><?= $h((string) $extension['id']) ?></code></p>
<?php if ($extension['status'] === 'abandoned'): ?>
    <p class="notice notice-abandoned">This extension is abandoned: its author no longer maintains it. The page shows its last release.</p>
<?php endif; ?>
<?php if ($extension['stale']): ?>
    <p class="notice notice-stale">GitHub could not be read for this extension at the last build. Release data as of <?= $h(substr((string) $extension['fetched_at'], 0, 10)) ?>.</p>
<?php endif; ?>
    <p class="description"><?= $h((string) $extension['description']) ?></p>

    <dl class="facts">
<?php if (is_array($release)): ?>
        <dt>Release</dt>
        <dd><a href="<?= $h((string) $release['url']) ?>"><?= $h((string) $release['tag']) ?></a>, <?= $h(substr((string) $release['published_at'], 0, 10)) ?></dd>
        <dt>YiiPress</dt>
        <dd><code><?= $h((string) $release['yiipress']) ?></code></dd>
<?php endif; ?>
        <dt>License</dt>
        <dd><?= $h((string) $extension['license']) ?></dd>
<?php if ($extension['authors'] !== []): ?>
        <dt>Authors</dt>
        <dd>
<?php foreach ($extension['authors'] as $index => $author): ?>
            <?= $index > 0 ? ', ' : '' ?><?php if (($author['url'] ?? null) !== null): ?><a href="<?= $h((string) $author['url']) ?>"><?= $h((string) $author['name']) ?></a><?php else: ?><?= $h((string) $author['name']) ?><?php endif; ?>
<?php endforeach; ?>
        </dd>
<?php endif; ?>
        <dt>Repository</dt>
        <dd><a href="<?= $h((string) $extension['repository']) ?>"><?= $h((string) $extension['repository']) ?></a></dd>
<?php if (($extension['homepage'] ?? null) !== null): ?>
        <dt>Homepage</dt>
        <dd><a href="<?= $h((string) $extension['homepage']) ?>"><?= $h((string) $extension['homepage']) ?></a></dd>
<?php endif; ?>
<?php if (is_array($theme)): ?>
        <dt>Falls back to</dt>
        <dd><code><?= $h((string) $theme['fallback']) ?></code></dd>
<?php if (($theme['demo'] ?? null) !== null): ?>
        <dt>Demo</dt>
        <dd><a href="<?= $h((string) $theme['demo']) ?>"><?= $h((string) $theme['demo']) ?></a></dd>
<?php endif; ?>
<?php endif; ?>
<?php if ($extension['categories'] !== []): ?>
        <dt>Categories</dt>
        <dd>
            <ul class="categories">
<?php foreach ($extension['categories'] as $category): ?>
                <li><a href="<?= $h($url('categories/' . $category . '/')) ?>"><?= $h((string) $category) ?></a></li>
<?php endforeach; ?>
            </ul>
        </dd>
<?php endif; ?>
    </dl>

    <h2>Install</h2>
<?php if ($extension['install'] !== null): ?>
    <pre class="install"><code><?= $h((string) $extension['install']) ?></code></pre>
    <ul class="latest-compatible">
<?php foreach ($extension['latest_compatible'] as $engine => $tag): ?>
<?php if ($tag !== null): ?>
        <li>For YiiPress <?= $h((string) $engine) ?>: <?= $h((string) $tag) ?><?= $tag !== ($release['tag'] ?? null) ? ' (add <code>--version=' . $h(ltrim((string) $tag, 'v')) . '</code>)' : '' ?></li>
<?php else: ?>
        <li>For YiiPress <?= $h((string) $engine) ?>: no compatible release</li>
<?php endif; ?>
<?php endforeach; ?>
    </ul>
<?php else: ?>
    <p class="notice">No installer yet: YiiPress cannot install <?= $h(strtolower((string) $type['title'])) ?>. Follow the extension's own instructions.</p>
<?php endif; ?>
<?php if (is_array($theme) && $theme['screenshots'] !== []): ?>

    <h2>Screenshots</h2>
    <div class="screenshots">
<?php foreach ($theme['screenshots'] as $screenshot): ?>
        <a href="<?= $h($url((string) $screenshot)) ?>"><img src="<?= $h($url((string) $screenshot)) ?>" alt="<?= $h($entryTitle) ?> screenshot" loading="lazy"></a>
<?php endforeach; ?>
    </div>
<?php endif; ?>
<?php if (is_array($processor)): ?>

    <h2>Runs at</h2>
    <ul class="entry-points">
<?php foreach ($processor['entry_points'] as $entryPoint): ?>
        <li><code><?= $h((string) $entryPoint['path']) ?></code>: <?= $h(implode(', ', $entryPoint['stages'])) ?></li>
<?php endforeach; ?>
    </ul>
<?php foreach ($processor['examples'] as $index => $example): ?>
    <h3>Example <?= $index + 1 ?></h3>
    <div class="example">
        <div><p>Input</p><pre><code><?= $h((string) $example['input']) ?></code></pre></div>
        <div><p>Output</p><pre><code><?= $h((string) $example['output']) ?></code></pre></div>
    </div>
<?php endforeach; ?>
<?php endif; ?>

    <h2>Releases</h2>
    <div class="table-scroll">
    <table class="releases">
        <thead>
            <tr>
                <th>Release</th>
                <th>Date</th>
                <th>YiiPress</th>
<?php foreach ($engineVersions as $engine): ?>
                <th><?= $h((string) $engine) ?></th>
<?php endforeach; ?>
            </tr>
        </thead>
        <tbody>
<?php foreach ($extension['releases'] as $row): ?>
            <tr>
                <td><a href="<?= $h((string) $row['url']) ?>"><?= $h((string) $row['tag']) ?></a></td>
                <td><?= $h(substr((string) $row['published_at'], 0, 10)) ?></td>
<?php if ($row['installable'] && $row['problems'] === []): ?>
                <td><code><?= $h((string) $row['yiipress']) ?></code></td>
<?php foreach ($engineVersions as $engine): ?>
<?php $compatible = $row['compatible'][$engine] ?? false; ?>
                <td class="<?= $compatible ? 'yes' : 'no' ?>"><?= $compatible ? '✓' : '—' ?></td>
<?php endforeach; ?>
<?php elseif ($row['unread'] ?? false): ?>
                <td colspan="<?= count($engineVersions) + 1 ?>" class="problems">Could not be read from GitHub at the last build: <?= $h(implode('; ', $row['problems'])) ?></td>
<?php else: ?>
                <td colspan="<?= count($engineVersions) + 1 ?>" class="problems">Not installable<?= $row['problems'] !== [] ? ': ' . $h(implode('; ', $row['problems'])) : ': no extension.yaml' ?></td>
<?php endif; ?>
            </tr>
<?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <p class="table-note">✓ the release installs on that YiiPress version; — it does not.</p>
<?php if ($extension['history'] !== []): ?>

    <h2>History</h2>
    <ul class="history">
<?php foreach ($extension['history'] as $transfer): ?>
        <li><?= $h((string) $transfer['date']) ?>: moved from <code><?= $h((string) $transfer['from']) ?></code> (<a href="<?= $h((string) $transfer['record']) ?>">record</a>). Sites installed from the old repository keep it as their source.</li>
<?php endforeach; ?>
    </ul>
<?php endif; ?>
</article>
<?php
echo $partial('page', ['title' => $entryTitle . ' — ' . $siteTitle, 'body' => (string) ob_get_clean()] + $frame);
