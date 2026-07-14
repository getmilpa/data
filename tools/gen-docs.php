<?php

/**
 * This file is part of milpa/data — the runtime-native persistence primitive of the Milpa PHP
 * framework.
 *
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency <hola@teamx.agency>
 *
 * @license Apache-2.0
 *
 * @link    https://github.com/getmilpa/data
 */

declare(strict_types=1);

/**
 * Generates the static API reference site for milpa/data.
 *
 * Thin entry over the family docs generator (`Milpa\Docs\SiteGenerator`,
 * shipped inside the milpa/core dist and pulled in here as a dev-only tool —
 * milpa/data has zero package dependencies at runtime): reflects over
 * `src/`, renders one `mui-api`-styled page per public type wrapped in the
 * `mui-docs` shell, a nav, a per-page table of contents, and `index.html`.
 *
 * Usage: php tools/gen-docs.php --out <dir> [--css-base <url>] [--version <v>]
 */

require dirname(__DIR__) . '/vendor/autoload.php';

// Required-value long options (`name:`, not `name::`) so `--css-base /ds` with a
// space is captured; optional (`::`) only binds `--css-base=/ds`. getopt yields
// `false` for a flag it can't bind a value to, so guard with is_string, not `??`
// (which only rescues null) before falling back to the default.
$opts = getopt('', ['out:', 'css-base:', 'version:']);
$out = is_string($opts['out'] ?? null) ? $opts['out'] : 'build/docs';
$cssBase = is_string($opts['css-base'] ?? null) ? $opts['css-base'] : 'https://cdn.jsdelivr.net/npm/@milpa/design@0.8.0';

// Version shown in the docs chrome (topbar badge, title, footer). Prefer an
// explicit --version; otherwise read the release-please manifest (present in
// the published repo); fall back to "dev" for local builds.
$version = is_string($opts['version'] ?? null) ? $opts['version'] : null;
if ($version === null) {
    $manifest = dirname(__DIR__) . '/.github/.release-please-manifest.json';
    $data = is_file($manifest) ? json_decode((string) file_get_contents($manifest), true) : null;
    $version = is_array($data) && is_string($data['.'] ?? null) ? $data['.'] : 'dev';
}

// Branding for this package's docs site — see Milpa\Docs\SiteConfig (milpa/core).
$config = new Milpa\Docs\SiteConfig(
    brand: 'Milpa Data',
    nsPrefix: 'Milpa\\Data\\',
    installCommand: 'composer require milpa/data',
    repoUrl: 'https://github.com/getmilpa/data',
    pagesUrl: 'https://getmilpa.github.io/data/',
    heroParagraph: 'Runtime-native <strong>persistence</strong> for Milpa: plain entities (no ORM base '
        . 'class, no attributes) behind a small repository contract, with two interchangeable backends '
        . '— <strong>file</strong> (JSON) and <strong>in-memory</strong>. Zero Doctrine, zero database, '
        . 'zero infrastructure. The persistence primitive an agent-scaffolded entity targets.',
    utmContent: 'data',
);

$count = (new Milpa\Docs\SiteGenerator(dirname(__DIR__) . '/src', $out, $cssBase, $version, $config))->generate();

echo "generated {$count} page(s) to {$out} (v{$version}, css-base: {$cssBase})\n";
exit(0);
