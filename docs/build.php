<?php

use League\CommonMark\GithubFlavoredMarkdownConverter;

require dirname(__DIR__).'/vendor/autoload.php';

$root = dirname(__DIR__);
$output = $root.'/build/docs';
$pages = ['README.md' => 'index.html', 'CHANGELOG.md' => 'CHANGELOG.html', 'SECURITY.md' => 'SECURITY.html', 'SPEC.md' => 'SPEC.html', '.github/RELEASE_CHECKLIST.md' => 'release-checklist.html'];
foreach (glob(__DIR__.'/*.md') as $path) {
    $pages['docs/'.basename($path)] = 'docs/'.basename($path, '.md').'.html';
}
$converter = new GithubFlavoredMarkdownConverter(['html_input' => 'strip', 'allow_unsafe_links' => false]);
$escape = fn (string $value) => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
foreach ($pages as $source => $destination) {
    $prefix = str_starts_with($destination, 'docs/') ? '../' : '';
    $html = (string) $converter->convert(file_get_contents($root.'/'.$source));
    $html = preg_replace_callback('/href="([^"]+)"/', function (array $match) use ($root, $source, $prefix, $pages, $escape): string {
        $href = html_entity_decode($match[1], ENT_QUOTES, 'UTF-8');
        if (preg_match('~^(?:https?://|mailto:|#)~', $href)) {
            return $match[0];
        }
        [$file, $anchor] = array_pad(explode('#', $href, 2), 2, null);
        $path = realpath($root.'/'.dirname($source).'/'.$file);
        if ($path === false || ! str_starts_with($path, $root.'/') || ! is_file($path)) {
            throw new RuntimeException('Broken local documentation link in '.$source.': '.$href);
        }
        $relative = substr($path, strlen($root) + 1);
        $target = $pages[$relative] ?? ($relative === 'config/clearance.php' ? $relative.'.txt' : $relative);

        return 'href="'.$escape($prefix.$target.($anchor !== null ? '#'.$anchor : '')).'"';
    }, $html);
    $title = preg_match('/^# (.+)$/m', file_get_contents($root.'/'.$source), $match) ? $match[1] : 'Clearance';
    $nav = '<nav aria-label="Documentation"><a href="'.$prefix.'index.html">Clearance</a> · <a href="'.$prefix.'docs/api.html">API</a> · <a href="'.$prefix.'docs/migration.html">Upgrade</a> · <a href="'.$prefix.'docs/recipes.html">Recipes</a> · <a href="'.$prefix.'docs/support.html">Support</a></nav>';
    $page = '<!doctype html><html lang="en"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>'.$escape($title).'</title><style>body{font:18px/1.6 system-ui,sans-serif;color:#17212b;background:#fff;max-width:76ch;margin:auto;padding:1.5rem}nav{border-bottom:1px solid #bbb;padding-bottom:1rem}a{color:#064c9b}a:focus-visible{outline:3px solid #064c9b;outline-offset:3px}pre,table{display:block;overflow:auto}pre{background:#f2f5f7;padding:1rem}code{font-size:.9em}th,td{padding:.4rem;text-align:left;border-bottom:1px solid #ddd}blockquote{border-left:4px solid #687787;padding-left:1rem;margin-left:0}main{overflow-wrap:anywhere}</style>'.$nav.'<main>'.$html.'</main></html>';
    $directory = dirname($output.'/'.$destination);
    if (! is_dir($directory)) {
        mkdir($directory, 0755, true);
    }
    file_put_contents($output.'/'.$destination, $page);
}
foreach (['LICENSE', 'config/clearance.php'] as $asset) {
    $directory = dirname($output.'/'.$asset);
    if (! is_dir($directory)) {
        mkdir($directory, 0755, true);
    }
    copy($root.'/'.$asset, $output.'/'.$asset.($asset === 'config/clearance.php' ? '.txt' : ''));
}
fwrite(STDOUT, 'Built '.count($pages).' documentation pages; local links checked. Open '.$output."/index.html\n");
