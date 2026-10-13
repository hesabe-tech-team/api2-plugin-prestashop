<?php
/**
 * Copyright (c) 2026 the hesabe SDK authors. MIT License, see LICENSE.
 */

// Checks that every string the module translates has an entry in each legacy translation
// file under translations/, and that no entry is left over from a string that changed.
// PrestaShop keys an entry by the source file and the md5 of the English text, so editing a
// sentence silently drops its translation unless this check runs.
//
//   php scripts/translations.php            check every translations/*.php
//   php scripts/translations.php --list     print each key with its English text

declare(strict_types=1);

$root = dirname(__DIR__);

/**
 * The keys PrestaShop looks up, as Translate::getModuleTranslation builds them.
 *
 * @return array<string, string> key => English text
 */
function hesabe_strings(string $root): array
{
    $strings = [];
    $add = static function (string $source, string $text) use (&$strings): void {
        // Translate::getModuleTranslation escapes quotes before hashing.
        $hash = md5((string) preg_replace("/\\\\*'/", "\\'", $text));
        $strings[strtolower('<{hesabe}prestashop>' . $source) . '_' . $hash] = $text;
    };
    $unquote = static fn (string $s): string => strtr($s, ["\\'" => "'", '\\\\' => '\\']);
    $quoted = "'((?:[^'\\\\]|\\\\.)*)'";

    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
    foreach ($files as $file) {
        $path = str_replace('\\', '/', substr((string) $file, strlen($root) + 1));
        if (preg_match('#^(\.git|vendor|build|tests|dev|scripts|translations)/#', $path)) {
            continue;
        }
        $code = (string) file_get_contents((string) $file);
        if (str_ends_with($path, '.php')) {
            // Module::l() takes the module name as the source unless a second argument names one.
            preg_match_all("/->l\\(\\s*{$quoted}\\s*(?:,\\s*'([a-z_]+)'\\s*)?\\)/", $code, $found, PREG_SET_ORDER);
            foreach ($found as $match) {
                $add(($match[2] ?? '') !== '' ? $match[2] : 'hesabe', $unquote($match[1]));
            }
        } elseif (str_ends_with($path, '.tpl')) {
            // {l s='...' mod='hesabe'} takes the template's file name as the source.
            preg_match_all("/\\{l\\s+s={$quoted}[^}]*mod='hesabe'/", $code, $found, PREG_SET_ORDER);
            foreach ($found as $match) {
                $add(basename($path, '.tpl'), $unquote($match[1]));
            }
        }
    }
    ksort($strings);
    return $strings;
}

$strings = hesabe_strings($root);

if (in_array('--list', $argv, true)) {
    foreach ($strings as $key => $text) {
        echo $key, "\t", $text, "\n";
    }
    exit(0);
}

$failed = false;
foreach (glob($root . '/translations/*.php') ?: [] as $file) {
    if (basename($file) === 'index.php') {
        continue;
    }
    $_MODULE = [];
    include $file;
    $missing = array_diff_key($strings, $_MODULE);
    $stale = array_diff_key($_MODULE, $strings);
    foreach ($missing as $key => $text) {
        echo basename($file), ": missing {$key} ({$text})\n";
    }
    foreach (array_keys($stale) as $key) {
        echo basename($file), ": no longer used {$key}\n";
    }
    $failed = $failed || $missing !== [] || $stale !== [];
    echo basename($file), ': ', count($strings) - count($missing), ' of ', count($strings), " strings\n";
}
exit($failed ? 1 : 0);
