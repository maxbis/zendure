<?php

declare(strict_types=1);

/**
 * Render a trusted project Markdown document using a deliberately small subset.
 *
 * Supported blocks: headings, paragraphs, ordered/unordered lists, and fenced
 * code. Raw HTML is escaped. Relative Markdown links stay inside the validated
 * documentation viewer; source-code references are displayed without exposing
 * executable project files through the web server.
 *
 * @return array{html: string, title: string, headings: list<array{id: string, level: int, label: string}>}
 */
function renderProjectMarkdown(string $markdown, string $documentPath, string $docsRoot): array
{
    $documentAbsolutePath = realpath($docsRoot . DIRECTORY_SEPARATOR . $documentPath);
    if ($documentAbsolutePath === false) {
        throw new RuntimeException('Documentation file could not be resolved.');
    }

    $tokens = [];
    $inline = static function (string $text) use (&$tokens, $documentAbsolutePath, $docsRoot): string {
        $storeToken = static function (string $html) use (&$tokens): string {
            $token = 'DOCXTOKEN' . count($tokens) . 'X';
            $tokens[$token] = $html;
            return $token;
        };

        $text = preg_replace_callback('/`([^`]+)`/', static function (array $match) use ($storeToken): string {
            return $storeToken('<code>' . htmlspecialchars($match[1], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</code>');
        }, $text) ?? $text;

        $text = preg_replace_callback('/\[([^\]]+)\]\(([^)]+)\)/', static function (array $match) use ($storeToken, $documentAbsolutePath, $docsRoot): string {
            $labelText = $match[1];
            if (preg_match('/^`([^`]+)`$/', $labelText, $codeLabelMatch) === 1) {
                $label = '<code>' . htmlspecialchars($codeLabelMatch[1], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</code>';
            } else {
                $label = htmlspecialchars($labelText, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            }
            $target = trim($match[2]);

            if (preg_match('#^https?://#i', $target) === 1) {
                $href = htmlspecialchars($target, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                return $storeToken('<a href="' . $href . '" rel="noreferrer">' . $label . '</a>');
            }

            if (str_starts_with($target, '#')) {
                $href = htmlspecialchars($target, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                return $storeToken('<a href="' . $href . '">' . $label . '</a>');
            }

            [$relativeTarget, $fragment] = array_pad(explode('#', $target, 2), 2, '');
            $resolvedTarget = realpath(dirname($documentAbsolutePath) . DIRECTORY_SEPARATOR . $relativeTarget);
            $docsPrefix = rtrim((string)realpath($docsRoot), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;

            if ($resolvedTarget !== false && str_starts_with($resolvedTarget, $docsPrefix) && str_ends_with(strtolower($resolvedTarget), '.md')) {
                $relativeDoc = str_replace(DIRECTORY_SEPARATOR, '/', substr($resolvedTarget, strlen($docsPrefix)));
                $href = 'docs.php?doc=' . rawurlencode($relativeDoc);
                if ($fragment !== '') {
                    $href .= '#' . rawurlencode($fragment);
                }
                return $storeToken('<a href="' . htmlspecialchars($href, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '">' . $label . '</a>');
            }

            $sourceTitle = htmlspecialchars('Project source: ' . $relativeTarget, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            return $storeToken('<span class="doc-source-ref" title="' . $sourceTitle . '">' . $label . '</span>');
        }, $text) ?? $text;

        $escaped = htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $escaped = preg_replace('/\*\*([^*]+)\*\*/', '<strong>$1</strong>', $escaped) ?? $escaped;

        return strtr($escaped, $tokens);
    };

    $slugCounts = [];
    $slugify = static function (string $label) use (&$slugCounts): string {
        $slug = strtolower(trim($label));
        $slug = preg_replace('/[^a-z0-9]+/', '-', $slug) ?? '';
        $slug = trim($slug, '-');
        $slug = $slug !== '' ? $slug : 'section';
        $count = $slugCounts[$slug] ?? 0;
        $slugCounts[$slug] = $count + 1;
        return $count === 0 ? $slug : $slug . '-' . ($count + 1);
    };

    $html = [];
    $headings = [];
    $paragraph = [];
    $listType = null;
    $inCode = false;
    $codeLanguage = '';
    $codeLines = [];
    $title = pathinfo($documentPath, PATHINFO_FILENAME);

    $flushParagraph = static function () use (&$paragraph, &$html, $inline): void {
        if ($paragraph === []) {
            return;
        }
        $html[] = '<p>' . $inline(implode(' ', array_map('trim', $paragraph))) . '</p>';
        $paragraph = [];
    };
    $closeList = static function () use (&$listType, &$html): void {
        if ($listType !== null) {
            $html[] = '</' . $listType . '>';
            $listType = null;
        }
    };

    $lines = preg_split('/\R/', $markdown) ?: [];
    $lineCount = count($lines);
    for ($lineIndex = 0; $lineIndex < $lineCount; $lineIndex++) {
        $line = $lines[$lineIndex];
        if (preg_match('/^```\s*([a-zA-Z0-9_-]*)\s*$/', $line, $match) === 1) {
            if (!$inCode) {
                $flushParagraph();
                $closeList();
                $inCode = true;
                $codeLanguage = $match[1];
                $codeLines = [];
            } else {
                $languageClass = $codeLanguage !== '' ? ' class="language-' . htmlspecialchars($codeLanguage, ENT_QUOTES, 'UTF-8') . '"' : '';
                $html[] = '<pre><code' . $languageClass . '>' . htmlspecialchars(implode("\n", $codeLines), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</code></pre>';
                $inCode = false;
                $codeLanguage = '';
                $codeLines = [];
            }
            continue;
        }

        if ($inCode) {
            $codeLines[] = $line;
            continue;
        }

        if (trim($line) === '') {
            $flushParagraph();
            $closeList();
            continue;
        }

        if (trim($line) === '---' || trim($line) === '***') {
            $flushParagraph();
            $closeList();
            $html[] = '<hr>';
            continue;
        }

        if (
            preg_match('/^\s*\|(.+)\|\s*$/', $line, $headerMatch) === 1
            && isset($lines[$lineIndex + 1])
            && preg_match('/^\s*\|(?:\s*:?-+:?\s*\|)+\s*$/', $lines[$lineIndex + 1]) === 1
        ) {
            $flushParagraph();
            $closeList();
            $headerCells = array_map('trim', explode('|', trim($headerMatch[1])));
            $html[] = '<div class="docs-table-wrap"><table><thead><tr>';
            foreach ($headerCells as $cell) {
                $html[] = '<th>' . $inline($cell) . '</th>';
            }
            $html[] = '</tr></thead><tbody>';
            $lineIndex += 2;
            while ($lineIndex < $lineCount && preg_match('/^\s*\|(.+)\|\s*$/', $lines[$lineIndex], $rowMatch) === 1) {
                $rowCells = array_map('trim', explode('|', trim($rowMatch[1])));
                $html[] = '<tr>';
                foreach ($headerCells as $cellIndex => $_headerCell) {
                    $html[] = '<td>' . $inline($rowCells[$cellIndex] ?? '') . '</td>';
                }
                $html[] = '</tr>';
                $lineIndex++;
            }
            $html[] = '</tbody></table></div>';
            $lineIndex--;
            continue;
        }

        if (preg_match('/^>\s?(.*)$/', $line, $quoteMatch) === 1) {
            $flushParagraph();
            $closeList();
            $quoteLines = [$quoteMatch[1]];
            while (isset($lines[$lineIndex + 1]) && preg_match('/^>\s?(.*)$/', $lines[$lineIndex + 1], $nextQuoteMatch) === 1) {
                $quoteLines[] = $nextQuoteMatch[1];
                $lineIndex++;
            }
            $html[] = '<blockquote>' . $inline(implode(' ', $quoteLines)) . '</blockquote>';
            continue;
        }

        if (preg_match('/^(#{1,4})\s+(.+)$/', $line, $match) === 1) {
            $flushParagraph();
            $closeList();
            $level = strlen($match[1]);
            $label = trim($match[2]);
            $plainLabel = trim(preg_replace('/[`*_]/', '', $label) ?? $label);
            $id = $slugify($plainLabel);
            if ($level === 1) {
                $title = $plainLabel;
            } else {
                $headings[] = ['id' => $id, 'level' => $level, 'label' => $plainLabel];
            }
            $html[] = '<h' . $level . ' id="' . $id . '">' . $inline($label) . '</h' . $level . '>';
            continue;
        }

        if (preg_match('/^\s*[-*]\s+(.+)$/', $line, $match) === 1) {
            $flushParagraph();
            if ($listType !== 'ul') {
                $closeList();
                $html[] = '<ul>';
                $listType = 'ul';
            }
            $html[] = '<li>' . $inline($match[1]) . '</li>';
            continue;
        }

        if (preg_match('/^\s*\d+\.\s+(.+)$/', $line, $match) === 1) {
            $flushParagraph();
            if ($listType !== 'ol') {
                $closeList();
                $html[] = '<ol>';
                $listType = 'ol';
            }
            $html[] = '<li>' . $inline($match[1]) . '</li>';
            continue;
        }

        $closeList();
        $paragraph[] = $line;
    }

    if ($inCode) {
        $html[] = '<pre><code>' . htmlspecialchars(implode("\n", $codeLines), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</code></pre>';
    }
    $flushParagraph();
    $closeList();

    return ['html' => implode("\n", $html), 'title' => $title, 'headings' => $headings];
}
