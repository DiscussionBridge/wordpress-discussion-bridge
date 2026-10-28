<?php

declare(strict_types=1);

// Real typed client, continuation and encrypted journal using the existing
// in-memory WordPress/HTTP fixture. No WordPress server/database qualification.
require __DIR__.'/integration-bootstrap.php';

use DiscussionBridge\WordPress\CmsArtifacts;
use DiscussionBridge\WordPress\CmsClient;
use DiscussionBridge\WordPress\CmsJournal;
use DiscussionBridge\WordPress\CmsSourceContinuation;
use DiscussionBridge\WordPress\Settings;

$cases = [];
function feed_test(string $name, callable $test): void { $GLOBALS['cases'][] = [$name, $test]; }
function feed_expect(bool $ok, string $message = 'assertion failed'): void { if (! $ok) throw new RuntimeException($message); }
function feed_copy(mixed $value): mixed { return unserialize(serialize($value)); }
function feed_event(string $sequence, int $id, string $sourceId = 'source:a'): stdClass {
    $fixtures = json_decode(file_get_contents(__DIR__.'/fixtures/v4-author-types.json'), false, 512, JSON_THROW_ON_ERROR);
    foreach ($fixtures->fixtures as $fixture) if ($fixture->id === 'initial_feed') $source = $fixture->value->items[0]->source;
    $source->source_id = $sourceId;
    return (object) ['event_type' => 'publication', 'event_id' => sprintf('11111111-1111-4111-8111-%012d', $id),
        'event_sequence' => $sequence, 'source' => $source];
}
function feed_page(array $events, string $stream = 'changes', ?string $next = null, ?string $checkpoint = 'checkpoint_done'): stdClass {
    return (object) ['stream' => $stream, 'items' => $events, 'next_cursor' => $next, 'checkpoint_cursor' => $checkpoint];
}
function feed_open(string $stream = 'changes'): array {
    dbt_reset(); update_option(Settings::CONNECTION_SECRET_OPTION, Settings::sanitize_connection_secret(str_repeat('a', 43)));
    $journal = new CmsJournal(); $state = CmsSourceContinuation::fresh();
    $state->stream = $stream; $state->cursor = $stream === 'changes' ? 'checkpoint_before' : null;
    feed_expect($journal->write($state, fn() => true));
    return [new CmsClient(), $journal, $state];
}
function feed_mock(stdClass $state, stdClass $page): void {
    $query = ['stream' => $state->stream]; if ($state->cursor !== null) $query['cursor'] = $state->cursor;
    $url = Settings::server_url().CmsArtifacts::codec()->route('V4-06')['path'].'?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    $GLOBALS['dbt']['responses'][$url] = dbt_response(200, $page);
}
function feed_commit(stdClass $state, CmsJournal $journal): void {
    $state->events = []; // The normal coordinator queues every event before this call.
    feed_expect(CmsSourceContinuation::finish_page($state, fn() => $journal->write($state, fn() => true)) === true);
}
function feed_rejected(mixed $result): void {
    feed_expect($result instanceof WP_Error && $result->get_error_code() === 'discussionbridge_cms_source_inconsistent', 'Expected source-continuation inconsistency');
}

$increasing = [['9', '10'], ['9007199254740992', '9007199254740993'], ['9223372036854775806', '9223372036854775807']];
foreach ($increasing as [$first, $second]) {
    feed_test('increasing within page '.$first.' -> '.$second, function () use ($first, $second): void {
        [$client, $journal, $state] = feed_open(); feed_mock($state, feed_page([feed_event($first, 1), feed_event($second, 2)]));
        feed_expect(CmsSourceContinuation::intake($client, $state, fn() => $journal->write($state, fn() => true)) === true);
        $saved = $journal->read(); feed_expect($saved->cursor === 'checkpoint_before' && count($saved->events) === 2);
        feed_rejected(CmsSourceContinuation::finish_page($saved, fn() => true));
        feed_commit($state, $journal); $saved = $journal->read();
        feed_expect($saved->last_sequence === $second && $saved->checkpoint === 'checkpoint_done');
    });
    feed_test('increasing across page and journal reload '.$first.' -> '.$second, function () use ($first, $second): void {
        [$client, $journal, $state] = feed_open();
        feed_mock($state, feed_page([feed_event($first, 1)], next: 'page_two', checkpoint: 'checkpoint_one'));
        feed_expect(CmsSourceContinuation::intake($client, $state, fn() => $journal->write($state, fn() => true)) === true); feed_commit($state, $journal);
        $journal = new CmsJournal(); $state = $journal->read(); feed_expect($state->cursor === 'page_two');
        feed_mock($state, feed_page([feed_event($second, 2)]));
        feed_expect(CmsSourceContinuation::intake($client, $state, fn() => $journal->write($state, fn() => true)) === true); feed_commit($state, $journal);
        feed_expect($journal->read()->last_sequence === $second);
    });
}

$invalid = [['10', '10'], ['10', '9'], ['9007199254740993', '9007199254740993'],
    ['9007199254740993', '9007199254740992'], ['9223372036854775807', '9223372036854775806']];
foreach ($invalid as [$first, $second]) {
    feed_test('invalid second event leaves whole page uncommitted '.$first.' -> '.$second, function () use ($first, $second): void {
        [$client, $journal, $state] = feed_open(); $before = $journal->read();
        feed_mock($state, feed_page([feed_event($first, 1), feed_event($second, 2)]));
        feed_rejected(CmsSourceContinuation::intake($client, $state, fn() => $journal->write($state, fn() => true)));
        feed_expect($journal->read() == $before && $state == $before, 'Rejected page altered state or checkpoint');
    });
    feed_test('nonincreasing page after reload retains prior progress '.$first.' -> '.$second, function () use ($first, $second): void {
        [$client, $journal, $state] = feed_open();
        feed_mock($state, feed_page([feed_event($first, 1)], next: 'page_two', checkpoint: 'checkpoint_one'));
        feed_expect(CmsSourceContinuation::intake($client, $state, fn() => $journal->write($state, fn() => true)) === true); feed_commit($state, $journal);
        $journal = new CmsJournal(); $state = $journal->read(); $before = feed_copy($state);
        feed_mock($state, feed_page([feed_event($second, 2)]));
        feed_rejected(CmsSourceContinuation::intake($client, $state, fn() => $journal->write($state, fn() => true)));
        feed_expect($journal->read() == $before && $state == $before);
    });
}

feed_test('initial source-ID order permits descending sequences across pages', function (): void {
    [$client, $journal, $state] = feed_open('initial');
    feed_mock($state, feed_page([feed_event('100', 1, 'source:a')], 'initial', 'initial_two', null));
    feed_expect(CmsSourceContinuation::intake($client, $state, fn() => $journal->write($state, fn() => true)) === true); feed_commit($state, $journal);
    $state = (new CmsJournal())->read(); feed_mock($state, feed_page([feed_event('3', 2, 'source:b')], 'initial'));
    feed_expect(CmsSourceContinuation::intake($client, $state, fn() => $journal->write($state, fn() => true)) === true); feed_commit($state, $journal);
    feed_expect($journal->read()->last_sequence === null && $journal->read()->stream === 'changes');
});

feed_test('committed page resumes after reload without a second request', function (): void {
    [$client, $journal, $state] = feed_open(); feed_mock($state, feed_page([feed_event('10', 1)]));
    feed_expect(CmsSourceContinuation::intake($client, $state, fn() => $journal->write($state, fn() => true)) === true);
    $state = (new CmsJournal())->read(); $before = feed_copy($state); $requests = count($GLOBALS['dbt']['requests']);
    feed_expect(CmsSourceContinuation::intake($client, $state, fn() => throw new RuntimeException('Committed page unexpectedly saved again')) === true);
    feed_expect(count($GLOBALS['dbt']['requests']) === $requests && $state == $before && count($state->events) === 1);
});

foreach ([false, true] as $changed) feed_test(($changed ? 'changed' : 'exact').' old-sequence replay rejects without advancing', function () use ($changed): void {
    [$client, $journal, $state] = feed_open(); $event = feed_event('10', 1); feed_mock($state, feed_page([$event]));
    feed_expect(CmsSourceContinuation::intake($client, $state, fn() => $journal->write($state, fn() => true)) === true); feed_commit($state, $journal);
    $state = $journal->read(); $before = feed_copy($state); if ($changed) $event->source->title .= ' changed'; feed_mock($state, feed_page([$event]));
    feed_rejected(CmsSourceContinuation::intake($client, $state, fn() => $journal->write($state, fn() => true)));
    feed_expect($journal->read() == $before);
});

feed_test('failed page restart uses original cursor and stages all corrected entries', function (): void {
    [$client, $journal, $state] = feed_open(); feed_mock($state, feed_page([feed_event('10', 1), feed_event('9', 2)]));
    feed_rejected(CmsSourceContinuation::intake($client, $state, fn() => $journal->write($state, fn() => true)));
    $journal = new CmsJournal(); $state = $journal->read(); feed_expect($state->cursor === 'checkpoint_before');
    feed_mock($state, feed_page([feed_event('9', 2), feed_event('10', 1)]));
    feed_expect(CmsSourceContinuation::intake($client, $state, fn() => $journal->write($state, fn() => true)) === true);
    feed_expect(count($journal->read()->events) === 2 && $GLOBALS['dbt']['requests'][0][0] === $GLOBALS['dbt']['requests'][1][0]);
    feed_commit($state, $journal); feed_expect($journal->read()->checkpoint === 'checkpoint_done');
});

feed_test('refused durable save cannot advance restart checkpoint', function (): void {
    [$client, $journal, $state] = feed_open(); $before = $journal->read(); feed_mock($state, feed_page([feed_event('10', 1)]));
    $GLOBALS['dbt']['option_write_callback'] = fn() => false;
    $error = CmsSourceContinuation::intake($client, $state, fn() => $journal->write($state, fn() => true));
    feed_expect($error instanceof WP_Error && $error->get_error_code() === 'discussionbridge_stale_worker');
    $GLOBALS['dbt']['option_write_callback'] = null;
    feed_expect($journal->read() == $before);
    $state = $journal->read(); feed_expect(CmsSourceContinuation::intake($client, $state, fn() => $journal->write($state, fn() => true)) === true);
});

$passed = 0; $failures = [];
foreach ($cases as [$name, $test]) {
    try { $test(); $passed++; echo 'ok - '.$name.PHP_EOL; }
    catch (Throwable $error) { $failures[] = $name; fwrite(STDERR, 'FAIL - '.$name.': '.get_class($error).': '.$error->getMessage().PHP_EOL); }
}
echo json_encode(['php' => PHP_VERSION, 'tests' => count($cases), 'passed' => $passed, 'failed' => count($failures),
    'harness' => 'existing reduced WordPress fixture; real client/codec/continuation/encrypted journal; in-memory options and HTTP',
    'native_qualified' => false], JSON_THROW_ON_ERROR).PHP_EOL;
exit($failures === [] ? 0 : 1);
