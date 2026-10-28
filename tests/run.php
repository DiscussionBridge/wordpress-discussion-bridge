<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use DiscussionBridge\WordPress\NativeWriter;
use DiscussionBridge\WordPress\ProtectedJournal;
use DiscussionBridge\WordPress\NativeContent;
use DiscussionBridge\WordPress\NativeSourceSnapshot;
use DiscussionBridge\WordPress\NativeDeletion;

$tests = [];
function test(string $name, callable $body): void { $GLOBALS['tests'][] = [$name, $body]; }
function expect(bool $condition, string $reason = 'assertion failed'): void { if (!$condition) throw new RuntimeException($reason); }

function native_deletion(?callable $capture = null, ?callable $confirm = null): array {
    $writer = new NativeWriter(); $journal = new ProtectedJournal('current-native-source:1');
    $capture ??= fn($id, $post, $guard) => $journal->write((object) ['native_id' => (string) $id, 'phase' => 'captured', 'fixture_receipt' => 'immutable-native-fixture'], $guard);
    $confirm ??= function ($id, $post, $guard) use ($journal): bool {
        $tree = $journal->read(); if (!$tree instanceof stdClass) return false; $tree->phase = 'completed_event';
        return $journal->write($tree, $guard);
    };
    return [new NativeDeletion($writer, fn($id, $post): bool => ($GLOBALS['native']['owned'][$id] ?? false) === true, $capture, $confirm), $writer, $journal];
}

/** Isolated reproduction of documented hook ordering; not an installed WordPress run. */
function native_core_delete(NativeDeletion $deletion, int $id, ?callable $during = null, bool $sql_failure = false): WP_Post|false {
    $post = $GLOBALS['native']['posts'][$id];
    if ($deletion->before(null, $post, true) !== null) return false;
    if ($during !== null) $during();
    unset($GLOBALS['native']['meta'][$id]); // Core removes metadata before its post-row SQL.
    if ($sql_failure) return false;
    unset($GLOBALS['native']['posts'][$id]); $deletion->completed($id, $post);
    clean_post_cache($id); $deletion->after($id, $post); return $post;
}

test('native writer reuses one site lock and nested work cannot release its parent connection', function (): void {
    native_reset(); $writer = new NativeWriter();
    $writer->run(function ($outer) use ($writer): void {
        expect($outer()); $writer->run(fn($inner) => expect($inner())); expect($outer());
        expect(is_wp_error($writer->acquire(true)), 'exclusive source deletion must not nest inside an active worker');
    });
    expect($GLOBALS['native']['locks'] === []);
});

test('reconnected or replaced native connection loses writer authority and cannot release another physical lock', function (): void {
    native_reset(); $writer = new NativeWriter(); $lock = $writer->acquire(); expect(!is_wp_error($lock));
    $old = $GLOBALS['wpdb']; $GLOBALS['wpdb'] = new NativeTestDb('202'); expect(!$lock->valid());
    expect(is_wp_error($writer->acquire())); $lock->release(); expect($GLOBALS['native']['locks'] === []);
    $GLOBALS['wpdb'] = $old; $again = $writer->acquire(); $old->connection = '303'; $GLOBALS['native']['locks'][$writer->name()] = '303';
    expect(!$again->valid()); $again->release(); expect($GLOBALS['native']['locks'][$writer->name()] === '303');
});

test('protected native journal encrypts exact receipt tree and rejects cross-context copy or failed write without cache success', function (): void {
    native_reset(); $a = new ProtectedJournal('current-native:1'); $b = new ProtectedJournal('current-native:2');
    $tree = (object) ['opaque_predecessor' => 'current-native-edition', 'exact_body' => (object) ['text' => '秘密 é', 'nullable' => null], 'items' => []];
    expect($a->write($tree, fn() => true) && $a->read() == $tree);
    expect(!str_contains(get_option($a->option_name()), 'current-native-edition'));
    update_option($b->option_name(), get_option($a->option_name())); expect(is_wp_error($b->read()));
    $GLOBALS['native']['fail_option'] = true; expect(!$a->write((object) ['changed' => true], fn() => true) && $a->read() == $tree);
    expect(!$a->write($tree, fn() => false));
});

test('native complete content and UTC observations retain full source before independently budgeted UTF8 prefix', function (): void {
    native_reset(); $post = $GLOBALS['native']['posts'][1]; $complete = '<p>' . str_repeat('é native content & ', 6000) . '</p>';
    $post->post_content = $complete . '<script>document.body.dataset.answer="42";</script>';
    $limited = NativeSourceSnapshot::capture(1); expect(is_wp_error($limited) && $limited->get_error_code() === 'discussionbridge_native_function_preservation');
    expect(str_contains($post->post_content, '<script>'));
    $post->post_content = $complete;
    $snapshot = NativeSourceSnapshot::capture(1); expect(is_array($snapshot));
    expect($snapshot['created_at'] === '2026-10-14T03:04:05.000Z' && $snapshot['updated_at'] === null && $snapshot['post_id'] === '1');
    expect($snapshot['complete_bytes'] > 49152 && $snapshot['complete_sha256'] === hash('sha256', $snapshot['complete_html']) && !str_contains($snapshot['complete_html'], '<script'));
    $prefix = NativeContent::article_prefix($snapshot['complete_html'], 1200);
    expect(is_string($prefix) && strlen($prefix) <= 1200 && preg_match('//u', $prefix) === 1 && !str_contains($prefix, 'Read More'));
    expect($post->post_content !== $prefix && $snapshot['author']['native_id'] === '7');
    $post->post_status = 'draft'; expect(is_wp_error(NativeSourceSnapshot::capture(1)));
});

test('owned source deletion under another native writer is vetoed before metadata loss while unrelated native deletion passes', function (): void {
    native_reset(); [$deletion, $writer, $journal] = native_deletion(); $database = $GLOBALS['wpdb'];
    $GLOBALS['wpdb'] = new NativeTestDb('202'); $other = $writer->acquire(); $GLOBALS['wpdb'] = $database;
    expect(native_core_delete($deletion, 1) === false && isset($GLOBALS['native']['posts'][1], $GLOBALS['native']['meta'][1]) && $journal->read() === null);
    expect($deletion->attention()->get_error_code() === 'discussionbridge_native_writer_busy');
    $post = new WP_Post(); $post->ID = 2; $GLOBALS['native']['posts'][2] = $post; $GLOBALS['native']['meta'][2] = ['ordinary' => true];
    expect(native_core_delete($deletion, 2) instanceof WP_Post && !isset($GLOBALS['native']['posts'][2]));
    $GLOBALS['wpdb'] = new NativeTestDb('202'); $other->release(); $GLOBALS['wpdb'] = $database;
});

test('failed or throwing durable deletion capture vetoes core deletion and releases the shared writer', function (): void {
    foreach ([false, 'throw'] as $failure) {
        native_reset(); [$deletion] = native_deletion(function () use ($failure): bool { if ($failure === 'throw') throw new RuntimeException('fixture capture failed'); return false; });
        expect(native_core_delete($deletion, 1) === false && isset($GLOBALS['native']['posts'][1], $GLOBALS['native']['meta'][1]));
        expect($deletion->attention()->get_error_code() === 'discussionbridge_native_delete_capture' && $GLOBALS['native']['locks'] === []);
    }
});

test('successful owned deletion durably captures before native metadata removal and holds same writer through actual confirmation', function (): void {
    native_reset(); [$deletion, $writer, $journal] = native_deletion(); $database = $GLOBALS['wpdb'];
    expect(native_core_delete($deletion, 1, function () use ($journal, $writer, $database): void {
        expect($journal->read()->phase === 'captured' && isset($GLOBALS['native']['meta'][1]));
        $GLOBALS['wpdb'] = new NativeTestDb('202'); expect(is_wp_error($writer->acquire())); $GLOBALS['wpdb'] = $database;
    }) instanceof WP_Post);
    expect($journal->read()->phase === 'completed_event' && $journal->read()->fixture_receipt === 'immutable-native-fixture');
    expect(!isset($GLOBALS['native']['posts'][1], $GLOBALS['native']['meta'][1]) && $GLOBALS['native']['locks'] === [] && $deletion->attention() === null);
});

test('same-request nested owned deletion is vetoed instead of overwriting an active source worker journal', function (): void {
    native_reset(); [$deletion, $writer, $journal] = native_deletion();
    $writer->run(function ($guard) use ($deletion): void { expect($guard()); expect(native_core_delete($deletion, 1) === false && $guard()); });
    expect($journal->read() === null && isset($GLOBALS['native']['meta'][1]) && $GLOBALS['native']['locks'] === []);
});

test('native SQL failure after metadata removal retains predelete receipt without invented confirmation rollback or duplicate post', function (): void {
    native_reset(); [$deletion, $writer, $journal] = native_deletion();
    expect(native_core_delete($deletion, 1, null, true) === false);
    expect(isset($GLOBALS['native']['posts'][1]) && !isset($GLOBALS['native']['meta'][1]) && $journal->read()->phase === 'captured');
    expect($GLOBALS['native']['locks'] !== []); $deletion->shutdown();
    expect($GLOBALS['native']['locks'] === [] && $deletion->attention()->get_error_code() === 'discussionbridge_native_delete_unresolved' && count($GLOBALS['native']['posts']) === 1);
});

test('failed completion persistence retains captured identity and reports actual deletion separately from durable confirmation', function (): void {
    native_reset(); [$deletion, $writer, $journal] = native_deletion(null, fn() => false);
    expect(native_core_delete($deletion, 1) instanceof WP_Post);
    expect($journal->read()->phase === 'captured' && !isset($GLOBALS['native']['posts'][1]) && $GLOBALS['native']['locks'] === []);
    expect($deletion->attention()->get_error_code() === 'discussionbridge_native_delete_confirmation');
});

test('connection replacement after deletion capture cannot supply false confirmation and shutdown releases original lock only', function (): void {
    native_reset(); [$deletion, $writer, $journal] = native_deletion(); $database = $GLOBALS['wpdb'];
    expect(native_core_delete($deletion, 1, function (): void { $GLOBALS['wpdb'] = new NativeTestDb('202'); }) instanceof WP_Post);
    expect($journal->read()->phase === 'captured' && $deletion->attention()->get_error_code() === 'discussionbridge_native_delete_confirmation');
    expect($GLOBALS['native']['locks'] === []); $GLOBALS['wpdb'] = $database;
});

test('supported native hook registration and previous veto overrides do not create a false deletion intent', function (): void {
    native_reset(); [$deletion, $writer, $journal] = native_deletion(); $deletion->register();
    expect($GLOBALS['native']['hooks'][0][0] === 'pre_delete_post' && $GLOBALS['native']['hooks'][0][2] === PHP_INT_MAX && $GLOBALS['native']['hooks'][0][3] === 3);
    expect($deletion->before(false, $GLOBALS['native']['posts'][1], true) === false && $journal->read() === null && $GLOBALS['native']['locks'] === []);
    $override = new WP_Post(); expect($deletion->before($override, $GLOBALS['native']['posts'][1], true) === $override);
});

$passed = 0;
foreach ($tests as [$name, $body]) {
    try { $body(); $passed++; fwrite(STDOUT, "ok - {$name}\n"); }
    catch (Throwable $error) { fwrite(STDERR, "not ok - {$name}: {$error->getMessage()}\n"); exit(1); }
}
fwrite(STDOUT, "{$passed}/" . count($tests) . " native tests passed\n");
