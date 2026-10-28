<?php

declare(strict_types=1);

// Original 987/1056: the installed cron/frontend permission-loss path has no admin notice helper.
// Invoke frontend and admin in separate PHP processes; this is a focused Settings regression,
// with explicit user/option boundaries, not installed cron/storage qualification.
$context = $argv[1] ?? '';
if (!in_array($context, ['frontend', 'admin'], true)) {
    fwrite(STDERR, "Explicit frontend or admin context required.\n");
    exit(2);
}
$source = $argv[2] ?? __DIR__ . '/../src/Settings.php';
if (!is_file($source) || !is_readable($source)) {
    fwrite(STDERR, "Explicit readable Settings source required.\n");
    exit(2);
}
if (function_exists('add_settings_error')) throw new RuntimeException('Fresh PHP process required.');
if ($context === 'admin') {
    function add_settings_error(string $setting, string $code, string $message): void {
        $GLOBALS['service_context']['notices'][] = [$setting, $code, $message];
    }
}

final class WP_User
{
    public function __construct(public int $ID, public string $user_login, public bool $publish_posts = true) {}
}
function sanitize_user(string $value, bool $strict = false): string { return trim($value); }
function get_option(string $name, mixed $default = false): mixed {
    if ($name !== 'discussionbridge_service_author') throw new RuntimeException('Unexpected option read.');
    return $GLOBALS['service_context']['option'] ?? $default;
}
function get_user_by(string $field, string $value): WP_User|false {
    if ($field !== 'login') throw new RuntimeException('Unexpected user lookup.');
    return $GLOBALS['service_context']['users'][$value] ?? false;
}
function user_can(WP_User $user, string $capability): bool {
    if ($capability !== 'publish_posts') throw new RuntimeException('Unexpected capability read.');
    return $user->publish_posts;
}

require $source;
use DiscussionBridge\WordPress\Settings;

$tests = [];
$test = static function (string $name, callable $body) use (&$tests): void { $tests[] = [$name, $body]; };
$expect = static function (bool $condition, string $why): void { if (!$condition) throw new RuntimeException($why); };
$fresh = static function (string $option = 'service'): void {
    $GLOBALS['service_context'] = ['option' => $option, 'users' => ['service' => new WP_User(7, 'service')], 'notices' => []];
};
$lookup = static function (string $expected, int $id, bool $invalid) use ($context, $expect): void {
    $GLOBALS['service_context']['notices'] = [];
    $expect(Settings::sanitize_service_author($GLOBALS['service_context']['option']) === $expected, 'Direct service-author validation changed.');
    $expect(Settings::service_author_username() === $expected, 'Configured service-author lookup changed.');
    $expect(Settings::service_author_id() === $id, 'Configured service-author authority changed.');
    $notices = $GLOBALS['service_context']['notices'];
    $expect(count($notices) === ($invalid && $context === 'admin' ? 3 : 0), 'Native validation notice count changed.');
    foreach ($notices as $notice) $expect($notice === [Settings::SERVICE_AUTHOR_OPTION, 'invalid_service_author',
        'DiscussionBridge service author must be an existing WordPress user allowed to publish posts.'], 'Native validation notice changed.');
    $expect(function_exists('add_settings_error') === ($context === 'admin'), 'Context unexpectedly loaded or lost the admin helper.');
};

$test('keeps the admin notice helper absent from frontend process', static function () use ($context, $expect): void {
    $expect(function_exists('add_settings_error') === ($context === 'admin'), 'Wrong native context fixture.');
});
$test('retains a configured publishing service author', static function () use ($fresh, $lookup): void {
    $fresh(); $lookup('service', 7, false);
});
$test('missing service author refuses without requiring admin helpers', static function () use ($fresh, $lookup): void {
    $fresh('missing'); $lookup('', 0, true);
});
$test('deleted service author loses authority without requiring admin helpers', static function () use ($fresh, $lookup): void {
    $fresh(); $lookup('service', 7, false);
    unset($GLOBALS['service_context']['users']['service']); $lookup('', 0, true);
});
$test('service author capability loss refuses without requiring admin helpers', static function () use ($fresh, $lookup): void {
    $fresh(); $lookup('service', 7, false);
    $GLOBALS['service_context']['users']['service']->publish_posts = false; $lookup('', 0, true);
});
$test('blank service author remains unconfigured without a validation notice', static function () use ($fresh, $lookup): void {
    $fresh(''); $lookup('', 0, false);
});

$passed = 0; $failed = 0;
foreach ($tests as [$name, $body]) {
    try { $body(); $passed++; fwrite(STDOUT, 'ok - ' . $name . "\n"); }
    catch (Throwable $error) { $failed++; fwrite(STDERR, 'not ok - ' . $name . ': ' . $error::class . ': ' . $error->getMessage() . "\n"); }
}
fwrite(STDOUT, json_encode(['context' => $context, 'tests' => count($tests), 'passed' => $passed, 'failed' => $failed,
    'source_sha256' => hash_file('sha256', $source),
    'limits' => 'Actual Settings with explicit user/option fixtures; installed cron, SQL and session evidence is separate'], JSON_THROW_ON_ERROR) . "\n");
exit($failed === 0 ? 0 : 1);
