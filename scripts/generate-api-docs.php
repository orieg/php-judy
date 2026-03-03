#!/usr/bin/env php
<?php
/**
 * API.md generator for php-judy.
 *
 * Reads Judy.stub.php (signatures + PHPDoc descriptions) and
 * scripts/api-metadata.php (supplemental data) to produce API.md.
 *
 * Usage:
 *   php scripts/generate-api-docs.php          # Regenerate API.md
 *   php scripts/generate-api-docs.php --check  # Verify API.md is up to date (exit 1 if stale)
 */

$checkMode = in_array('--check', $argv, true);
$rootDir = dirname(__DIR__);
$stubFile = $rootDir . '/Judy.stub.php';
$metaFile = __DIR__ . '/api-metadata.php';
$outputFile = $rootDir . '/API.md';

// ── Phase A: Parse the stub ─────────────────────────────────────

$stubCode = file_get_contents($stubFile);
if ($stubCode === false) {
    fwrite(STDERR, "Error: Cannot read $stubFile\n");
    exit(1);
}

$tokens = token_get_all($stubCode);
$methods = [];      // name => ['signature' => ..., 'doc' => ..., 'is_static' => bool]
$constants = [];    // name => value
$functions = [];    // name => ['signature' => ..., 'doc' => ...]
$interfaces = [];   // list of implemented interfaces

$lastDoc = null;
$inClass = false;
$braceDepth = 0;
$i = 0;
$count = count($tokens);

while ($i < $count) {
    $token = $tokens[$i];

    if (is_array($token)) {
        [$id, $text] = $token;

        if ($id === T_DOC_COMMENT) {
            $lastDoc = $text;
            $i++;
            continue;
        }

        if ($id === T_CLASS && !$inClass) {
            $inClass = true;
            // Parse "class Judy implements A, B, C {"
            $j = $i + 1;
            while ($j < $count) {
                if (is_array($tokens[$j]) && $tokens[$j][0] === T_IMPLEMENTS) {
                    $j++;
                    $ifaceName = '';
                    while ($j < $count && !(is_string($tokens[$j]) && $tokens[$j] === '{')) {
                        if (is_array($tokens[$j])) {
                            $ifaceName .= $tokens[$j][1];
                        } elseif ($tokens[$j] === ',') {
                            $interfaces[] = trim($ifaceName);
                            $ifaceName = '';
                        }
                        $j++;
                    }
                    if (trim($ifaceName) !== '') {
                        $interfaces[] = trim($ifaceName);
                    }
                    break;
                }
                if (is_string($tokens[$j]) && $tokens[$j] === '{') {
                    break;
                }
                $j++;
            }
            $lastDoc = null;
            $i++;
            continue;
        }

        if ($id === T_CONST && $inClass) {
            // Parse: public const int NAME = VALUE;
            $j = $i + 1;
            $constName = null;
            $constValue = null;
            while ($j < $count && $tokens[$j] !== ';') {
                if (is_array($tokens[$j]) && $tokens[$j][0] === T_STRING && $constName === null) {
                    // Skip type keyword (int)
                    if (in_array($tokens[$j][1], ['int', 'string', 'float', 'bool', 'array'], true)) {
                        $j++;
                        continue;
                    }
                    $constName = $tokens[$j][1];
                } elseif (is_array($tokens[$j]) && $tokens[$j][0] === T_LNUMBER && $constName !== null) {
                    $constValue = (int)$tokens[$j][1];
                }
                $j++;
            }
            if ($constName !== null && $constValue !== null) {
                $constants[$constName] = $constValue;
            }
            $lastDoc = null;
            $i = $j + 1;
            continue;
        }

        if ($id === T_FUNCTION) {
            $isStatic = false;
            // Look back for 'static' keyword
            for ($b = $i - 1; $b >= max(0, $i - 6); $b--) {
                if (is_array($tokens[$b]) && $tokens[$b][0] === T_STATIC) {
                    $isStatic = true;
                    break;
                }
            }

            // Get function name
            $j = $i + 1;
            while ($j < $count && is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) {
                $j++;
            }
            $funcName = is_array($tokens[$j]) ? $tokens[$j][1] : '';

            // Get full signature up to and including the closing )
            // For display we need: "public [static] function name(params): returnType"
            $sigStart = $i;
            // Go back to find 'public'
            for ($b = $i - 1; $b >= max(0, $i - 6); $b--) {
                if (is_array($tokens[$b]) && $tokens[$b][0] === T_PUBLIC) {
                    $sigStart = $b;
                    break;
                }
            }

            // Collect signature text up to and including closing brace or semicolon
            $sigParts = [];
            $k = $sigStart;
            $parenDepth = 0;
            $pastCloseParen = false;
            while ($k < $count) {
                $t = $tokens[$k];
                $tText = is_array($t) ? $t[1] : $t;

                if ($tText === '(') $parenDepth++;
                if ($tText === ')') {
                    $parenDepth--;
                    if ($parenDepth === 0) $pastCloseParen = true;
                }

                if ($tText === '{' && $pastCloseParen) break;

                $sigParts[] = $tText;
                $k++;
            }

            // Clean up signature: collapse whitespace, trim
            $signature = implode('', $sigParts);
            $signature = preg_replace('/\s+/', ' ', $signature);
            $signature = trim($signature);
            // Remove trailing {}
            $signature = preg_replace('/\s*\{\s*\}\s*$/', '', $signature);

            $entry = [
                'signature' => $signature,
                'doc' => $lastDoc,
                'is_static' => $isStatic,
            ];

            if ($inClass) {
                $methods[$funcName] = $entry;
            } else {
                $functions[$funcName] = $entry;
            }

            $lastDoc = null;
            $i = $k + 1;
            continue;
        }
    }

    // Track brace depth for class boundary
    if (is_string($token)) {
        if ($token === '{') $braceDepth++;
        if ($token === '}') {
            $braceDepth--;
            if ($braceDepth === 0 && $inClass) {
                $inClass = false;
            }
        }
    }

    $i++;
}

/**
 * Extract the prose description from a PHPDoc comment block.
 * Returns only the free-text lines (not @-tags).
 */
function extractDescription(string $doc): string {
    $lines = explode("\n", $doc);
    $desc = [];
    foreach ($lines as $line) {
        // Strip leading PHPDoc markers: /** , * , */ etc.
        $line = preg_replace('/^\s*\/?\*+\/?\s?/', '', $line);
        // Strip trailing */ (for single-line /** text */ comments)
        $line = preg_replace('/\s*\*\/\s*$/', '', $line);
        $line = rtrim($line);
        // Skip @-tag lines
        if (preg_match('/^\s*@/', $line)) {
            continue;
        }
        $desc[] = $line;
    }
    // Trim empty lines from start and end
    $text = implode("\n", $desc);
    return trim($text);
}

// ── Phase B: Load metadata ──────────────────────────────────────

$meta = require $metaFile;

// ── Phase C: Drift detection ────────────────────────────────────

$errors = [];

// Collect all method names referenced in sections
$sectionMethods = [];
foreach ($meta['sections'] as $secName => $sec) {
    foreach ($sec['methods'] as $m) {
        $sectionMethods[$m] = $secName;
    }
}

// Every stub method (except skipped) must appear in a section
$skipDrift = $meta['skip_drift_check'] ?? [];
foreach ($methods as $name => $_) {
    if (in_array($name, $skipDrift, true)) continue;
    if (!isset($sectionMethods[$name]) && !in_array($name, $meta['global_functions'] ?? [], true)) {
        $errors[] = "Method '$name' exists in stub but is not assigned to any section in api-metadata.php";
    }
}

// Every section method must exist in stub
foreach ($sectionMethods as $name => $secName) {
    if (!isset($methods[$name])) {
        $errors[] = "Method '$name' (in section '$secName') does not exist in the stub";
    }
}

// Every global function must exist in stub
foreach ($meta['global_functions'] as $fname) {
    if (!isset($functions[$fname])) {
        $errors[] = "Global function '$fname' does not exist in the stub";
    }
}

// Every constant in type_groups must exist in stub, and vice versa
$metaConstants = [];
foreach ($meta['type_groups'] as $group) {
    foreach ($group as $cname => $cinfo) {
        $metaConstants[$cname] = $cinfo['value'];
    }
}
foreach ($constants as $name => $value) {
    if (!isset($metaConstants[$name])) {
        $errors[] = "Constant '$name' exists in stub but not in type_groups in api-metadata.php";
    } elseif ($metaConstants[$name] !== $value) {
        $errors[] = "Constant '$name' value mismatch: stub=$value, metadata={$metaConstants[$name]}";
    }
}
foreach ($metaConstants as $name => $value) {
    if (!isset($constants[$name])) {
        $errors[] = "Constant '$name' in type_groups does not exist in the stub";
    }
}

if (!empty($errors)) {
    fwrite(STDERR, "Drift detected between Judy.stub.php and api-metadata.php:\n");
    foreach ($errors as $e) {
        fwrite(STDERR, "  - $e\n");
    }
    exit(1);
}

// ── Phase D: Generate Markdown ──────────────────────────────────

$out = '';

// Helper: multibyte-safe string length (falls back to strlen if mbstring unavailable)
function str_width(string $s): int {
    return function_exists('mb_strlen') ? mb_strlen($s) : strlen($s);
}

// Helper: emit a method signature in a fenced code block
function formatSignature(array $entry): string {
    return "```php\n{$entry['signature']}\n```\n";
}

// Helper: pad a string to a given width
function pad(string $s, int $width): string {
    $len = str_width($s);
    return $s . str_repeat(' ', max(0, $width - $len));
}

// Helper: build a markdown table from headers and rows
function markdownTable(array $headers, array $rows): string {
    // Compute column widths
    $widths = [];
    foreach ($headers as $ci => $h) {
        $widths[$ci] = str_width($h);
    }
    foreach ($rows as $row) {
        foreach ($row as $ci => $cell) {
            $widths[$ci] = max($widths[$ci] ?? 0, str_width($cell));
        }
    }

    $lines = [];
    // Header
    $hLine = '|';
    $sLine = '|';
    foreach ($headers as $ci => $h) {
        $hLine .= ' ' . pad($h, $widths[$ci]) . ' |';
        $sLine .= ' ' . str_repeat('-', $widths[$ci]) . ' |';
    }
    $lines[] = $hLine;
    $lines[] = $sLine;
    // Rows
    foreach ($rows as $row) {
        $rLine = '|';
        foreach ($row as $ci => $cell) {
            $rLine .= ' ' . pad($cell, $widths[$ci]) . ' |';
        }
        $lines[] = $rLine;
    }
    return implode("\n", $lines) . "\n";
}

// ── Title and intro ──

$out .= "# {$meta['title']}\n\n";
$out .= "{$meta['intro']}\n\n";

// ── Table of Contents ──

$tocSections = array_keys($meta['sections']);
$tocExtra = ['Global Functions', 'Type Compatibility Matrix'];
$allTocEntries = array_merge(['Type Constants'], $tocSections, $tocExtra);

$out .= "## Table of Contents\n\n";
foreach ($allTocEntries as $idx => $name) {
    $anchor = strtolower(preg_replace('/[^a-z0-9 -]/i', '', $name));
    $anchor = preg_replace('/\s+/', '-', $anchor);
    $num = $idx + 1;
    $out .= "{$num}. [{$name}](#{$anchor})\n";
}
$out .= "\n---\n\n";

// ── Type Constants ──

$out .= "## Type Constants\n\n";
$out .= "PHP Judy provides " . count($constants) . " array types, each optimized for different key/value combinations and access patterns.\n\n";

foreach ($meta['type_groups'] as $groupName => $groupConstants) {
    $out .= "### {$groupName}\n\n";

    $headers = ['Constant', 'Value', 'Description', 'Backing Structure'];
    $rows = [];
    foreach ($groupConstants as $cname => $cinfo) {
        $rows[] = [
            "`Judy::{$cname}`",
            (string)$cinfo['value'],
            $cinfo['description'],
            $cinfo['backing'],
        ];
    }
    $out .= markdownTable($headers, $rows);
    $out .= "\n";

    if (isset($meta['type_group_notes'][$groupName])) {
        $out .= $meta['type_group_notes'][$groupName] . "\n\n";
    }
}

$out .= "---\n\n";

// ── Sections ──

foreach ($meta['sections'] as $secName => $sec) {
    $out .= "## {$secName}\n\n";

    if (!empty($sec['description'])) {
        $out .= $sec['description'] . "\n\n";
    }

    if (!empty($sec['example']) && !empty($sec['hide_individual_methods'])) {
        $out .= "```php\n" . $sec['example'] . "\n```\n\n";
    }

    // Key/Value type table (special for Array Access)
    if ($secName === 'Array Access' && isset($meta['key_value_table'])) {
        $out .= "### Key/Value Types per Array Type\n\n";
        $headers = ['Type', 'Key', 'Value', 'Notes'];
        $rows = [];
        foreach ($meta['key_value_table'] as $tname => $tinfo) {
            $rows[] = [$tname, $tinfo['key'], $tinfo['value'], $tinfo['notes']];
        }
        $out .= markdownTable($headers, $rows);
        $out .= "\n";
    }

    if (!empty($sec['hide_individual_methods'])) {
        if (!empty($sec['notes'])) {
            $out .= $sec['notes'] . "\n\n";
        }
        $out .= "---\n\n";
        continue;
    }

    // Render individual methods
    $rendered = []; // track which methods have been rendered (for groups)
    foreach ($sec['methods'] as $methodName) {
        if (isset($rendered[$methodName])) continue;

        $methodMeta = $meta['methods'][$methodName] ?? [];
        $entry = $methods[$methodName] ?? null;
        if (!$entry) continue;

        // Check if this method starts a group
        $group = $sec['method_groups'][$methodName] ?? null;
        if ($group) {
            $out .= "### {$group['group_heading']}\n\n";

            // Render all grouped signatures
            $out .= "```php\n";
            foreach ($group['group_members'] as $gm) {
                $gmEntry = $methods[$gm] ?? null;
                if ($gmEntry) {
                    $out .= $gmEntry['signature'] . "\n";
                }
                $rendered[$gm] = true;
            }
            $out .= "```\n\n";

            if (!empty($group['group_description'])) {
                $out .= $group['group_description'] . "\n\n";
            }

            // Render example from the first group member's metadata if any
            $firstMemberMeta = $meta['methods'][$methodName] ?? [];
            if (!empty($firstMemberMeta['example'])) {
                $out .= "```php\n" . $firstMemberMeta['example'] . "\n```\n\n";
            }

            continue;
        }

        // Regular method
        $out .= "### {$methodName}()\n\n";
        $out .= formatSignature($entry);
        $out .= "\n";

        // Description: prefer metadata override, fall back to stub PHPDoc
        if (!empty($methodMeta['description'])) {
            $out .= $methodMeta['description'] . "\n\n";
        } elseif ($entry['doc']) {
            $desc = extractDescription($entry['doc']);
            if ($desc !== '') {
                $out .= $desc . "\n\n";
            }
        }

        // Supported types
        if (!empty($methodMeta['supported_types'])) {
            $out .= "**Supported types**: {$methodMeta['supported_types']}\n\n";
        }

        // Notes
        if (!empty($methodMeta['notes'])) {
            $out .= $methodMeta['notes'] . "\n\n";
        }

        // Custom table
        if (!empty($methodMeta['table'])) {
            $out .= markdownTable($methodMeta['table']['headers'], $methodMeta['table']['rows']);
            $out .= "\n";
        }

        // Example
        if (!empty($methodMeta['example'])) {
            $ex = $methodMeta['example'];
            // Single-line examples don't need multi-line formatting
            if (strpos($ex, "\n") === false) {
                $out .= "```php\n{$ex}\n```\n\n";
            } else {
                $out .= "```php\n{$ex}\n```\n\n";
            }
        }

        $rendered[$methodName] = true;
    }

    $out .= "---\n\n";
}

// ── Global Functions ──

$out .= "## Global Functions\n\n";
foreach ($meta['global_functions'] as $fname) {
    $entry = $functions[$fname] ?? null;
    if (!$entry) continue;
    $fmeta = $meta['methods'][$fname] ?? [];

    $out .= "### {$fname}()\n\n";
    $out .= "```php\n{$entry['signature']}\n```\n\n";

    if (!empty($fmeta['description'])) {
        $out .= $fmeta['description'] . "\n\n";
    } elseif ($entry['doc']) {
        $desc = extractDescription($entry['doc']);
        if ($desc !== '') {
            $out .= $desc . "\n\n";
        }
    }
}

$out .= "---\n\n";

// ── Type Compatibility Matrix ──

$out .= "## Type Compatibility Matrix\n\n";
$out .= "Summary of which methods are available for each type. Methods not listed here work with all 10 types.\n\n";

$matrixCols = $meta['matrix_columns'];
$headers = array_merge(['Method'], $matrixCols);
$rows = [];
foreach ($meta['compatibility_matrix'] as $methodLabel => $colValues) {
    $row = [$methodLabel];
    foreach ($matrixCols as $col) {
        $row[] = $colValues[$col] ?? '?';
    }
    $rows[] = $row;
}
$out .= markdownTable($headers, $rows);
$out .= "\n";

$out .= $meta['matrix_legend'] . "\n\n";
$out .= $meta['matrix_footer'] . "\n";

// ── Output ──────────────────────────────────────────────────────

if ($checkMode) {
    $existing = file_exists($outputFile) ? file_get_contents($outputFile) : '';
    if ($existing === $out) {
        echo "API.md is up to date.\n";
        exit(0);
    } else {
        fwrite(STDERR, "API.md is out of date. Run 'php scripts/generate-api-docs.php' to regenerate.\n");

        // Show a brief diff summary
        $existingLines = explode("\n", $existing);
        $generatedLines = explode("\n", $out);
        $maxLines = max(count($existingLines), count($generatedLines));
        $diffCount = 0;
        for ($i = 0; $i < $maxLines; $i++) {
            $a = $existingLines[$i] ?? '<missing>';
            $b = $generatedLines[$i] ?? '<missing>';
            if ($a !== $b) {
                $diffCount++;
                if ($diffCount <= 10) {
                    fwrite(STDERR, "  Line " . ($i + 1) . ":\n");
                    fwrite(STDERR, "    - " . substr($a, 0, 120) . "\n");
                    fwrite(STDERR, "    + " . substr($b, 0, 120) . "\n");
                }
            }
        }
        if ($diffCount > 10) {
            fwrite(STDERR, "  ... and " . ($diffCount - 10) . " more differences\n");
        }
        fwrite(STDERR, "Total: $diffCount line(s) differ.\n");
        exit(1);
    }
}

file_put_contents($outputFile, $out);
echo "Generated $outputFile (" . strlen($out) . " bytes)\n";
