<?php

declare(strict_types=1);

// Generate the release assets after the ZIP exists, so Joomla can verify its hash.
[$script, $archive, $manifestPath, $destination] = $argv;
$manifest = simplexml_load_file($manifestPath);
if ($manifest === false || !is_file($archive)) {
    throw new RuntimeException('Missing archive or invalid extension manifest.');
}

$version = trim((string) $manifest->version);
if (!preg_match('/^\d+\.\d+\.\d+$/D', $version)) {
    throw new RuntimeException('Expected a three-part numeric release version.');
}

$hash = hash_file('sha256', $archive);
$name = basename($archive);
$download = "https://github.com/spamtroll/spamtroll-joomla/releases/download/v{$version}/{$name}";
$feed = new SimpleXMLElement('<?xml version="1.0" encoding="utf-8"?><updates/>');

// Separate entries avoid claiming compatibility with untested Joomla 6.
foreach (['4\.4', '5\.[0-9]+'] as $platform) {
    $update = $feed->addChild('update');
    $update->addChild('name', 'System - Spamtroll');
    $update->addChild('description', 'Spamtroll API integration for registrations and content saves.');
    $update->addChild('element', 'spamtroll');
    $update->addChild('type', 'plugin');
    $update->addChild('folder', 'system');
    $update->addChild('client', '0');
    $update->addChild('version', $version);
    $source = $update->addChild('downloads')->addChild('downloadurl', $download);
    $source->addAttribute('type', 'full');
    $source->addAttribute('format', 'zip');
    $update->addChild('tags')->addChild('tag', 'stable');
    $target = $update->addChild('targetplatform');
    $target->addAttribute('name', 'joomla');
    $target->addAttribute('version', $platform);
    $update->addChild('php_minimum', (string) $manifest->php_minimum);
    $update->addChild('sha256', $hash);
}

$document = new DOMDocument('1.0', 'utf-8');
$document->preserveWhiteSpace = false;
$document->formatOutput = true;
if (!$document->loadXML($feed->asXML()) ||
    $document->save($destination . '/updates.xml') === false ||
    file_put_contents($archive . '.sha256', "{$hash}  {$name}\n") === false) {
    throw new RuntimeException('Failed to write release metadata.');
}
