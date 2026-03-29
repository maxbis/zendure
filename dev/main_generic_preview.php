<?php
declare(strict_types=1);
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Main Generic CSS Preview</title>
    <link rel="stylesheet" href="css/main_generic.css">
    <style>
        .preview-stack {
            display: flex;
            flex-direction: column;
            gap: 12px;
        }

        .preview-text {
            color: var(--text-secondary);
            line-height: 1.5;
        }

        .preview-row {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            align-items: center;
        }

        .preview-chip {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 8px 10px;
            border-radius: 8px;
            border: 1px solid var(--border-color);
            background: var(--bg-tertiary);
            color: var(--text-primary);
            font-size: 0.9rem;
        }

        .preview-token-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
            gap: 10px;
        }

        .preview-swatch {
            border: 1px solid var(--border-color);
            border-radius: 10px;
            overflow: hidden;
            background: var(--bg-tertiary);
        }

        .preview-swatch-color {
            height: 52px;
        }

        .preview-swatch-label {
            padding: 10px;
            font-size: 0.82rem;
            color: var(--text-secondary);
            font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
        }

        .preview-surface {
            padding: 12px;
            border-radius: 8px;
            border: 1px solid var(--border-color);
        }

        .preview-surface-primary {
            background: var(--bg-primary);
        }

        .preview-surface-secondary {
            background: var(--bg-secondary);
        }

        .preview-surface-tertiary {
            background: var(--bg-tertiary);
        }

        .preview-modal-shell {
            padding: 12px;
            border: 1px solid var(--border-color);
            border-radius: 12px;
            background: var(--bg-tertiary);
        }

        .preview-header-spaced {
            margin-bottom: 12px;
        }

        .preview-body-copy {
            color: var(--text-primary);
        }

        .preview-muted {
            color: var(--text-tertiary);
        }
    </style>
</head>
<body class="mobile-dark">
<div class="container">
    <div class="header">
        <h1>Generic CSS Preview</h1>
    </div>

    <section class="card">
        <h2 class="card-header">Overview</h2>
        <div class="preview-stack">
            <p class="preview-text">
                This page previews the shared primitives defined in <code>dev/css/main_generic.css</code>.
                It is only a sample page and is not wired into the main application.
            </p>
            <div class="preview-row">
                <span class="preview-chip">body.mobile-dark</span>
                <span class="preview-chip">.container</span>
                <span class="preview-chip">.header</span>
                <span class="preview-chip">.card</span>
                <span class="preview-chip">.card-header</span>
                <span class="preview-chip">.btn*</span>
            </div>
        </div>
    </section>

    <section class="card">
        <h2 class="card-header">Theme Tokens</h2>
        <div class="preview-token-grid">
            <div class="preview-swatch">
                <div class="preview-swatch-color" style="background: var(--bg-primary);"></div>
                <div class="preview-swatch-label">--bg-primary</div>
            </div>
            <div class="preview-swatch">
                <div class="preview-swatch-color" style="background: var(--bg-secondary);"></div>
                <div class="preview-swatch-label">--bg-secondary</div>
            </div>
            <div class="preview-swatch">
                <div class="preview-swatch-color" style="background: var(--bg-tertiary);"></div>
                <div class="preview-swatch-label">--bg-tertiary</div>
            </div>
            <div class="preview-swatch">
                <div class="preview-swatch-color" style="background: var(--border-color);"></div>
                <div class="preview-swatch-label">--border-color</div>
            </div>
            <div class="preview-swatch">
                <div class="preview-swatch-color" style="background: var(--charge-color);"></div>
                <div class="preview-swatch-label">--charge-color</div>
            </div>
            <div class="preview-swatch">
                <div class="preview-swatch-color" style="background: var(--discharge-color);"></div>
                <div class="preview-swatch-label">--discharge-color</div>
            </div>
            <div class="preview-swatch">
                <div class="preview-swatch-color" style="background: var(--netzero-color);"></div>
                <div class="preview-swatch-label">--netzero-color</div>
            </div>
            <div class="preview-swatch">
                <div class="preview-swatch-color" style="background: var(--netzero-plus-color);"></div>
                <div class="preview-swatch-label">--netzero-plus-color</div>
            </div>
        </div>
    </section>

    <section class="card">
        <h2 class="card-header">Layout Surfaces</h2>
        <div class="preview-stack">
            <div class="preview-surface preview-surface-primary">Primary surface using <code>--bg-primary</code>.</div>
            <div class="preview-surface preview-surface-secondary">Secondary surface using <code>--bg-secondary</code>.</div>
            <div class="preview-surface preview-surface-tertiary">Tertiary surface using <code>--bg-tertiary</code>.</div>
        </div>
    </section>

    <section class="card">
        <h2 class="card-header">Buttons</h2>
        <div class="preview-stack">
            <div class="preview-row">
                <button class="btn">Default Button</button>
                <button class="btn btn-primary">Primary Button</button>
                <button class="btn btn-outline">Outline Button</button>
                <button class="btn btn-danger">Danger Button</button>
                <button class="btn btn-add">Add Button</button>
                <button class="btn btn-primary" disabled>Disabled Button</button>
            </div>
        </div>
    </section>

    <section class="card">
        <h2 class="card-header">Semantic Text Colors</h2>
        <div class="preview-stack">
            <p class="preview-body-copy">
                Status classes:
                <strong class="charge">charge</strong>,
                <strong class="discharge">discharge</strong>,
                <strong class="neutral">neutral</strong>,
                <strong class="netzero">netzero</strong>,
                <strong class="netzero-plus">netzero-plus</strong>.
            </p>
            <p class="preview-muted">Muted text example using the dashboard token palette.</p>
        </div>
    </section>

    <section class="card">
        <h2 class="card-header card-header--no-line preview-header-spaced">Modal Header Primitive</h2>
        <div class="preview-modal-shell">
            <div class="modal-header">
                <div class="modal-title">Example Modal Title</div>
                <button class="btn btn-outline">Close</button>
            </div>
            <p class="preview-text">
                This block previews the shared <code>.modal-header</code> and <code>.modal-title</code> primitives
                without bringing in any page-specific modal styles.
            </p>
        </div>
    </section>
</div>
</body>
</html>
