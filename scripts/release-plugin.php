#!/usr/bin/env php
<?php

$pluginFile = __DIR__ . '/../wp-easyscripts-payment-api.php';
$version = $argv[1] ?? null;

if (!$version) {
    echo "Enter new plugin version (e.g. 1.2.3): ";
    $version = trim(fgets(STDIN));
}

if (!preg_match('/^\d+\.\d+\.\d+$/', $version)) {
    echo "❌ Invalid version format. Use semantic versioning (e.g. 1.2.3).\n";
    exit(1);
}

$content = file_get_contents($pluginFile);
$lines = explode("\n", $content);
$foundVersion = false;

// Substitui se já houver "Version:"
foreach ($lines as $i => $line) {
    if (preg_match('/^\s*\*\s*Version:/i', $line)) {
        $lines[$i] = preg_replace('/(Version:\s*)([\d\.]+)/i', '${1}' . $version, $line);
        $foundVersion = true;
        break;
    }
}

// Se não encontrou, tenta inserir após "Plugin Name:"
if (!$foundVersion) {
    foreach ($lines as $i => $line) {
        if (preg_match('/^\s*\*\s*Plugin Name:/i', $line)) {
            array_splice($lines, $i + 1, 0, " * Version: $version");
            $foundVersion = true;
            break;
        }
    }
}

// Se ainda não encontrou, adiciona no final do cabeçalho (antes da linha "*/")
if (!$foundVersion) {
    foreach ($lines as $i => $line) {
        if (preg_match('/^\s*\*\/\s*$/', $line)) {
            array_splice($lines, $i, 0, " * Version: $version");
            $foundVersion = true;
            break;
        }
    }
}

// Se mesmo assim falhar, adiciona no topo como último recurso
if (!$foundVersion) {
    array_unshift($lines, "/**", " * Version: $version", " */");
    echo "⚠️ Plugin header not found, creating new block with Version tag.\n";
} else {
    echo "✅ Plugin version set to $version\n";
}

file_put_contents($pluginFile, implode("\n", $lines));

// Git operations
exec("git add $pluginFile", $o1, $c1);
exec("git commit -m \"Release v$version\"", $o2, $c2);
exec("git tag v$version", $o3, $c3);
exec("git push origin main", $o4, $c4);
exec("git push origin v$version", $o5, $c5);

if ($c1 || $c2 || $c3 || $c4 || $c5) {
    echo "❌ One or more Git commands failed.\n";
    exit(1);
}

echo "🎉 Released version v$version to main and pushed tag.\n";
