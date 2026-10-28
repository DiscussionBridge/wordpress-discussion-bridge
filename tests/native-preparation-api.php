<?php

declare(strict_types=1);

// Exact WordPress6.6 native parser/IRI source, TEST ONLY. Production uses the installed platform.
$native_api = __DIR__ . '/fixtures/native-wordpress-6.6/';
$native_manifest = json_decode(file_get_contents($native_api . 'SOURCE_MANIFEST.json'), false, 512, JSON_THROW_ON_ERROR);
foreach ($native_manifest->members as $member) {
    $path = $native_api . $member->path;
    if (filesize($path) !== $member->bytes || hash_file('sha256', $path) !== $member->sha256) throw new RuntimeException('Native preparation fixture identity mismatch.');
}
foreach (['class-wp-token-map.php', 'html5-named-character-references.php', 'class-wp-html-attribute-token.php',
    'class-wp-html-text-replacement.php', 'class-wp-html-span.php', 'class-wp-html-decoder.php', 'class-wp-html-tag-processor.php',
    'Exception.php', 'InvalidArgument.php', 'InputValidator.php', 'Ipv6.php', 'Port.php', 'Iri.php'] as $file) require_once $native_api . $file;
unset($native_api, $native_manifest, $member, $path, $file);

function wp_kses_uri_attributes(): array {
    return ['action', 'archive', 'background', 'cite', 'classid', 'codebase', 'data', 'formaction', 'href', 'icon', 'longdesc', 'manifest', 'poster', 'profile', 'src', 'usemap', 'xmlns'];
}
