<?php
declare(strict_types=1);

// Actual installed-byte loader/codec; no WordPress/database fixture.
require_once __DIR__ . '/../src/CmsArtifacts.php';
use DiscussionBridge\WordPress\CmsArtifacts;
use DiscussionBridge\CmsV4\ValidationException;

$codec = CmsArtifacts::codec();
$base = __DIR__ . '/fixtures/cms-v4/';
$positive = json_decode(file_get_contents($base . 'positive-examples.json'), false, 512, JSON_THROW_ON_ERROR)->fixtures;
$connection = null;
foreach ($positive as $item) if ($item->type === 'Connection') $connection = $item->value;
if (!$connection instanceof stdClass) throw new RuntimeException('Connection vector missing');
$checks = 0;
$assert = static function (bool $ok, string $reason) use (&$checks): void { $checks++; if (!$ok) throw new RuntimeException($reason); };
$validate = static function (string $type, mixed $value, bool $accepted, string $id) use ($codec, $assert): void {
    $before = serialize($value);
    try {
        $codec->validate($type, $value);
        $assert($accepted, 'Unexpected acceptance: ' . $id);
    } catch (ValidationException $error) {
        $assert(!$accepted && $error->validationCode === 'invalid_field', 'Unexpected rejection: ' . $id);
    }
    $assert(serialize($value) === $before, 'Input changed: ' . $id);
};
$policy = json_decode(file_get_contents($base . 'url-domain-policy.json'), true, 512, JSON_THROW_ON_ERROR);
foreach ($policy['cases'] as $item) {
    $validate('Url', $item['url'], $item['accepted'], $item['id']);
    if ($item['accepted']) $assert($codec->urlAuthority($item['url']) === $item['facts'], 'Wrong authority facts: ' . $item['id']);
}
$context = json_decode(file_get_contents($base . 'url-contextj.json'), true, 512, JSON_THROW_ON_ERROR);
foreach ($context['cases'] as $item) {
    foreach ([$item['label'] . '.example', $item['asciiHost']] as $host) {
        $url = 'https://' . $host;
        $validate('Url', $url . '/', $item['accepted'], $item['id']);
        $value = clone $connection;
        $value->destination_origin = $url;
        $validate('Connection', $value, $item['accepted'], $item['id'] . ':Connection');
    }
}
$private = json_decode(file_get_contents(__DIR__ . '/../src/private/cms-v4/PROVENANCE.json'), true, 512, JSON_THROW_ON_ERROR);
foreach ($private['members'] as $member) {
    $bytes = file_get_contents(__DIR__ . '/../src/private/cms-v4/' . $member['path']);
    $assert(strlen($bytes) === $member['bytes'] && hash('sha256', $bytes) === $member['sha256'], 'Private package byte mismatch');
}
foreach ($private['fixtures'] as $member) {
    $bytes = file_get_contents(__DIR__ . '/../' . $member['path']);
    $assert(strlen($bytes) === $member['bytes'] && hash('sha256', $bytes) === $member['sha256'], 'Fixture source mismatch');
}
echo json_encode(['checks' => $checks, 'context_labels' => count($context['cases']), 'private_members' => count($private['members']),
    'php' => PHP_VERSION, 'limits' => 'Actual packaged codec/loader only; not installed WordPress/native database qualification'], JSON_THROW_ON_ERROR) . "\n";
