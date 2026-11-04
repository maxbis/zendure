<?php

declare(strict_types=1);

require_once __DIR__ . '/../login/validate.php';
require_once __DIR__ . '/includes/project_markdown.php';

$docsRoot = realpath(__DIR__ . '/../docs');
$requestedDoc = isset($_GET['doc']) ? rawurldecode((string)$_GET['doc']) : null;
$requestedDoc = $requestedDoc !== null ? ltrim(str_replace('\\', '/', $requestedDoc), '/') : null;
$document = null;
$error = null;
$documents = [];

if ($docsRoot === false) {
    $error = 'The documentation directory could not be found.';
} else {
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($docsRoot, FilesystemIterator::SKIP_DOTS)
    );

    foreach ($iterator as $file) {
        if (!$file->isFile() || strtolower($file->getExtension()) !== 'md') {
            continue;
        }

        $absolutePath = $file->getRealPath();
        if ($absolutePath === false) {
            continue;
        }

        $relativePath = str_replace(DIRECTORY_SEPARATOR, '/', substr($absolutePath, strlen($docsRoot) + 1));
        $markdown = file_get_contents($absolutePath);
        $title = pathinfo($file->getFilename(), PATHINFO_FILENAME);
        if ($markdown !== false && preg_match('/^#\s+(.+)$/m', $markdown, $match) === 1) {
            $title = trim(preg_replace('/[`*_]/', '', $match[1]) ?? $match[1]);
        }

        $pathParts = explode('/', $relativePath);
        $section = count($pathParts) > 1 ? $pathParts[0] : 'General';
        $documents[] = [
            'path' => $relativePath,
            'title' => $title,
            'section' => $section,
            'location' => 'docs/' . $relativePath,
        ];
    }

    usort($documents, static function (array $left, array $right): int {
        return [$left['section'], $left['title']] <=> [$right['section'], $right['title']];
    });

    if ($requestedDoc !== null) {
        if (preg_match('#^[a-zA-Z0-9/_-]+\.md$#', $requestedDoc) !== 1) {
            $error = 'The requested documentation path is invalid.';
        } else {
            $candidate = realpath($docsRoot . DIRECTORY_SEPARATOR . $requestedDoc);
            $docsPrefix = rtrim($docsRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
            if ($candidate === false || !str_starts_with($candidate, $docsPrefix) || !is_file($candidate)) {
                $error = 'The requested documentation file was not found.';
            } else {
                $markdown = file_get_contents($candidate);
                if ($markdown === false) {
                    $error = 'The documentation file could not be read.';
                } else {
                    $document = renderProjectMarkdown($markdown, $requestedDoc, $docsRoot);
                }
            }
        }
    }
}

$isIndex = $requestedDoc === null;
$pageTitle = $document['title'] ?? ($isIndex ? 'Documentation' : 'Documentation unavailable');
$groupedDocuments = [];
foreach ($documents as $indexedDocument) {
    $groupedDocuments[$indexedDocument['section']][] = $indexedDocument;
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#0d0f12">
    <title><?= htmlspecialchars($pageTitle, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?> · Zendure</title>
    <link rel="icon" type="image/png" sizes="32x32" href="assets/icons/app-icon-32.png">
    <link rel="stylesheet" href="../themes/graphite-signal-dark/assets/css/theme.css">
    <link rel="stylesheet" href="../themes/graphite-signal-dark/assets/css/components.css">
    <link rel="stylesheet" href="assets/css/app.css">
    <link rel="stylesheet" href="assets/css/docs.css?v=<?= (int)filemtime(__DIR__ . '/assets/css/docs.css'); ?>">
    <?php if ($isIndex): ?><script src="assets/js/docs.js?v=<?= (int)filemtime(__DIR__ . '/assets/js/docs.js'); ?>" defer></script><?php endif; ?>
</head>
<body data-theme="graphite-signal-dark">
    <main class="app-shell docs-shell">
        <header class="app-topbar docs-topbar">
            <div class="app-brand">
                <span class="app-brand__mark" aria-hidden="true"><img src="assets/icons/app-icon-180.png" alt=""></span>
                <div class="app-brand__copy">
                    <h1 class="app-brand__title"><?= $isIndex ? 'Project documentation' : htmlspecialchars($pageTitle, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></h1>
                    <p class="app-brand__meta"><?= $isIndex ? count($documents) . ' Markdown documents in one searchable library' : 'Rendered from docs/' . htmlspecialchars((string)$requestedDoc, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></p>
                </div>
            </div>
            <nav class="app-topbar__actions" aria-label="Documentation navigation">
                <?php if (!$isIndex): ?><a class="gsd-btn gsd-btn--secondary" href="docs.php">All docs</a><?php endif; ?>
                <a class="gsd-btn gsd-btn--secondary" href="optimizer_scarcity.php">Scarcity metrics</a>
                <a class="gsd-btn gsd-btn--secondary" href="./">Live app</a>
            </nav>
        </header>

        <?php if ($error !== null): ?>
            <section class="gsd-card docs-error" role="alert">
                <strong>Documentation unavailable</strong>
                <p><?= htmlspecialchars($error, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></p>
                <a class="gsd-btn gsd-btn--secondary" href="docs.php">Open documentation index</a>
            </section>
        <?php elseif ($isIndex): ?>
            <section class="gsd-card docs-intro">
                <div>
                    <span class="docs-eyebrow">Documentation library</span>
                    <h2>Browse all project documentation</h2>
                    <p>Every Markdown file below is rendered through the same authenticated viewer. Search by title, folder, or source path.</p>
                </div>
                <label class="docs-search">
                    <span>Search documentation</span>
                    <input type="search" placeholder="Search all docs…" autocomplete="off" data-role="docs-search">
                </label>
            </section>

            <p class="docs-results" data-role="docs-results" aria-live="polite"><?= count($documents); ?> documents</p>
            <div class="docs-index" data-role="docs-index">
                <?php foreach ($groupedDocuments as $section => $sectionDocuments): ?>
                    <section class="docs-group" data-doc-group>
                        <header><h2><?= htmlspecialchars(ucwords(str_replace(['_', '-'], ' ', $section)), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></h2><span><?= count($sectionDocuments); ?></span></header>
                        <div class="docs-grid">
                            <?php foreach ($sectionDocuments as $indexedDocument): ?>
                                <a class="gsd-card docs-card" href="docs.php?doc=<?= rawurlencode($indexedDocument['path']); ?>" data-doc-card data-search-text="<?= htmlspecialchars(strtolower($indexedDocument['title'] . ' ' . $indexedDocument['section'] . ' ' . $indexedDocument['location']), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>">
                                    <strong><?= htmlspecialchars($indexedDocument['title'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></strong>
                                    <code><?= htmlspecialchars($indexedDocument['location'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></code>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    </section>
                <?php endforeach; ?>
            </div>
            <section class="gsd-card docs-empty" data-role="docs-empty" hidden>
                <strong>No documentation found</strong>
                <p>Try a different title, folder, or path.</p>
            </section>
        <?php else: ?>
            <div class="docs-layout">
                <aside class="gsd-card docs-toc" aria-labelledby="contents-title">
                    <span class="docs-eyebrow">On this page</span>
                    <h2 id="contents-title">Contents</h2>
                    <nav aria-label="Table of contents">
                        <?php foreach ($document['headings'] as $heading): ?>
                            <?php if ($heading['level'] <= 3): ?>
                                <a data-level="<?= $heading['level']; ?>" href="#<?= htmlspecialchars($heading['id'], ENT_QUOTES, 'UTF-8'); ?>"><?= htmlspecialchars($heading['label'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></a>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </nav>
                </aside>

                <article class="gsd-card docs-article">
                    <div class="docs-source"><span>Canonical documentation</span><code>docs/<?= htmlspecialchars((string)$requestedDoc, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></code></div>
                    <?= $document['html']; ?>
                </article>
            </div>
        <?php endif; ?>
    </main>
</body>
</html>
