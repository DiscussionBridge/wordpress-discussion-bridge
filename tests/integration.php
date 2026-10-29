<?php

declare(strict_types=1);

require __DIR__ . '/integration-bootstrap.php';

use DiscussionBridge\WordPress\CmsArtifacts;
use DiscussionBridge\WordPress\CmsClient;
use DiscussionBridge\WordPress\CmsJournal;
use DiscussionBridge\WordPress\CmsMutation;
use DiscussionBridge\WordPress\CmsSourceEdition;
use DiscussionBridge\WordPress\NativeContent;
use DiscussionBridge\WordPress\NativeSourceSnapshot;
use DiscussionBridge\WordPress\CmsSourceMove;
use DiscussionBridge\WordPress\CmsSourceOwnership;
use DiscussionBridge\WordPress\CmsSourceNativeMove;
use DiscussionBridge\WordPress\CmsSourceArticleProfile;
use DiscussionBridge\WordPress\CmsSourcePublicVerifier;
use DiscussionBridge\WordPress\CmsSourcePublisher;
use DiscussionBridge\WordPress\CmsSourceWithdrawal;
use DiscussionBridge\WordPress\CmsSourceRecovery;
use DiscussionBridge\WordPress\Admin;
use DiscussionBridge\WordPress\Publisher;
use DiscussionBridge\WordPress\Presentation;
use DiscussionBridge\WordPress\Materializer;
use DiscussionBridge\WordPress\CmsSourceContinuation;
use DiscussionBridge\WordPress\CmsNativeFence;
use DiscussionBridge\WordPress\CmsNativeSelection;
use DiscussionBridge\WordPress\CmsNativeWriter;
use DiscussionBridge\WordPress\CmsCoordinator;
use DiscussionBridge\WordPress\CmsLocalLimitation;
use DiscussionBridge\WordPress\CmsCatalog;
use DiscussionBridge\WordPress\CmsPresentation;
use DiscussionBridge\WordPress\CmsPublicVerifier;
use DiscussionBridge\WordPress\FromSynchronizer;
use DiscussionBridge\WordPress\Settings;
use DiscussionBridge\CmsV4\NoContent;

$tests = [];
function test(string $name, callable $case): void { $GLOBALS['tests'][] = [$name, $case]; }
function expect(bool $condition, string $message = 'assertion failed'): void { if (!$condition) throw new RuntimeException($message); }
function fixture(string $id): stdClass {
    $items = json_decode(file_get_contents(__DIR__ . '/fixtures/v4-author-types.json'))->fixtures;
    foreach ($items as $item) if ($item->id === $id) {
        return $item->value;
    }
    throw new RuntimeException('Unknown current author fixture');
}
function configured(): void {
    dbt_reset(); update_option(Settings::CONNECTION_SECRET_OPTION, Settings::sanitize_connection_secret(str_repeat('a', 43)));
    $GLOBALS['dbt']['posts'][1] = new WP_Post(1, 'publish', 'post', 'Actual native title', '<p>Actual native article é</p>', 9, 'native-source', '2026-10-14 03:04:05', '0000-00-00 00:00:00');
    $GLOBALS['dbt']['meta'][1] = ['_discussionbridge_enabled' => '1'];
}
function connection(): stdClass {
    $connection = fixture('connection'); $connection->connection_id = Settings::connection_id(); $connection->profile = 'wordpress';
    $connection->destination_origin = home_url('/'); $connection->publication_lane = Settings::lane(); return $connection;
}
function mock(string $route, mixed $body, int $status = 200, string $query = '', array $path = []): string {
    $url = Settings::server_url() . CmsArtifacts::codec()->route($route)['path'];
    foreach ($path as $key => $value) $url = str_replace('{' . $key . '}', rawurlencode($value), $url);
    if ($query !== '') $url .= '?' . $query;
    $GLOBALS['dbt']['responses'][$url] = is_callable($body) || $body instanceof WP_Error ? $body : dbt_response($status, $body); return $url;
}
function source_receipt(stdClass $body): stdClass {
    return (object) ['outcome' => 'created', 'reason' => 'record_created', 'resource_id' => '11111111-1111-4111-8111-000000000002',
        'topic_id' => '8', 'topic_url' => Settings::server_url() . '/t/native/8', 'direction' => 'to_discourse',
        'source_revision' => $body->source_revision, 'synchronized_at' => '2026-10-14T04:00:00.000Z', 'core_fallback' => false];
}
function establish_source(): stdClass {
    mock('V4-01', connection()); mock('V4-03', (object) ['items' => [], 'next_cursor' => null]);
    mock('V4-02', function ($url, $args): array { return dbt_response(201, source_receipt(json_decode($args['body'])->bridge_record)); });
    $result = FromSynchronizer::serialized(fn($guard) => CmsSourcePublisher::step(1, $guard));
    expect($result === 'healthy', 'source creation failed: ' . (is_wp_error($result) ? $result->get_error_code() . ' ' . json_encode($result->get_error_data()) : (string) $result));
    return (new CmsJournal(1))->read();
}

test('one exact V4 SDK preserves31 routes plus gated recovery extension and rejects removed adoption grammar', function (): void {
    configured(); $codec = CmsArtifacts::codec(); expect($codec->route('V4-13')['path'] === '/discussion-bridge/v4/work/claim.json');
    expect(!$codec->hasType('MappingAdopt') && CmsArtifacts::matches(CmsArtifacts::binding()));
    expect($codec->route('V4-32')['path'] === '/discussion-bridge/v4/attention-recovery.json' && $codec->route('V4-36')['response'] === 'Lease');
    try { $codec->route('V2-14'); expect(false); } catch (RuntimeException) {}
    $bad = CmsArtifacts::binding(); $bad->schema_sha256 = str_repeat('0', 64); expect(!CmsArtifacts::matches($bad));
});

test('typed transport is current HTTPS scoped and no unsupported-direction request dispatches', function (): void {
    configured(); mock('V4-01', connection()); $client = new CmsClient(null, 'to'); expect(is_object($client->request('V4-01')));
    [$url, $args] = $GLOBALS['dbt']['requests'][0]; expect(str_contains($url, '/v4/') && $args['redirection'] === 0 && $args['headers']['Accept-Encoding'] === 'identity');
    expect($args['headers']['X-DiscussionBridge-Secret'] === Settings::connection_secret() && !str_contains($url, Settings::connection_secret()));
    expect(is_wp_error($client->request('V4-13', ['body' => (object) ['mutation_id' => wp_generate_uuid4()]])) && count($GLOBALS['dbt']['requests']) === 1);
    $GLOBALS['dbt']['options'][Settings::CONNECTION_ID_OPTION] = 'dbc_bbbbbbbbbbbbbbbbbbbbbbbb'; expect(is_wp_error($client->request('V4-01')));
});

test('strict V4 transport distinguishes bodyless204 from JSONnull and rejects duplicate response keys', function (): void {
    configured(); $client = new CmsClient(); $url = mock('V4-13', '', 204);
    $options = ['body' => (object) ['mutation_id' => wp_generate_uuid4()]]; expect($client->request('V4-13', $options) === NoContent::VALUE);
    $GLOBALS['dbt']['responses'][$url] = dbt_response(204, 'null'); expect(is_wp_error($client->request('V4-13', $options)));
    mock('V4-01', '{"connection_id":"x","connection_id":"y"}'); expect(is_wp_error($client->request('V4-01')));
});

test('both native directions bind configured service identity before any authenticated dispatch', function (): void {
    configured(); $client = new CmsClient(); mock('V4-01', connection());
    update_option(Settings::SERVER_URL_OPTION, 'https://new-service.example');
    expect(is_wp_error($client->request('V4-01')) && $GLOBALS['dbt']['requests'] === []);
});

test('From Policy off does not disable current scoped To source authority or mutate its exact pending request', function (): void {
    configured(); mock('V4-01', connection()); mock('V4-03', (object) ['items' => [], 'next_cursor' => null]); mock('V4-02', new WP_Error('timeout'));
    FromSynchronizer::serialized(fn($guard) => CmsSourcePublisher::step(1, $guard)); $pending = (new CmsJournal(1))->read()->pending;
    $connection = connection(); $connection->policy->enabled = false; mock('V4-01', $connection);
    $count = count(array_filter($GLOBALS['dbt']['requests'], fn($r) => $r[1]['method'] === 'POST'));
    expect(is_wp_error(FromSynchronizer::serialized(fn($guard) => CmsSourcePublisher::step(1, $guard))));
    expect((new CmsJournal(1))->read()->pending == $pending && count(array_filter($GLOBALS['dbt']['requests'], fn($r) => $r[1]['method'] === 'POST')) === $count + 1);
});

test('uncertain typed mutation retains exact durable ID tree and predecessor before dispatch', function (): void {
    configured(); $client = new CmsClient(null, 'to'); $capture = CmsSourceEdition::capture(1, $client); expect(is_array($capture));
    $body = CmsSourceEdition::delivery($capture, $client, null); $state = (object) ['pending' => null]; $store = new CmsJournal(1);
    $save = fn(): bool => $store->write($state, fn() => true); $url = mock('V4-02', new WP_Error('timeout'));
    $options = ['body' => (object) ['bridge_record' => $body]]; expect(is_wp_error(CmsMutation::perform($client, 'V4-02', $options, $state, $save)));
    expect($store->read()->pending == $state->pending && $state->pending->options->body->bridge_record->expected_source_revision === null);
    $first = $GLOBALS['dbt']['requests'][0][1]['body']; $GLOBALS['dbt']['responses'][$url] = dbt_response(201, source_receipt($body));
    $replayed = CmsMutation::perform($client, 'V4-02', $options, $state, $save);
    expect($replayed instanceof stdClass && $GLOBALS['dbt']['requests'][1][1]['body'] === $first);
    $changed = clone $body; $changed->title = 'Changed'; expect(is_wp_error(CmsMutation::perform($client, 'V4-02', ['body' => (object) ['bridge_record' => $changed]], $state, $save)) && count($GLOBALS['dbt']['requests']) === 2);
});

test('actual native complete content nullable dates and author provenance determine edition independent of configuration', function (): void {
    configured(); $client = new CmsClient(null, 'to'); $one = CmsSourceEdition::capture(1, $client); expect(is_array($one));
    expect($one['body']->source_updated_at === null && $one['body']->source_created_at === '2026-10-14T03:04:05.000Z');
    expect($one['body']->source_content_sha256 === hash('sha256', $one['complete_content']) && count($one['body']->source_authors) === 1 && !isset($one['body']->source_authors[0]->profile_url));
    update_option(Settings::COMMENTS_MODE_OPTION, 'simple'); $two = CmsSourceEdition::capture(1, $client); expect($one['body']->source_revision === $two['body']->source_revision);
    $GLOBALS['dbt']['posts'][1]->post_content = '<p>A genuine new article</p>'; $three = CmsSourceEdition::capture(1, $client); expect($one['body']->source_revision !== $three['body']->source_revision);
});

test('oversized source and envelope-only overflow use owned generated ReadMore with truthful full count/hash', function (): void {
    configured(); $client = new CmsClient(null, 'to'); $GLOBALS['dbt']['posts'][1]->post_content = '<p>' . str_repeat('é source ', 9000) . '</p>';
    $capture = CmsSourceEdition::capture(1, $client);
    $prefix = NativeContent::article_prefix($capture['complete_content'], 49152 - strlen($client->codec()->generatedReadMore($capture['body']->canonical_url)));
    expect(is_string($prefix), 'native oversized prefix must be representable');
    $direct = $client->codec()->createGeneratedExcerpt($capture['body'], $capture['complete_content'], $prefix);
    expect($direct instanceof stdClass, 'the shared SDK must accept the native oversized prefix');
    $body = CmsSourceEdition::delivery($capture, $client, null);
    expect($body instanceof stdClass, is_wp_error($body) ? 'oversized delivery: ' . $body->get_error_code() : 'oversized delivery is not a record');
    expect($body->representation->kind === 'generated_excerpt' && $body->source_content_bytes > 49152 && $body->source_content_sha256 === hash('sha256', $capture['complete_content']), 'oversized delivery must retain the complete source count/hash');
    expect(strlen($body->content_html) <= 49152 && str_ends_with($body->content_html, $client->codec()->generatedReadMore($body->canonical_url)), 'oversized delivery must fit and end with the owned Read More');
    $GLOBALS['dbt']['posts'][1]->post_content = '<p>' . str_repeat('"', 40000) . '</p>'; $capture = CmsSourceEdition::capture(1, $client);
    $body = CmsSourceEdition::delivery($capture, $client, null);
    expect($body instanceof stdClass, is_wp_error($body) ? 'envelope-only delivery: ' . $body->get_error_code() : 'envelope-only delivery is not a record');
    expect($body->source_content_bytes <= 49152 && $body->representation->selection_full_request_bytes > 65536, 'envelope-only fixture must exceed the full JSON envelope, not the content byte cap');
    expect(strlen($client->codec()->encodeJson((object) ['mutation_id' => wp_generate_uuid4(), 'bridge_record' => $body])) <= 65536, 'generated selection must fit the JSON envelope');
});

test('same genuine edition replays historical generated selection and changed ordinary source uses accepted CAS', function (): void {
    configured(); $GLOBALS['dbt']['posts'][1]->post_content = '<p>' . str_repeat('native full source ', 7000) . '</p>'; $state = establish_source();
    $original = $state->current_record; $original_receipt = $state->receipt; $ids = [$original_receipt->resource_id, $original_receipt->topic_id];
    mock('V4-02', function ($url, $args) use ($original, $original_receipt): array {
        $body = json_decode($args['body'])->bridge_record; expect($body->content_html === $original->content_html && $body->representation == $original->representation && $body->expected_source_revision === $original_receipt->source_revision);
        $receipt = clone $original_receipt; $receipt->outcome = 'resolved'; $receipt->reason = 'already_resolved'; return dbt_response(200, $receipt);
    });
    expect(FromSynchronizer::serialized(fn($guard) => CmsSourcePublisher::step(1, $guard)) === 'healthy');
    $GLOBALS['dbt']['posts'][1]->post_content = '<p>Genuine next source edition</p>';
    mock('V4-02', function ($url, $args) use ($original_receipt): array {
        $body = json_decode($args['body'])->bridge_record; expect($body->expected_source_revision === $original_receipt->source_revision && $body->source_revision !== $original_receipt->source_revision);
        $receipt = source_receipt($body); $receipt->outcome = 'updated'; $receipt->reason = 'source_revision_updated'; return dbt_response(200, $receipt);
    });
    expect(FromSynchronizer::serialized(fn($guard) => CmsSourcePublisher::step(1, $guard)) === 'healthy'); $latest = (new CmsJournal(1))->read();
    expect([$latest->receipt->resource_id, $latest->receipt->topic_id] === $ids && count($latest->accepted_history) === 3 && $latest->accepted_history[0]->record == $original);
});

test('native source change during uncertain creation becomes query-only and cannot mint second mutation', function (): void {
    configured(); mock('V4-01', connection()); mock('V4-03', (object) ['items' => [], 'next_cursor' => null]); mock('V4-02', new WP_Error('timeout'));
    expect(is_wp_error(FromSynchronizer::serialized(fn($guard) => CmsSourcePublisher::step(1, $guard)))); $pending = (new CmsJournal(1))->read()->pending;
    $GLOBALS['dbt']['posts'][1]->post_content = '<p>Changed after timeout</p>'; $count = count(array_filter($GLOBALS['dbt']['requests'], fn($r) => $r[1]['method'] === 'POST'));
    expect(is_wp_error(FromSynchronizer::serialized(fn($guard) => CmsSourcePublisher::step(1, $guard))));
    expect((new CmsJournal(1))->read()->pending == $pending && count(array_filter($GLOBALS['dbt']['requests'], fn($r) => $r[1]['method'] === 'POST')) === $count);
});

test('From metadata page is durably staged before advancing checkpoint and duplicates fail visibly', function (): void {
    configured(); $client = new CmsClient(); $page = fixture('initial_feed'); mock('V4-06', $page, 200, 'stream=initial');
    $state = CmsSourceContinuation::fresh(); $saved = []; $save = function () use (&$saved, $state): bool { $saved[] = unserialize(serialize($state)); return true; };
    expect(CmsSourceContinuation::intake($client, $state, $save) === true && $saved[0]->cursor === null && count($saved[0]->events) === 1);
    $state->events = []; expect(CmsSourceContinuation::finish_page($state, $save) === true && $state->stream === 'changes' && $state->checkpoint === $page->checkpoint_cursor);
    $page->items[] = clone $page->items[0]; mock('V4-06', $page, 200, 'stream=initial'); $other = CmsSourceContinuation::fresh(); expect(is_wp_error(CmsSourceContinuation::intake($client, $other, fn() => true)));
});

test('From complete byte hydration is bounded and handles splitUTF8 across restart without total cap', function (): void {
    configured(); $client = new CmsClient(); $source = fixture('initial_feed')->items[0]->source;
    $bytes = '<p>' . str_repeat('é native source ', 12000) . '</p>'; $source->content_bytes = strlen($bytes); $source->content_sha256 = hash('sha256', $bytes);
    for ($offset = 0; $offset < strlen($bytes); $offset += 32768) {
        $chunk = (object) ['source_id' => $source->source_id, 'source_revision' => $source->source_revision, 'content_sha256' => $source->content_sha256,
            'content_bytes' => strlen($bytes), 'offset' => $offset, 'next_offset' => $offset + 32768 < strlen($bytes) ? $offset + 32768 : null,
            'content_base64' => base64_encode(substr($bytes, $offset, 32768))];
        mock('V4-07', $chunk, 200, 'offset=' . $offset, ['source_id' => $source->source_id, 'source_revision' => $source->source_revision]);
    }
    $progress = null; $result = CmsSourceContinuation::content($client, $source, $progress, fn() => true); expect(is_wp_error($result) && $progress->offset === 131072 && !$progress->complete);
    $progress = unserialize(serialize($progress)); $result = CmsSourceContinuation::content($client, $source, $progress, fn() => true);
    expect($result === $bytes && $progress->complete && strlen($bytes) > 131072);
});

test('native metadata selection preserves empty assignments and rejects unavailable/multiple native authors', function (): void {
    configured(); $conn = connection(); $policy = $conn->policy; $existing = $GLOBALS['dbt']['posts'][1];
    $empty = CmsNativeSelection::resolve($conn, $policy, $existing); expect(is_array($empty) && !isset($empty['post']['post_author']) && $empty['terms'] === []);
    $policy->author_ids = ['7', '9']; expect(is_wp_error(CmsNativeSelection::resolve($conn, $policy, $existing)));
    $policy->author_ids = ['99']; expect(is_wp_error(CmsNativeSelection::resolve($conn, $policy, $existing)));
    $policy->author_ids = ['7']; $valid = CmsNativeSelection::resolve($conn, $policy, $existing); expect($valid['post']['post_author'] === 7);
});

test('actual V4 owned deletion uses veto and exact durable withdrawal before metadata loss then actual completion', function (): void {
    configured(); $state = establish_source(); $deletion = CmsSourceWithdrawal::deletion_guard();
    $GLOBALS['dbt']['native_lock'] = 'other'; expect($deletion->before(null, $GLOBALS['dbt']['posts'][1], true) === false && isset($GLOBALS['dbt']['meta'][1]));
    $GLOBALS['dbt']['native_lock'] = null; expect($deletion->before(null, $GLOBALS['dbt']['posts'][1], true) === null);
    $captured = (new CmsJournal(1))->read(); expect($captured->pending->route === 'V4-05' && !$captured->withdrawal->confirmed && $captured->pending->options->body->expected_source_revision === $state->receipt->source_revision);
    $post = $GLOBALS['dbt']['posts'][1]; unset($GLOBALS['dbt']['meta'][1], $GLOBALS['dbt']['posts'][1]); $deletion->completed(1, $post); $deletion->after(1, $post);
    $confirmed = (new CmsJournal(1))->read(); expect($confirmed->withdrawal->confirmed && $confirmed->pending == $captured->pending && $GLOBALS['dbt']['native_lock'] === null);
});

test('owned pending creation vetoes native deletion and retains immutable uncertain request', function (): void {
    configured(); mock('V4-01', connection()); mock('V4-03', (object) ['items' => [], 'next_cursor' => null]); mock('V4-02', new WP_Error('timeout'));
    FromSynchronizer::serialized(fn($guard) => CmsSourcePublisher::step(1, $guard)); $pending = (new CmsJournal(1))->read()->pending;
    $deletion = CmsSourceWithdrawal::deletion_guard(); expect($deletion->before(null, $GLOBALS['dbt']['posts'][1], true) === false && isset($GLOBALS['dbt']['meta'][1]));
    expect((new CmsJournal(1))->read()->pending == $pending && $GLOBALS['dbt']['native_lock'] === null);
});

function native_job(): array {
    $client = new CmsClient(); $conn = connection(); $source = fixture('initial_feed')->items[0]->source;
    $bytes = '<p>Immutable native From article é</p>'; $source->content_bytes = strlen($bytes); $source->content_sha256 = hash('sha256', $bytes);
    $mapping = fixture('mapping'); $mapping->connection_id = Settings::connection_id(); $mapping->destination_native_id = null;
    $mapping->canonical_url = home_url('/from-source/'); $mapping->external_id = 'from:actual-site:' . $source->source_id;
    $lease = fixture('lease'); $lease->server_time = gmdate('Y-m-d\TH:i:s') . '.000Z'; $lease->expires_at = gmdate('Y-m-d\TH:i:s', time() + 300) . '.000Z';
    $journal = CmsCoordinator::fresh('fixture-native-scope'); $journal->lease = $lease; $journal->lease_deadline = time() + 280;
    $job = (object) ['source' => $source, 'mapping' => $mapping, 'plan' => (object) ['canonical_url' => $mapping->canonical_url, 'slug' => 'from-source'],
        'hydration' => (object) ['complete' => true, 'bytes_base64' => base64_encode($bytes)], 'native_result' => null];
    $storage = new CmsJournal();
    return [$client, $conn, $job, $journal, fn(): bool => $storage->write($journal, fn() => true)];
}

test('isolated V4 leased From create retains ownedID fullcontent and separate DOM anonymousfetch observation', function (): void {
    configured(); [$client, $conn, $job, $state, $save] = native_job();
    $result = FromSynchronizer::serialized(fn($guard) => CmsNativeWriter::apply($client, $conn, $conn->policy, $job, $state, $save, $guard));
    expect($result instanceof stdClass, is_wp_error($result) ? $result->get_error_code() : 'native result absent'); $id = (int) $result->native_id;
    expect($GLOBALS['dbt']['posts'][$id]->post_content === base64_decode($job->hydration->bytes_base64) . CmsPresentation::provenance($conn, $job->source));
    expect(CmsNativeWriter::confirm($client, $job, $state, $conn));
    $mapping = clone $job->mapping; $mapping->synchronized_at = gmdate('Y-m-d\TH:i:s', time() - 1) . '.000Z';
    $receipt = get_post_meta($id, CmsPresentation::META, true); $page = CmsPresentation::expected($GLOBALS['dbt']['posts'][$id], $receipt);
    $GLOBALS['dbt']['responses'][$mapping->canonical_url] = dbt_response(200, '<html><body>' . $page . '</body></html>', 'text/html; charset=UTF-8');
    $proof = CmsPublicVerifier::verify($client, $mapping, $result, $job->source, fn() => true);
    expect($proof instanceof stdClass && $proof->source_content_sha256 !== $proof->native_publication_sha256 && $proof->checked_at >= $mapping->synchronized_at);
    $request = end($GLOBALS['dbt']['requests'])[1]; expect($request['cookies'] === [] && !isset($request['headers']['X-DiscussionBridge-Secret']));
    $GLOBALS['dbt']['responses'][$mapping->canonical_url] = dbt_response(200, str_replace('Immutable native', 'Wrong native', $page), 'text/html');
    expect(is_wp_error(CmsPublicVerifier::verify($client, $mapping, $result, $job->source, fn() => true)));
});

test('owned native editor token rejects stale/unversioned writes and permits exact current save while originals pass', function (): void {
    configured(); [$client, $conn, $job, $state, $save] = native_job();
    $result = FromSynchronizer::serialized(fn($guard) => CmsNativeWriter::apply($client, $conn, $conn->policy, $job, $state, $save, $guard)); expect($result instanceof stdClass);
    $id = (int) $result->native_id; $old = CmsNativeFence::token($id); expect(is_string($old));
    $current = CmsNativeFence::with_expected($id, $old, fn() => wp_update_post(['ID' => $id, 'post_content' => '<p>Current native human edit</p>'], true)); expect($current === $id);
    $stale = CmsNativeFence::with_expected($id, $old, fn() => wp_update_post(['ID' => $id, 'post_content' => '<p>Stale editor</p>'], true)); expect(is_wp_error($stale));
    expect($GLOBALS['dbt']['posts'][$id]->post_content === '<p>Current native human edit</p>' && is_wp_error(wp_update_post(['ID' => $id, 'post_content' => '<p>Unversioned</p>'], true)));
    expect(wp_update_post(['ID' => 1, 'post_title' => 'Ordinary unrelated source edit'], true) === 1 && $GLOBALS['dbt']['native_lock'] === null);
    $worker = FromSynchronizer::serialized(fn($guard) => CmsNativeWriter::apply($client, $conn, $conn->policy, $job, $state, $save, $guard)); expect(is_wp_error($worker) && $GLOBALS['dbt']['posts'][$id]->post_content === '<p>Current native human edit</p>');
});

test('normal or wiki From source revision updates preserve the same native postURL/resource/topic and source-time meaning', function (): void {
    configured(); [$client, $conn, $job, $state, $save] = native_job();
    $first = FromSynchronizer::serialized(fn($guard) => CmsNativeWriter::apply($client, $conn, $conn->policy, $job, $state, $save, $guard)); expect($first instanceof stdClass);
    $id = (int) $first->native_id; $initial = get_post_meta($id, CmsPresentation::META, true); $url = get_permalink($id);
    $bytes = '<p>Next complete cooked edition from the same ordinary or wiki first post.</p>';
    $job->source->source_revision = 'source-r2'; $job->source->content_bytes = strlen($bytes); $job->source->content_sha256 = hash('sha256', $bytes);
    $job->source->source_updated_at = '2026-10-14T05:00:00.000Z'; $job->hydration->bytes_base64 = base64_encode($bytes); $job->native_result = null;
    $job->mapping->destination_native_id = $first->native_id; $job->mapping->desired_source_revision = 'source-r2';
    $state->lease->work->work_id = '11111111-1111-4111-8111-000000000006'; $state->lease->work->source_revision = 'source-r2';
    $state->lease->work->lease_generation = 2; $state->lease->lease_generation = 2;
    $next = FromSynchronizer::serialized(fn($guard) => CmsNativeWriter::apply($client, $conn, $conn->policy, $job, $state, $save, $guard));
    expect($next instanceof stdClass, is_wp_error($next) ? $next->get_error_code() : 'next source receipt absent'); $latest = get_post_meta($id, CmsPresentation::META, true);
    expect($next->native_id === $first->native_id && get_permalink($id) === $url && count($GLOBALS['dbt']['posts']) === 2);
    foreach (['resource_id', 'source_id', 'mapping_id', 'external_id', 'native_id', 'canonical_url'] as $field) expect($latest['receipt'][$field] === $initial['receipt'][$field]);
    expect($latest['topic_id'] === $initial['topic_id'] && get_post_meta($id, '_discussionbridge_source_updated_at', true) === '2026-10-14T05:00:00.000Z');
    expect($GLOBALS['dbt']['posts'][$id]->post_content === $bytes . CmsPresentation::provenance($conn, $job->source));
});

test('native storage rollback invalidates caches and reconnect leaves uncertain create with no second create', function (): void {
    configured(); $GLOBALS['dbt']['native_cache_enabled'] = true; [$client, $conn, $job, $state, $save] = native_job();
    $GLOBALS['dbt']['wp_insert_callback'] = function ($id, $data): int { $GLOBALS['dbt']['posts'][$id]->post_content = '<p>Native filter changed body</p>'; return $id; };
    $failed = FromSynchronizer::serialized(fn($guard) => CmsNativeWriter::apply($client, $conn, $conn->policy, $job, $state, $save, $guard)); expect(is_wp_error($failed));
    expect(count($GLOBALS['dbt']['posts']) === 1 && !isset($GLOBALS['dbt']['post_cache'][100], $GLOBALS['dbt']['meta_cache'][100]) && $job->native_result === null);
    configured(); [$client, $conn, $job, $state, $save] = native_job();
    $GLOBALS['dbt']['wp_insert_callback'] = function ($id, $data): int { $GLOBALS['dbt']['connection_id']++; return $id; };
    $failed = FromSynchronizer::serialized(fn($guard) => CmsNativeWriter::apply($client, $conn, $conn->policy, $job, $state, $save, $guard)); expect(is_wp_error($failed));
    expect($state->fences->{$job->mapping->mapping_id}->unresolved_create !== null && $job->native_result === null);
    $count = count($GLOBALS['dbt']['inserted']); $GLOBALS['dbt']['native_lock'] = null; $GLOBALS['dbt']['wp_insert_callback'] = null;
    $again = FromSynchronizer::serialized(fn($guard) => CmsNativeWriter::apply($client, $conn, $conn->policy, $job, $state, $save, $guard)); expect(is_wp_error($again) && count($GLOBALS['dbt']['inserted']) === $count);
});

test('native nontransactional storage boundary refuses a leased From write before creating any post', function (): void {
    configured(); [$client, $conn, $job, $state, $save] = native_job(); $GLOBALS['dbt']['engine'] = 'MyISAM';
    $result = FromSynchronizer::serialized(fn($guard) => CmsNativeWriter::apply($client, $conn, $conn->policy, $job, $state, $save, $guard));
    expect(is_wp_error($result) && $GLOBALS['dbt']['inserted'] === [] && count($GLOBALS['dbt']['posts']) === 1);
});

test('known actual To media removal retains local durable issue and source with no wire mutation until faithful native repair', function (): void {
    configured(); $body = '<p>Article</p><video controls><source src="https://media.example/article.mp4"></video>';
    $GLOBALS['dbt']['posts'][1]->post_content = $body; $GLOBALS['dbt']['native_kses_callback'] = fn($html) => preg_replace('/<source\\b[^>]*>/i', '', $html);
    mock('V4-01', connection()); $result = FromSynchronizer::serialized(fn($guard) => CmsSourcePublisher::step(1, $guard));
    expect(is_wp_error($result) && $result->get_error_code() === 'discussionbridge_native_media_preservation');
    $state = (new CmsJournal(1))->read(); expect($state->local_issue->observation->element === 'source' && $state->pending === null && $GLOBALS['dbt']['posts'][1]->post_content === $body);
    expect(count(array_filter($GLOBALS['dbt']['requests'], fn($r) => $r[1]['method'] === 'POST')) === 0);
    unset($GLOBALS['dbt']['native_kses_callback']); $state = establish_source(); expect(!isset($state->local_issue) && $state->current_record->content_html === $body);
});

test('native catalog0 sends no pages and current descriptive snapshot changes only at exact finalize', function (): void {
    configured(); $GLOBALS['dbt']['users'] = []; mock('V4-01', connection());
    $upload = fixture('catalog_upload'); $upload->kind = 'authors'; $upload->total_items = 0; $upload->received_items = 0; $upload->next_page = 0;
    mock('V4-24', function ($url, $args) use ($upload): array { $body = json_decode($args['body']); $answer = clone $upload; $answer->native_revision = $body->native_revision; return dbt_response(201, $answer); });
    $scope = 'actual-native-catalog'; $one = FromSynchronizer::serialized(fn($guard) => CmsCatalog::refresh('authors', $scope, $guard)); expect(is_array($one) && !$one['done']);
    $journal = (new CmsJournal())->read(); $revision = $journal->catalog->native_revision;
    mock('V4-26', (object) ['kind' => 'authors', 'catalog_revision' => 'current-native-catalog', 'native_revision' => $revision, 'total_items' => 0, 'items' => [], 'next_cursor' => null],
        200, '', ['upload_id' => $upload->upload_id]);
    $two = FromSynchronizer::serialized(fn($guard) => CmsCatalog::refresh('authors', $scope, $guard)); expect($two['done'] && $two['total'] === 0);
    $journal = (new CmsJournal())->read(); expect($journal->catalog === null && $journal->catalog_receipts->authors->total_items === 0 && $journal->jobs === []);
    expect(count(array_filter($GLOBALS['dbt']['requests'], fn($r) => str_contains($r[0], '/pages.json') || str_contains($r[0], '/work/'))) === 0);
});

test('native catalog upload101 uses sequential100+1 pages and uncertain page replays exact request without policy writes', function (): void {
    configured(); $GLOBALS['dbt']['users'] = [];
    for ($id = 1; $id <= 101; $id++) $GLOBALS['dbt']['users'][$id] = new WP_User($id, 'native-' . $id, 'Native ' . $id, ['publish_posts' => $id !== 101]);
    mock('V4-01', connection()); $upload = fixture('catalog_upload'); $upload->kind = 'authors'; $upload->total_items = 101; $upload->received_items = 0; $upload->next_page = 0;
    mock('V4-24', function ($url, $args) use ($upload): array { $answer = clone $upload; $answer->native_revision = json_decode($args['body'])->native_revision; return dbt_response(201, $answer); });
    $scope = 'native101'; $result = FromSynchronizer::serialized(fn($guard) => CmsCatalog::refresh('authors', $scope, $guard)); expect(is_array($result));
    $journal = (new CmsJournal())->read(); $upload = $journal->catalog->upload;
    $url = mock('V4-25', new WP_Error('timeout'), 200, '', ['upload_id' => $upload->upload_id]);
    expect(is_wp_error(FromSynchronizer::serialized(fn($guard) => CmsCatalog::refresh('authors', $scope, $guard)))); $request = end($GLOBALS['dbt']['requests'])[1]['body'];
    expect(count(json_decode($request)->items) === 100 && strlen($request) <= 65536 && (new CmsJournal())->read()->catalog->index === 0);
    $GLOBALS['dbt']['responses'][$url] = function ($url, $args) use ($upload): array { $body = json_decode($args['body']); $answer = clone $upload; $answer->received_items = $body->page === 0 ? 100 : 101; $answer->next_page = $body->page + 1; return dbt_response(200, $answer); };
    $page0 = FromSynchronizer::serialized(fn($guard) => CmsCatalog::refresh('authors', $scope, $guard)); expect($page0['received'] === 100 && end($GLOBALS['dbt']['requests'])[1]['body'] === $request);
    $page1 = FromSynchronizer::serialized(fn($guard) => CmsCatalog::refresh('authors', $scope, $guard)); expect($page1['received'] === 101 && count(json_decode(end($GLOBALS['dbt']['requests'])[1]['body'])->items) === 1);
    $pending = (new CmsJournal())->read(); $last = array_values(array_filter($pending->catalog->items, fn($item) => $item->id === '101'))[0]; expect(!$last->available);
    expect($pending->jobs === [] && $pending->pending === null && $pending->lease === null);
});

test('owned native editor REST revision input rejects stale capture and releases cancellation locks', function (): void {
    configured(); [$client, $conn, $job, $state, $save] = native_job();
    $result = FromSynchronizer::serialized(fn($guard) => CmsNativeWriter::apply($client, $conn, $conn->policy, $job, $state, $save, $guard)); expect($result instanceof stdClass);
    $id = (int) $result->native_id; $token = CmsNativeFence::token($id);
    $request = new WP_REST_Request(['id' => $id, CmsNativeFence::INPUT => $token]); $prepared = (object) ['ID' => $id];
    expect(CmsNativeFence::rest_prepare($prepared, $request) === $prepared && $GLOBALS['dbt']['native_lock'] === 'worker');
    CmsNativeFence::cancel(); expect($GLOBALS['dbt']['native_lock'] === null);
    $GLOBALS['dbt']['posts'][$id]->post_title = 'Current native change'; expect(is_wp_error(CmsNativeFence::rest_prepare($prepared, $request)));
    expect($GLOBALS['dbt']['native_lock'] === null && $GLOBALS['dbt']['posts'][$id]->post_title === 'Current native change');
    $request = new WP_REST_Request(['id' => 1]); expect(CmsNativeFence::rest_prepare((object) ['ID' => 1], $request) instanceof stdClass);
});

test('final native creation body mismatch vetoes SQL and durably preserves complete source and exact private authority', function (): void {
    configured(); [$client, $conn, $job, $state, $save] = native_job(); $state->jobs = [$job];
    $GLOBALS['dbt']['native_final_data_callback'] = function ($data, $original): array { $data['post_content'] = wp_slash('<p>Lossy final native body</p>'); return $data; };
    $result = FromSynchronizer::serialized(fn($guard) => CmsNativeWriter::apply($client, $conn, $conn->policy, $job, $state, $save, $guard));
    expect(is_wp_error($result) && $result->get_error_code() === 'discussionbridge_cms_native_body_limitation');
    expect($GLOBALS['dbt']['inserted'] === [] && count($GLOBALS['dbt']['posts']) === 1 && $job->native_result === null);
    expect($job->local_issue->rollback_proven && $job->local_issue->work == $state->lease->work && $job->creation_attempt === null);
    $stored = (new CmsJournal())->read(); expect($stored->jobs[0]->hydration->bytes_base64 === $job->hydration->bytes_base64 && $stored->jobs[0]->local_issue == $job->local_issue);
    expect($job->local_issue->observation->expected_sha256 === hash('sha256', base64_decode($job->hydration->bytes_base64) . CmsPresentation::provenance($conn, $job->source)));
    expect($GLOBALS['dbt']['requests'] === [] && $GLOBALS['dbt']['native_lock'] === null);
    unset($GLOBALS['dbt']['native_final_data_callback']);
    expect(wp_insert_post(['post_title' => 'Unrelated source', 'post_content' => '<p>Ordinary body</p>'], true) > 0);
});

function held_native_case(): array {
    configured(); [$client, $conn, $job, $state, $save] = native_job();
    $first = FromSynchronizer::serialized(fn($guard) => CmsNativeWriter::apply($client, $conn, $conn->policy, $job, $state, $save, $guard)); expect($first instanceof stdClass);
    $id = (int) $first->native_id; $prior = unserialize(serialize($GLOBALS['dbt']['posts'][$id])); $receipt = get_post_meta($id, CmsPresentation::META, true);
    $job->mapping->destination_native_id = (string) $id; $job->source->source_revision = 'source-r2'; $job->mapping->desired_source_revision = 'source-r2';
    $body = '<video><source src="https://media.example/full.mp4"></video><p>Complete new source</p>';
    $job->source->content_bytes = strlen($body); $job->source->content_sha256 = hash('sha256', $body); $job->hydration->bytes_base64 = base64_encode($body); $job->native_result = null;
    $job->event = null; $job->prepared = true; $job->synchronized = false; $job->verification = null;
    $state->lease->work->source_revision = 'source-r2'; $state->lease->work->work_id = '11111111-1111-4111-8111-000000000009';
    $state->lease->work->lease_generation = 2; $state->lease->lease_generation = 2; $state->jobs = [$job];
    $GLOBALS['dbt']['native_final_data_callback'] = function ($data): array { $data['post_content'] = wp_slash('<p>Lossy replacement</p>'); return $data; };
    $count = count($GLOBALS['dbt']['inserted']);
    $result = FromSynchronizer::serialized(fn($guard) => CmsNativeWriter::apply($client, $conn, $conn->policy, $job, $state, $save, $guard));
    expect(is_wp_error($result) && $result->get_error_code() === 'discussionbridge_cms_native_body_limitation');
    expect(count($GLOBALS['dbt']['inserted']) === $count && $GLOBALS['dbt']['posts'][$id] == $prior && get_post_meta($id, CmsPresentation::META, true) === $receipt);
    expect($job->local_issue->prior_native_receipt === $receipt && $job->local_issue->rollback_proven);
    $state->receiver_scope = $conn->scope_revision . "\0" . $conn->policy->policy_revision;
    $state->mapping_complete = true; $state->mappings->{$job->source->source_id} = $job->mapping;
    $state->sources->stream = 'changes'; $state->sources->cursor = fixture('changes_feed')->checkpoint_cursor;
    $status = (object) ['work' => clone $state->lease->work, 'mapping' => clone $job->mapping, 'lease_expires_at' => $state->lease->expires_at];
    expect(CmsLocalLimitation::hold($job, $state, $save)); $state->jobs = []; expect($save());
    $empty = fixture('changes_feed'); $empty->items = [];
    mock('V4-01', $conn); mock('V4-08', $conn->policy); mock('V4-06', $empty, 200, 'stream=changes&cursor=' . $state->sources->cursor);
    mock('V4-14', $status, 200, '', ['work_id' => $status->work->work_id]); mock('V4-11', $job->mapping, 200, '', ['mapping_id' => $job->mapping->mapping_id]);
    return [$conn, $job, $state, $status, $id];
}

test('held final native loss observes knownWork without callbacks claims failures or count/time reset', function (): void {
    [$conn, $job, $state, $status, $id] = held_native_case(); $before = count($GLOBALS['dbt']['inserted']);
    $GLOBALS['dbt']['native_final_data_callback'] = function (): never { throw new RuntimeException('A read must not replay final native callbacks'); };
    for ($i = 0; $i < 2; $i++) expect(FromSynchronizer::serialized(fn($guard) => CmsCoordinator::step($state->scope, $guard)) === 'waiting');
    $current = (new CmsJournal())->read(); $held = $current->local_issues->{$status->work->work_id};
    expect($held->job->local_issue->work == $status->work && $held->lease->lease_generation === 2 && $current->lease === null);
    expect($held->job->local_issue->repair_proof === 'unresolved_final_native_hooks' && count($GLOBALS['dbt']['inserted']) === $before);
    expect(count(array_filter($GLOBALS['dbt']['requests'], fn($r) => $r[1]['method'] === 'POST')) === 0 && FromSynchronizer::status()['local_limitation_count'] === 1);
});

test('known native limitation permits only positive unrelated owned mapping claims and exact bodyless204 replay completion', function (): void {
    [$conn, $job, $state, $status] = held_native_case(); $other = clone $job->mapping;
    $other->mapping_id = '11111111-1111-4111-8111-000000000021'; $other->resource_id = '11111111-1111-4111-8111-000000000022';
    $other->source_id = 'topic:21'; $other->external_id = 'from:actual-site:topic21'; $other->canonical_url = home_url('/other-owned/'); $other->destination_native_id = '121';
    $state->mappings->{$other->source_id} = $other; expect((new CmsJournal())->write($state, fn() => true));
    mock('V4-13', function ($url, $args) use ($other): array { $request = json_decode($args['body']); expect($request->mapping_id === $other->mapping_id && count(get_object_vars($request)) === 2); return dbt_response(204, ''); });
    expect(FromSynchronizer::serialized(fn($guard) => CmsCoordinator::step($state->scope, $guard)) === 'waiting');
    $posts = array_values(array_filter($GLOBALS['dbt']['requests'], fn($r) => $r[1]['method'] === 'POST'));
    expect(count($posts) === 1 && str_ends_with($posts[0][0], '/work/claim.json'));
    $current = (new CmsJournal())->read(); expect($current->pending === null && $current->jobs === [] && count(get_object_vars($current->local_issues)) === 1);
});

test('interruption after durable final veto resumes into private held status without another native attempt or lease mutation', function (): void {
    [$conn, $job, $state, $status] = held_native_case(); $held = $state->local_issues->{$status->work->work_id};
    $state->local_issues = new stdClass(); $state->jobs = [$held->job]; $state->lease = $held->lease; $state->lease_deadline = $held->lease_deadline;
    expect((new CmsJournal())->write($state, fn() => true)); $count = count($GLOBALS['dbt']['inserted']);
    $GLOBALS['dbt']['native_final_data_callback'] = function (): never { throw new RuntimeException('Known refused write must not execute after restart'); };
    expect(FromSynchronizer::serialized(fn($guard) => CmsCoordinator::step($state->scope, $guard)) === 'waiting');
    $current = (new CmsJournal())->read(); expect($current->jobs === [] && $current->lease === null && isset($current->local_issues->{$status->work->work_id}));
    expect(count($GLOBALS['dbt']['inserted']) === $count && count(array_filter($GLOBALS['dbt']['requests'], fn($r) => $r[1]['method'] === 'POST')) === 0);
});

test('genuine newer source prepares current intent while known refusedWork is observed without sameMapping claim', function (): void {
    [$conn, $job, $state, $status] = held_native_case(); $event = fixture('initial_feed')->items[0];
    $event->event_sequence = '103'; $event->event_id = '11111111-1111-4111-8111-000000000031'; $event->source->source_revision = 'source-r3';
    $event->source->content_sha256 = hash('sha256', '<p>New complete edition</p>'); $event->source->content_bytes = strlen('<p>New complete edition</p>');
    $page = fixture('changes_feed'); $page->items = [$event]; mock('V4-06', $page, 200, 'stream=changes&cursor=' . $state->sources->cursor);
    mock('V4-09', function ($url, $args) use ($job): array { $body = json_decode($args['body']); expect($body->source_revision === 'source-r3'); $mapping = clone $job->mapping; $mapping->desired_source_revision = 'source-r3'; return dbt_response(200, $mapping); });
    expect(FromSynchronizer::serialized(fn($guard) => CmsCoordinator::step($state->scope, $guard)) === 'working');
    $current = (new CmsJournal())->read(); expect($current->jobs[0]->prepared && $current->jobs[0]->source->source_revision === 'source-r3');
    expect($current->source_records->{hash('sha256', $event->source->source_id . "\0source-r3")} == $event->source);
    expect(count(array_filter($GLOBALS['dbt']['requests'], fn($r) => str_ends_with($r[0], '/work/claim.json'))) === 0);
    $status->work->state = 'superseded'; $status->mapping->desired_source_revision = 'source-r3'; mock('V4-14', $status, 200, '', ['work_id' => $status->work->work_id]);
    mock('V4-11', $status->mapping, 200, '', ['mapping_id' => $status->mapping->mapping_id]);
    expect(CmsLocalLimitation::observe(new CmsClient(), $current, $conn->policy, fn() => true) === true && get_object_vars($current->local_issues) === []);
    expect($current->retired[0]->held->job->source->source_revision === 'source-r2');
});

test('receiver supersession permits owned withdrawal without masking it with the old publish-body limitation', function (): void {
    [$conn, $job, $state, $status, $id] = held_native_case(); $status->work->state = 'superseded';
    mock('V4-14', $status, 200, '', ['work_id' => $status->work->work_id]);
    $page = fixture('changes_feed'); mock('V4-06', $page, 200, 'stream=changes&cursor=' . $state->sources->cursor);
    $lease = fixture('lease'); $lease->work->work_id = '11111111-1111-4111-8111-000000000041'; $lease->work->action = 'withdraw'; $lease->work->source_revision = null;
    $lease->work->lease_generation = 3; $lease->lease_generation = 3; $lease->server_time = gmdate('Y-m-d\TH:i:s') . '.000Z'; $lease->expires_at = gmdate('Y-m-d\TH:i:s', time() + 300) . '.000Z';
    mock('V4-13', function ($url, $args) use ($lease, $job): array { expect(json_decode($args['body'])->mapping_id === $job->mapping->mapping_id); return dbt_response(200, $lease); });
    mock('V4-15', $lease, 200, '', ['work_id' => $lease->work->work_id]);
    $mapping = clone $job->mapping; $mapping->synchronized_at = gmdate('Y-m-d\TH:i:s') . '.000Z';
    mock('V4-16', function ($url, $args) use ($lease, $mapping): array { $request = json_decode($args['body']); $work = clone $lease->work; $work->state = $request->stage === 'synchronized' ? 'synchronized' : 'verified'; $answer = clone $mapping; if ($request->stage === 'verified') $answer->publicly_verified_at = $request->verification->checked_at; return dbt_response(200, (object) ['work' => $work, 'mapping' => $answer, 'lease_expires_at' => $lease->expires_at]); }, 200, '', ['work_id' => $lease->work->work_id]);
    $GLOBALS['dbt']['responses'][$mapping->canonical_url] = dbt_response(404, '', 'text/html');
    expect(FromSynchronizer::serialized(fn($guard) => CmsCoordinator::step($state->scope, $guard)) === 'healthy');
    $current = (new CmsJournal())->read(); expect(get_object_vars($current->local_issues) === [] && $GLOBALS['dbt']['posts'][$id]->post_status === 'draft');
    expect($current->retired[0]->held->job->local_issue->work->action === 'publish_revision' && $current->mappings->{$mapping->source_id}->publicly_verified_at !== null);
    expect(count(array_filter($GLOBALS['dbt']['requests'], fn($r) => str_ends_with($r[0], '/fail.json'))) === 0);
});

function record_for(stdClass $state, string $status = 'active'): stdClass {
    $record = fixture('record'); $record->state = $status;
    foreach (['resource_id', 'topic_id', 'topic_url', 'source_revision', 'synchronized_at'] as $field) $record->$field = $state->receipt->$field;
    foreach (['external_id', 'canonical_url', 'source_created_at', 'source_updated_at'] as $field) $record->$field = $state->current_record->$field;
    return $record;
}
function recover_source(): bool|WP_Error { return FromSynchronizer::serialized(fn($guard) => CmsSourceRecovery::step($guard)); }

test('durable To delete journal is rediscovered without index or Cron event and exact05 replay works with From Policy off', function (): void {
    configured(); $accepted = establish_source(); $guard = CmsSourceWithdrawal::deletion_guard(); $post = $GLOBALS['dbt']['posts'][1];
    expect($guard->before(null, $post, true) === null); unset($GLOBALS['dbt']['posts'][1], $GLOBALS['dbt']['meta'][1]); $guard->completed(1, $post); $guard->after(1, $post);
    $pending = (new CmsJournal(1))->read()->pending; $GLOBALS['dbt']['scheduled'] = [];
    $source_option = (new CmsJournal(1))->option_name(); foreach (array_keys($GLOBALS['dbt']['options']) as $key) if (str_starts_with($key, 'discussionbridge_native_') && $key !== $source_option) unset($GLOBALS['dbt']['options'][$key]);
    expect(recover_source() === true && wp_next_scheduled(Publisher::DELIVERY_HOOK, [1]) !== false, 'deleted-source discovery/requeue failed');
    $conn = connection(); $conn->policy->enabled = false; mock('V4-01', $conn); $url = mock('V4-05', new WP_Error('timeout'), 200, '', ['resource_id' => $accepted->receipt->resource_id]);
    Publisher::deliver(1); $first = end($GLOBALS['dbt']['requests'])[1]['body']; expect(json_decode($first)->expected_source_revision === $accepted->receipt->source_revision, 'first05 dispatch did not retain accepted predecessor');
    $GLOBALS['dbt']['scheduled'] = []; $recovered = recover_source(); expect($recovered === true && wp_next_scheduled(Publisher::DELIVERY_HOOK, [1]) !== false,
        'pending05 restart/requeue failed: ' . (is_wp_error($recovered) ? $recovered->get_error_code() : json_encode((new CmsJournal(1))->read()->withdrawal)));
    $GLOBALS['dbt']['responses'][$url] = dbt_response(200, record_for($accepted, 'withdrawn')); Publisher::deliver(1);
    $state = (new CmsJournal(1))->read(); expect($state->withdrawal->status === 'withdrawn' && $state->pending === null && $state->receipt == $accepted->receipt, '05 receipt settlement failed: ' . $state->withdrawal->status . ' ' . json_encode($state->withdrawal->terminal ?? null));
    expect(end($GLOBALS['dbt']['requests'])[1]['body'] === $first && $state->accepted_history[1]->request == $pending && count($state->accepted_history) === 2, 'exact05 replay/history changed');
    expect(!isset($GLOBALS['dbt']['posts'][1], $GLOBALS['dbt']['meta'][1]));
});

test('owned native unpublish lost under writer contention is recovered from actual post state without publication replay', function (): void {
    configured(); $accepted = establish_source(); $GLOBALS['dbt']['scheduled'] = []; $GLOBALS['dbt']['posts'][1]->post_status = 'draft';
    $GLOBALS['dbt']['native_lock'] = 'other'; CmsSourceWithdrawal::status_changed('draft', 'publish', $GLOBALS['dbt']['posts'][1]);
    expect(!isset((new CmsJournal(1))->read()->withdrawal)); $GLOBALS['dbt']['native_lock'] = null;
    $count = count($GLOBALS['dbt']['requests']); expect(recover_source() === true);
    $state = (new CmsJournal(1))->read(); expect($state->withdrawal->request->body->reason === 'source_unpublished' && $state->withdrawal->request->body->expected_source_revision === $accepted->receipt->source_revision);
    expect($state->pending->route === 'V4-05' && count($GLOBALS['dbt']['requests']) === $count && wp_next_scheduled(Publisher::DELIVERY_HOOK, [1]) !== false);
});

test('native post-change capture persistence failure leaves accepted journal discoverable and retries exact closure capture later', function (): void {
    configured(); $accepted = establish_source(); $GLOBALS['dbt']['posts'][1]->post_status = 'draft'; $GLOBALS['dbt']['scheduled'] = [];
    $option = (new CmsJournal(1))->option_name(); $GLOBALS['dbt']['option_write_callback'] = fn($key) => $key !== $option;
    CmsSourceWithdrawal::status_changed('draft', 'publish', $GLOBALS['dbt']['posts'][1]); expect(!isset((new CmsJournal(1))->read()->withdrawal));
    $GLOBALS['dbt']['option_write_callback'] = null; expect(recover_source() === true);
    $state = (new CmsJournal(1))->read(); expect($state->withdrawal->confirmed && $state->receipt == $accepted->receipt && $state->pending->route === 'V4-05');
});

test('protected owner optout fact survives capture contention and native recovery does not invent intent from missing metadata', function (): void {
    configured(); $accepted = establish_source(); $GLOBALS['dbt']['scheduled'] = []; $GLOBALS['dbt']['native_lock'] = 'other';
    $_POST = ['discussionbridge_post_nonce' => 'native-owner-nonce']; Admin::save_meta_box(1, $GLOBALS['dbt']['posts'][1]); $_POST = [];
    $fact = get_post_meta(1, CmsSourceRecovery::EXCLUSION_META, true); expect(CmsSourceRecovery::valid_exclusion(1, $fact) && get_post_meta(1, Publisher::ENABLED_META, true) !== '1');
    expect(!isset((new CmsJournal(1))->read()->withdrawal)); $GLOBALS['dbt']['native_lock'] = null; $GLOBALS['dbt']['capabilities']['edit_post'] = false;
    expect(recover_source() === true && (new CmsJournal(1))->read()->withdrawal->request->body->reason === 'policy_excluded');
    configured(); establish_source(); delete_post_meta(1, Publisher::ENABLED_META); expect(recover_source() === true);
    expect(!isset((new CmsJournal(1))->read()->withdrawal));
});

test('saved native type eligibility loss recovers actual source_ineligible after capture contention', function (): void {
    configured(); establish_source(); $GLOBALS['dbt']['posts'][1]->post_type = 'page'; $GLOBALS['dbt']['native_lock'] = 'other';
    Publisher::post_saved(1, $GLOBALS['dbt']['posts'][1], true, null); expect(!isset((new CmsJournal(1))->read()->withdrawal));
    $GLOBALS['dbt']['native_lock'] = null; expect(recover_source() === true);
    expect((new CmsJournal(1))->read()->withdrawal->request->body->reason === 'source_ineligible');
});

test('native disappearance without captured delete is explicit local reconciliation not inferred05 or recreated source', function (): void {
    configured(); $accepted = establish_source(); unset($GLOBALS['dbt']['posts'][1], $GLOBALS['dbt']['meta'][1]); $GLOBALS['dbt']['scheduled'] = [];
    $count = count($GLOBALS['dbt']['requests']); expect(recover_source() === true); $state = (new CmsJournal(1))->read();
    expect($state->recovery_issue->code === 'native_missing_without_delete_capture' && !isset($state->withdrawal));
    expect($state->receipt == $accepted->receipt && $GLOBALS['dbt']['scheduled'] === [] && count($GLOBALS['dbt']['requests']) === $count);
});

test('new uncertain source with no acceptedA remains unreplayed and deletion-vetoed after actual closure/restart', function (): void {
    configured(); mock('V4-01', connection()); mock('V4-03', (object) ['items' => [], 'next_cursor' => null]); mock('V4-02', new WP_Error('timeout'));
    FromSynchronizer::serialized(fn($guard) => CmsSourcePublisher::step(1, $guard)); $pending = (new CmsJournal(1))->read()->pending;
    $GLOBALS['dbt']['posts'][1]->post_status = 'draft'; $GLOBALS['dbt']['scheduled'] = []; $count = count($GLOBALS['dbt']['requests']);
    expect(recover_source() === true); $state = (new CmsJournal(1))->read();
    expect($state->pending == $pending && $state->receipt === null && isset($state->lifecycle_attention) && !isset($state->withdrawal));
    expect(count($GLOBALS['dbt']['requests']) === $count && $GLOBALS['dbt']['scheduled'] === []);
    expect(CmsSourceWithdrawal::deletion_guard()->before(null, $GLOBALS['dbt']['posts'][1], true) === false);
});

function closed_pending_edition(): array {
    configured(); $accepted = establish_source(); $GLOBALS['dbt']['posts'][1]->post_content = '<p>Actual native next edition B</p>'; mock('V4-02', new WP_Error('timeout'));
    expect(is_wp_error(FromSynchronizer::serialized(fn($guard) => CmsSourcePublisher::step(1, $guard))));
    $before = (new CmsJournal(1))->read(); $GLOBALS['dbt']['posts'][1]->post_status = 'draft';
    CmsSourceWithdrawal::status_changed('draft', 'publish', $GLOBALS['dbt']['posts'][1]); $captured = (new CmsJournal(1))->read();
    expect($captured->withdrawal->preserves_publication_pending && $captured->pending == $before->pending && $captured->capture == $before->capture);
    return [$accepted, $before, $captured];
}

test('closed acceptedA uses scoped04A and separate exact05 while uncertain02B and full capture remain immutable and unreplayed', function (): void {
    [$accepted, $before, $captured] = closed_pending_edition(); $count = count($GLOBALS['dbt']['requests']);
    $record = record_for($accepted); mock('V4-04', $record, 200, '', ['resource_id' => $record->resource_id]);
    $url = mock('V4-05', new WP_Error('timeout'), 200, '', ['resource_id' => $record->resource_id]);
    expect(is_wp_error(FromSynchronizer::serialized(fn($guard) => CmsSourcePublisher::step(1, $guard)))); $one = (new CmsJournal(1))->read();
    expect($one->pending == $before->pending && $one->capture == $before->capture && $one->withdrawal->pending == $captured->withdrawal->pending);
    $first = end($GLOBALS['dbt']['requests'])[1]['body']; expect(json_decode($first)->expected_source_revision === $accepted->receipt->source_revision);
    $withdrawn = record_for($accepted, 'withdrawn'); mock('V4-04', $withdrawn, 200, '', ['resource_id' => $record->resource_id]); $GLOBALS['dbt']['responses'][$url] = dbt_response(200, $withdrawn);
    expect(FromSynchronizer::serialized(fn($guard) => CmsSourcePublisher::step(1, $guard)) === 'withdrawn'); $state = (new CmsJournal(1))->read();
    expect($state->pending == $before->pending && $state->capture == $before->capture && $state->withdrawal->pending === null && $state->receipt == $accepted->receipt);
    expect(end($GLOBALS['dbt']['requests'])[1]['body'] === $first && count($state->accepted_history) === 2);
    expect(count(array_filter(array_slice($GLOBALS['dbt']['requests'], $count), fn($r) => str_ends_with($r[0], '/resolve.json'))) === 0);
});

test('scoped04 newerB metadata cannot promote pendingB or authorize guessed05 CAS after native closure', function (): void {
    [$accepted, $before] = closed_pending_edition(); $record = record_for($accepted); $record->source_revision = $before->pending->options->body->bridge_record->source_revision;
    mock('V4-04', $record, 200, '', ['resource_id' => $record->resource_id]); $count = count($GLOBALS['dbt']['requests']);
    expect(is_wp_error(FromSynchronizer::serialized(fn($guard) => CmsSourcePublisher::step(1, $guard)))); $state = (new CmsJournal(1))->read();
    expect($state->pending == $before->pending && $state->capture == $before->capture && $state->receipt == $accepted->receipt && $state->withdrawal->accepted === null);
    expect(count(array_filter(array_slice($GLOBALS['dbt']['requests'], $count), fn($r) => $r[1]['method'] === 'POST')) === 0);
});

test('05A CAS conflict after scoped04A preserves both pending trees and forbids trial02 replay or automatic resurrection', function (): void {
    [$accepted, $before, $captured] = closed_pending_edition(); $record = record_for($accepted);
    mock('V4-04', $record, 200, '', ['resource_id' => $record->resource_id]); mock('V4-05', fixture('error'), 409, '', ['resource_id' => $record->resource_id]);
    $count = count($GLOBALS['dbt']['requests']); expect(is_wp_error(FromSynchronizer::serialized(fn($guard) => CmsSourcePublisher::step(1, $guard))));
    $state = (new CmsJournal(1))->read(); expect($state->withdrawal->terminal->code === 'revision_conflict' && $state->pending == $before->pending && $state->withdrawal->pending == $captured->withdrawal->pending);
    $GLOBALS['dbt']['posts'][1]->post_status = 'publish'; $post_count = count(array_filter($GLOBALS['dbt']['requests'], fn($r) => $r[1]['method'] === 'POST'));
    expect(is_wp_error(FromSynchronizer::serialized(fn($guard) => CmsSourcePublisher::step(1, $guard))));
    expect(count(array_filter($GLOBALS['dbt']['requests'], fn($r) => $r[1]['method'] === 'POST')) === $post_count);
    expect(count(array_filter(array_slice($GLOBALS['dbt']['requests'], $count), fn($r) => str_ends_with($r[0], '/resolve.json'))) === 0);
});

test('unrelated corrupt sealed option is recorded without globally blocking a positively owned source recovery', function (): void {
    configured(); establish_source(); $GLOBALS['dbt']['posts'][1]->post_status = 'draft'; $GLOBALS['dbt']['scheduled'] = [];
    $GLOBALS['dbt']['options']['discussionbridge_native_' . str_repeat('0', 40)] = 'gcm1:broken';
    expect(recover_source() === true && (new CmsJournal(1))->read()->withdrawal->request->body->reason === 'source_unpublished');
    expect(wp_next_scheduled(Publisher::DELIVERY_HOOK, [1]) !== false);
});

test('physical native connection loss during lifecycle observation cannot commit capture schedule or guessed withdrawal', function (): void {
    configured(); $accepted = establish_source(); $GLOBALS['dbt']['posts'][1]->post_status = 'draft'; $GLOBALS['dbt']['scheduled'] = [];
    $GLOBALS['dbt']['native_row_callback'] = function (): void { $GLOBALS['dbt']['connection_id']++; };
    $count = count($GLOBALS['dbt']['requests']); expect(is_wp_error(recover_source()));
    $state = (new CmsJournal(1))->read(); expect($state->receipt == $accepted->receipt && !isset($state->withdrawal));
    expect($GLOBALS['dbt']['scheduled'] === [] && count($GLOBALS['dbt']['requests']) === $count);
});

test('actual later native deletion preserves captured05 reason and pendingB while supplying its own confirmed closure fact', function (): void {
    [$accepted, $before, $captured] = closed_pending_edition(); $frozen05 = $captured->withdrawal->pending;
    $post = $GLOBALS['dbt']['posts'][1]; $guard = CmsSourceWithdrawal::deletion_guard(); expect($guard->before(null, $post, true) === null);
    unset($GLOBALS['dbt']['posts'][1], $GLOBALS['dbt']['meta'][1]); $guard->completed(1, $post); $guard->after(1, $post);
    $deleted = (new CmsJournal(1))->read(); expect($deleted->withdrawal->pending == $frozen05 && $deleted->withdrawal->native_delete->confirmed);
    expect($deleted->pending == $before->pending && $deleted->capture == $before->capture);
    $record = record_for($accepted); mock('V4-04', $record, 200, '', ['resource_id' => $record->resource_id]); mock('V4-05', record_for($accepted, 'withdrawn'), 200, '', ['resource_id' => $record->resource_id]);
    expect(FromSynchronizer::serialized(fn($physical) => CmsSourcePublisher::step(1, $physical)) === 'withdrawn');
    $state = (new CmsJournal(1))->read(); expect($state->pending == $before->pending && $state->withdrawal->response->source_revision === $accepted->receipt->source_revision);
});

function owned_presentation(): array {
    configured(); [$client, $conn, $job, $state, $save] = native_job();
    $job->source->topic_url = Settings::server_url() . '/t/native/' . $job->source->topic_id;
    $native = FromSynchronizer::serialized(fn($guard) => CmsNativeWriter::apply($client, $conn, $conn->policy, $job, $state, $save, $guard));
    expect($native instanceof stdClass); $id = (int) $native->native_id;
    $record = fixture('record'); $record->direction = 'from_discourse'; $record->resource_id = $job->mapping->resource_id;
    $record->mapping_id = $job->mapping->mapping_id; $record->canonical_url = get_permalink($id); $record->external_id = $job->mapping->external_id;
    $record->source_revision = $job->source->source_revision; $record->topic_id = $job->source->topic_id; $record->topic_url = $job->source->topic_url;
    $record->source_created_at = $job->source->source_created_at; $record->source_updated_at = $job->source->source_updated_at;
    mock('V4-04', $record, 200, '', ['resource_id' => $record->resource_id]);
    return [$id, $record, $job];
}

test('registered native block renders only the existing owned publication and Full discussion without publication mutation', function (): void {
    [$id, $record, $job] = owned_presentation(); Presentation::register_block();
    expect($GLOBALS['dbt']['block'][1]['render_callback'] === [Presentation::class, 'render_block']);
    $before = (new CmsJournal())->read(); $body = $GLOBALS['dbt']['posts'][$id]->post_content;
    $html = Presentation::render_block(['resourceId' => $record->resource_id, 'commentsMode' => 'full']);
    expect(str_contains($html, 'discussionbridge-record') && str_contains($html, $body) && str_contains($html, 'discussionbridge-discussion--full'));
    expect(str_contains($html, 'topic_id=' . $record->topic_id) && !str_contains($html, 'full_app=true'));
    expect((new CmsJournal())->read() == $before && count($GLOBALS['dbt']['posts']) === 2);
    expect(count(array_filter($GLOBALS['dbt']['requests'], fn($request) => $request[1]['method'] !== 'GET')) === 0);
});

test('block fails closed for absent invalid duplicate and drifted native publication identities without creating content', function (): void {
    [$id, $record] = owned_presentation(); expect(Presentation::render_block(['resourceId' => 'invalid']) === '');
    $wrong = clone $record; $wrong->topic_id = '99'; mock('V4-04', $wrong, 200, '', ['resource_id' => $record->resource_id]);
    expect(Presentation::render_block(['resourceId' => $record->resource_id]) === '');
    mock('V4-04', $record, 200, '', ['resource_id' => $record->resource_id]);
    $GLOBALS['dbt']['extra_postmeta_rows'][] = [1, '_discussionbridge_resource_id', $record->resource_id];
    expect(Presentation::render_block(['resourceId' => $record->resource_id]) === ''); $GLOBALS['dbt']['extra_postmeta_rows'] = [];
    $GLOBALS['dbt']['posts'][$id]->post_content = '<p>Actual independent native edit</p>';
    expect(Presentation::render_block(['resourceId' => $record->resource_id]) === '' && $GLOBALS['dbt']['posts'][$id]->post_content === '<p>Actual independent native edit</p>');
    expect(count($GLOBALS['dbt']['posts']) === 2 && count(array_filter($GLOBALS['dbt']['requests'], fn($r) => $r[1]['method'] !== 'GET')) === 0);
});

test('native shortcode renders Simple replies separately from source and Interactive embeds the selected owned topic', function (): void {
    [$id, $record] = owned_presentation(); $topic = ['post_stream' => ['stream' => [80, 81], 'posts' => [
        ['id' => 80, 'post_number' => 1, 'cooked' => '<p>First forum body must not become a reply</p>'],
        ['id' => 81, 'post_number' => 2, 'username' => 'native-replier', 'name' => 'Native Reply Author', 'created_at' => '2026-10-14T01:00:00.000Z', 'cooked' => '<p>Visible native reply</p>'],
    ]]];
    $GLOBALS['dbt']['responses'][Settings::server_url() . '/t/' . $record->topic_id . '.json'] = dbt_response(200, $topic);
    $simple = Presentation::shortcode(['resource_id' => $record->resource_id, 'comments' => 'simple']);
    expect(str_contains($simple, 'Visible native reply') && str_contains($simple, 'Native Reply Author') && !str_contains($simple, 'First forum body must not become a reply'));
    expect(str_contains($simple, $GLOBALS['dbt']['posts'][$id]->post_content) && !str_contains($simple, '<iframe'));
    $interactive = Presentation::shortcode(['resource_id' => $record->resource_id, 'comments' => 'interactive']);
    expect(str_contains($interactive, 'full_app=true') && str_contains($interactive, 'discussion-bridge-source-presentation') && str_contains($interactive, 'topic_id=' . $record->topic_id));
    expect(Presentation::shortcode('unstructured attributes') === '' && count(array_filter($GLOBALS['dbt']['requests'], fn($r) => $r[1]['method'] !== 'GET')) === 0);
});

test('site discussion default and actual per-post override govern visible output while none leaves article unchanged', function (): void {
    configured(); $accepted = establish_source(); $GLOBALS['dbt']['queried_id'] = 1; $article = '<p>Underlying native article</p>';
    update_option(Settings::COMMENTS_MODE_OPTION, 'full');
    $site = Presentation::append_mapped_discussion($article); expect(str_contains($site, 'discussionbridge-discussion--full') && !str_contains($site, 'full_app=true'));
    update_post_meta(1, Presentation::COMMENTS_MODE_META, 'interactive'); $local = Presentation::append_mapped_discussion($article);
    expect(str_contains($local, 'discussionbridge-discussion--interactive') && str_contains($local, 'full_app=true'));
    update_post_meta(1, Presentation::COMMENTS_MODE_META, 'none'); expect(Presentation::append_mapped_discussion($article) === $article);
    update_post_meta(1, Presentation::COMMENTS_MODE_META, 'fullInteractive'); expect(Presentation::append_mapped_discussion($article) === $article && $GLOBALS['dbt']['settings_errors'] !== []);
    delete_post_meta(1, Presentation::COMMENTS_MODE_META); update_option(Settings::COMMENTS_MODE_OPTION, 'none'); expect(Presentation::append_mapped_discussion($article) === $article);
    $GLOBALS['dbt']['in_loop'] = false; update_option(Settings::COMMENTS_MODE_OPTION, 'full'); expect(Presentation::append_mapped_discussion($article) === $article);
    expect((new CmsJournal(1))->read()->receipt == $accepted->receipt);
});

test('protected per-post mode save requires native capability and nonce and blank restores the existing site default', function (): void {
    configured(); update_option(Settings::COMMENTS_MODE_OPTION, 'simple');
    $_POST = ['discussionbridge_post_nonce' => 'native-mode-nonce', 'discussionbridge_enabled' => '1', 'discussionbridge_comments_mode' => 'full'];
    $GLOBALS['dbt']['nonce_valid'] = false; Admin::save_meta_box(1, $GLOBALS['dbt']['posts'][1]); expect(get_post_meta(1, Presentation::COMMENTS_MODE_META, true) === '');
    $GLOBALS['dbt']['nonce_valid'] = true; $GLOBALS['dbt']['capabilities']['edit_post'] = false; Admin::save_meta_box(1, $GLOBALS['dbt']['posts'][1]); expect(get_post_meta(1, Presentation::COMMENTS_MODE_META, true) === '');
    $GLOBALS['dbt']['capabilities']['edit_post'] = true; Admin::save_meta_box(1, $GLOBALS['dbt']['posts'][1]); expect(get_post_meta(1, Presentation::COMMENTS_MODE_META, true) === 'full' && Settings::comments_mode() === 'simple');
    $_POST['discussionbridge_comments_mode'] = 'fullInteractive'; Admin::save_meta_box(1, $GLOBALS['dbt']['posts'][1]); expect(get_post_meta(1, Presentation::COMMENTS_MODE_META, true) === 'full');
    $_POST['discussionbridge_comments_mode'] = ''; Admin::save_meta_box(1, $GLOBALS['dbt']['posts'][1]); $_POST = [];
    expect(get_post_meta(1, Presentation::COMMENTS_MODE_META, true) === '' && Settings::comments_mode() === 'simple');
});

test('native comments remain suppressed on opted-in or imported publications and unrelated native state passes through', function (): void {
    configured(); expect(Presentation::native_comments_open(true, 1) === false);
    $GLOBALS['dbt']['posts'][2] = new WP_Post(2, 'publish'); expect(Presentation::native_comments_open(true, 2) && !Presentation::native_comments_open(false, 2));
    update_post_meta(2, Materializer::IMPORTED_META, '1'); expect(Presentation::native_comments_open(true, 2) === false);
    delete_post_meta(1, Publisher::ENABLED_META); expect(Presentation::native_comments_open(true, 1) && Presentation::native_comments_open(true, 0));
});

test('normal frontend enqueue respects native context rich optout and one discussion script while retaining source bytes', function (): void {
    configured(); $accepted = establish_source(); $GLOBALS['dbt']['queried_id'] = 1; update_option(Settings::COMMENTS_MODE_OPTION, 'full');
    $GLOBALS['dbt']['is_admin'] = true; Presentation::enqueue_mapped_discussion(); expect($GLOBALS['dbt']['scripts'] === [] && $GLOBALS['dbt']['styles'] === []);
    $GLOBALS['dbt']['is_admin'] = false; Presentation::enqueue_mapped_discussion(); Presentation::enqueue_mapped_discussion();
    expect(count(array_filter($GLOBALS['dbt']['scripts'], fn($handle) => $handle === 'discussionbridge-discourse-embed')) === 1);
    expect(in_array('discussionbridge-presentation', $GLOBALS['dbt']['styles'], true) && in_array('discussionbridge-discourse-embed', $GLOBALS['dbt']['registered_scripts'], true));
    configured(); $GLOBALS['dbt']['queried_id'] = 1; update_post_meta(1, Materializer::IMPORTED_META, '1'); $body = $GLOBALS['dbt']['posts'][1]->post_content;
    update_option(Settings::RICH_CONTENT_OPTION, 'enabled'); update_post_meta(1, Presentation::RICH_CONTENT_META, 'disabled'); Presentation::enqueue_mapped_discussion();
    expect(!in_array('discussionbridge-rich-content', $GLOBALS['dbt']['scripts'], true) && $GLOBALS['dbt']['posts'][1]->post_content === $body);
    update_option(Settings::RICH_CONTENT_OPTION, 'disabled'); update_post_meta(1, Presentation::RICH_CONTENT_META, 'enabled'); Presentation::enqueue_mapped_discussion();
    expect(in_array('discussionbridge-rich-content', $GLOBALS['dbt']['scripts'], true) && $GLOBALS['dbt']['posts'][1]->post_content === $body);
    $GLOBALS['dbt']['scripts'] = []; $GLOBALS['dbt']['is_singular'] = false; Presentation::enqueue_mapped_discussion(); expect($GLOBALS['dbt']['scripts'] === []);
});

test('selected source-time native query preserves category search status and pagination with exact source ordering and native ID ties', function (): void {
    configured(); expect(class_exists('SQLite3'), 'SQLite3 is required for this isolated query execution fixture');
    $base = ['fields' => 'wp_posts.ID', 'join' => ' INNER JOIN wp_term_relationships tr ON tr.object_id=wp_posts.ID INNER JOIN wp_term_taxonomy tt ON tt.term_taxonomy_id=tr.term_taxonomy_id',
        'where' => "wp_posts.post_status='publish' AND wp_posts.post_type='post' AND tt.taxonomy='category' AND tt.term_id=7 AND wp_posts.post_title LIKE '%needle%'",
        'groupby' => 'wp_posts.ID', 'orderby' => 'wp_posts.post_modified_gmt DESC', 'limits' => 'LIMIT 2 OFFSET 0', 'distinct' => ''];
    expect(Materializer::source_time_query_clauses($base, new WP_Query()) === $base);
    $GLOBALS['dbt']['is_admin'] = true; expect(Materializer::source_time_query_clauses($base, new WP_Query(['discussionbridge_source_modified' => true])) === $base); $GLOBALS['dbt']['is_admin'] = false;
    $selected = Materializer::source_time_query_clauses($base, new WP_Query(['discussionbridge_source_modified' => true]));
    foreach (['fields', 'where', 'groupby', 'limits', 'distinct'] as $key) expect($selected[$key] === $base[$key]);
    $database = new SQLite3(':memory:');
    try {
        $database->enableExceptions(true);
        $database->exec('CREATE TABLE wp_posts(ID INTEGER, post_type TEXT, post_status TEXT, post_title TEXT, post_modified_gmt TEXT)');
        $database->exec('CREATE TABLE wp_postmeta(post_id INTEGER, meta_key TEXT, meta_value TEXT)');
        $database->exec('CREATE TABLE wp_term_relationships(object_id INTEGER, term_taxonomy_id INTEGER)');
        $database->exec('CREATE TABLE wp_term_taxonomy(term_taxonomy_id INTEGER, taxonomy TEXT, term_id INTEGER)');
        $database->exec("INSERT INTO wp_term_taxonomy VALUES(70,'category',7),(80,'category',8)");
        foreach ([[10,'needle alpha','publish',70,500],[11,'needle beta','publish',70,600],[12,'needle gamma','publish',70,1000],[13,'needle native fallback','publish',70,150],[14,'needle other category','publish',80,2000],[15,'excluded title','publish',70,2000],[16,'needle draft','draft',70,2000]] as [$id,$title,$status,$category,$time]) {
            $database->exec("INSERT INTO wp_posts VALUES($id,'post','$status','$title','" . gmdate('Y-m-d H:i:s', $time) . "')"); $database->exec("INSERT INTO wp_term_relationships VALUES($id,$category)");
        }
        foreach ([[10,'190.000'],[10,'200.000'],[11,'200.000'],[12,'100.000'],[14,'999.000'],[15,'999.000'],[16,'999.000']] as [$id,$value]) $database->exec("INSERT INTO wp_postmeta VALUES($id,'_discussionbridge_source_updated_at_timestamp','$value')");
        // SQLite compatibility supplies ONLY MySQL's date primitive; native filter/join/order/pagination execute unchanged.
        $database->createFunction('TIMESTAMPDIFF', fn($unit,$from,$to) => (new DateTimeImmutable($to, new DateTimeZone('UTC')))->getTimestamp() - (new DateTimeImmutable($from, new DateTimeZone('UTC')))->getTimestamp(), 3);
        $order = str_replace('TIMESTAMPDIFF(SECOND,', "TIMESTAMPDIFF('SECOND',", $selected['orderby']); $pages = [];
        foreach ([0,2] as $offset) {
            $query = 'SELECT ' . $selected['fields'] . ' FROM wp_posts' . $selected['join'] . ' WHERE ' . $selected['where'] . ' GROUP BY ' . $selected['groupby'] . ' ORDER BY ' . $order . ' LIMIT 2 OFFSET ' . $offset;
            $result = $database->query($query); $ids = []; while ($row = $result->fetchArray(SQLITE3_ASSOC)) $ids[] = $row['ID']; $result->finalize(); $pages[] = $ids;
        }
        expect($pages === [[11,10],[13,12]], 'source-time order, tie identity or native filter/page preservation failed');
        expect(array_intersect($pages[0], $pages[1]) === [] && count(array_unique(array_merge(...$pages))) === 4);
    } finally { $database->close(); }
});

test('plain text and image-only native articles remain valid complete sources without prose or markup eligibility', function (): void {
    foreach (['Unformatted text é, no HTML tags.', '<img src="images/diagram.png" alt="">'] as $original) {
        configured(); $GLOBALS['dbt']['posts'][1]->post_content = $original; $client = new CmsClient(null, 'to');
        $capture = CmsSourceEdition::capture(1, $client); expect(is_array($capture));
        expect($capture['native_source']->article === $original && $capture['native_source']->article_url === 'https://wordpress.example/native-source/');
        expect($GLOBALS['dbt']['posts'][1]->post_content === $original && $capture['body']->source_content_sha256 === hash('sha256', $capture['complete_content']));
        $delivery = CmsSourceEdition::delivery($capture, $client, null); expect($delivery instanceof stdClass && $delivery->representation->kind === 'full');
        if (str_starts_with($original, '<img')) expect($delivery->content_html === '<img src="https://wordpress.example/native-source/images/diagram.png" alt="">');
        else expect($delivery->content_html === $original);
    }
});

test('complete native preparation preserves captured article-relative link media query fragment and srcset targets', function (): void {
    configured(); $original = '<p class="native">Keep <a href="../guide?part=1&amp;view=full#topic">guide</a> <a href="#section">section</a></p>'
        . '<img src="/uploads/image.png" srcset="small.png 1x, ../large.png 2x, data:image/png;base64,AA== 3x" alt="">'
        . '<video poster="./poster.png" src="//cdn.example/video.mp4"></video>';
    $GLOBALS['dbt']['posts'][1]->post_content = $original; $capture = CmsSourceEdition::capture(1, new CmsClient(null, 'to')); expect(is_array($capture));
    $tags = new WP_HTML_Tag_Processor($capture['complete_content']); expect($tags->next_tag('A'));
    expect($tags->get_attribute('href') === 'https://wordpress.example/guide?part=1&view=full#topic');
    expect($tags->next_tag('A') && $tags->get_attribute('href') === 'https://wordpress.example/native-source/#section');
    expect($tags->next_tag('IMG') && $tags->get_attribute('src') === 'https://wordpress.example/uploads/image.png');
    expect($tags->get_attribute('srcset') === 'https://wordpress.example/native-source/small.png 1x, https://wordpress.example/large.png 2x, data:image/png;base64,AA== 3x');
    expect($tags->next_tag('VIDEO') && $tags->get_attribute('poster') === 'https://wordpress.example/native-source/poster.png' && $tags->get_attribute('src') === 'https://cdn.example/video.mp4');
    expect($GLOBALS['dbt']['posts'][1]->post_content === $original && str_starts_with($capture['complete_content'], '<p class="native">Keep '));
    expect(NativeContent::relative_targets('<a href="#zero">x</a>', 'https://source.example/article?part=0') === '<a href="https://source.example/article?part=0#zero">x</a>');
});

test('snapshot refuses a URL changed during native preparation instead of joining article bytes to a later base', function (): void {
    configured(); $original = $GLOBALS['dbt']['posts'][1]->post_content;
    $GLOBALS['dbt']['native_render_callback'] = function ($body): string { $GLOBALS['dbt']['permalink_prefix'] = 'later/'; return $body; };
    $result = NativeSourceSnapshot::capture(1); expect(is_wp_error($result) && $result->get_error_code() === 'discussionbridge_native_source');
    expect($GLOBALS['dbt']['posts'][1]->post_content === $original && $GLOBALS['dbt']['requests'] === []);
});

test('generated delivery hashes every prepared source byte including resolved references beyond the delivered prefix', function (): void {
    configured(); $original = '<p>' . str_repeat('full source é ', 6000) . '</p><img src="../full-only.png" alt="">';
    $GLOBALS['dbt']['posts'][1]->post_content = $original; $client = new CmsClient(null, 'to'); $capture = CmsSourceEdition::capture(1, $client);
    expect(is_array($capture) && str_contains($capture['complete_content'], 'https://wordpress.example/full-only.png'));
    $delivery = CmsSourceEdition::delivery($capture, $client, null); expect($delivery instanceof stdClass && $delivery->representation->kind === 'generated_excerpt');
    expect($delivery->source_content_bytes === strlen($capture['complete_content']) && $delivery->source_content_sha256 === hash('sha256', $capture['complete_content']));
    expect($delivery->source_content_sha256 !== hash('sha256', $original) && !str_contains($delivery->content_html, 'full-only.png'));
    expect($GLOBALS['dbt']['posts'][1]->post_content === $original);
});

test('new durable native captures preserve original URL and raw proof through pending replay accepted history and fresh snapshot CAS', function (): void {
    configured(); $original = '<img src="images/original.png" alt="">'; $GLOBALS['dbt']['posts'][1]->post_content = $original;
    $state = establish_source(); $first = $state->accepted_history[0];
    expect($state->current_native_source->article === $original && $first->native_source == $state->current_native_source);
    expect($first->native_source->article_url === 'https://wordpress.example/native-source/' && preg_match('/^[0-9a-f]{64}$/', $first->native_source->native_row_sha256) === 1);
    $GLOBALS['dbt']['posts'][1]->post_content = '<p>Next original</p><img src="../next.png">';
    mock('V4-02', new WP_Error('timeout')); FromSynchronizer::serialized(fn($guard) => CmsSourcePublisher::step(1, $guard));
    $pending = (new CmsJournal(1))->read(); $frozen = $pending->pending; $capture = $pending->capture;
    $GLOBALS['dbt']['permalink_prefix'] = 'later/'; $requests = count(array_filter($GLOBALS['dbt']['requests'], fn($r) => $r[1]['method'] === 'POST'));
    expect(is_wp_error(FromSynchronizer::serialized(fn($guard) => CmsSourcePublisher::step(1, $guard))));
    $after = (new CmsJournal(1))->read(); expect($after->pending == $frozen && $after->capture == $capture && $after->accepted_history[0] == $first);
    expect(count(array_filter($GLOBALS['dbt']['requests'], fn($r) => $r[1]['method'] === 'POST')) === $requests);
});

test('canonical-only native URL change cannot reprepare accepted article targets or replace owned ReadMore history', function (): void {
    configured(); $GLOBALS['dbt']['posts'][1]->post_content = '<p>' . str_repeat('article ', 9000) . '</p><img src="relative.png">';
    $state = establish_source(); $record = $state->current_record; $history = $state->accepted_history;
    $GLOBALS['dbt']['permalink_prefix'] = 'changed/';
    $move = CmsSourceMove::prepare(1, 'https://wordpress.example/changed/native-source/', 'fixture-nonce'); expect(is_wp_error($move));
    $after = (new CmsJournal(1))->read(); expect($after->current_record == $record && $after->accepted_history == $history && $after->pending === null);
    expect($record->representation->kind === 'generated_excerpt' && $record->representation->read_more_url === 'https://wordpress.example/native-source/');
});

test('a genuine complete prepared snapshot change uses existing content-derived CAS without inventing native edits or dates', function (): void {
    configured(); $state = establish_source(); $first = $state->accepted_history[0]; $original = $GLOBALS['dbt']['posts'][1]->post_content;
    $dates = [$GLOBALS['dbt']['posts'][1]->post_date_gmt, $GLOBALS['dbt']['posts'][1]->post_modified_gmt];
    // Existing native rendering normalizes markup; this is not a profile/config/nonce manufactured edition.
    $GLOBALS['dbt']['native_render_callback'] = fn($html) => str_replace('<p>', '<p >', $html);
    mock('V4-02', function ($url, $args) use ($first): array {
        $body = json_decode($args['body'])->bridge_record;
        expect($body->expected_source_revision === $first->receipt->source_revision && $body->source_revision !== $first->receipt->source_revision);
        expect($body->source_created_at === $first->record->source_created_at && $body->source_updated_at === $first->record->source_updated_at);
        expect($body->source_content_sha256 === hash('sha256', $body->content_html) && $body->source_content_sha256 !== $first->record->source_content_sha256);
        $receipt = source_receipt($body); $receipt->outcome = 'updated'; $receipt->reason = 'source_revision_updated'; return dbt_response(200, $receipt);
    });
    expect(FromSynchronizer::serialized(fn($guard) => CmsSourcePublisher::step(1, $guard)) === 'healthy'); $next = (new CmsJournal(1))->read();
    expect($GLOBALS['dbt']['posts'][1]->post_content === $original && [$GLOBALS['dbt']['posts'][1]->post_date_gmt, $GLOBALS['dbt']['posts'][1]->post_modified_gmt] === $dates);
    expect($next->accepted_history[0] == $first && count($next->accepted_history) === 2 && $next->current_native_source->article === $original);
});

test('From verifies original source before edition-captured target preparation and retains separate native output evidence on restart', function (): void {
    configured(); [$client, $conn, $job, $state, $save] = native_job();
    $original = '<img src="images/original.png" alt=""><a href="../next#part">next</a>';
    $job->source->topic_url = Settings::server_url() . '/article/captured/';
    $job->source->content_bytes = strlen($original); $job->source->content_sha256 = hash('sha256', $original); $job->hydration->bytes_base64 = base64_encode($original);
    $result = FromSynchronizer::serialized(fn($guard) => CmsNativeWriter::apply($client, $conn, $conn->policy, $job, $state, $save, $guard)); expect($result instanceof stdClass);
    $prepared = $job->native_preparation; $native = $GLOBALS['dbt']['posts'][(int) $result->native_id];
    expect(base64_decode($job->hydration->bytes_base64) === $original && $job->source->content_sha256 === hash('sha256', $original));
    expect(str_contains($native->post_content, Settings::server_url() . '/article/captured/images/original.png') && !str_contains($native->post_content, 'wordpress.example/images'));
    expect($prepared->article_sha256 !== $job->source->content_sha256 && $prepared->body_sha256 === hash('sha256', $native->post_content));
    expect($result->representation->source_content_sha256 === $job->source->content_sha256 && CmsNativeWriter::confirm($client, $job, $state, $conn));
    $job->native_preparation = unserialize(serialize($prepared));
    $job->source->topic_url = Settings::server_url() . '/later/current/'; $before = $native->post_content;
    expect(!CmsNativeWriter::confirm($client, $job, $state, $conn));
    expect(is_wp_error(FromSynchronizer::serialized(fn($guard) => CmsNativeWriter::apply($client, $conn, $conn->policy, $job, $state, $save, $guard))));
    expect($native->post_content === $before && $job->native_preparation == $prepared);
});

test('bad complete From integrity cannot reach reference preparation SQL or a success receipt', function (): void {
    configured(); [$client, $conn, $job, $state, $save] = native_job(); $job->hydration->bytes_base64 = base64_encode('<img src="../tampered.png">');
    $result = FromSynchronizer::serialized(fn($guard) => CmsNativeWriter::apply($client, $conn, $conn->policy, $job, $state, $save, $guard));
    expect(is_wp_error($result) && !isset($job->native_preparation) && $GLOBALS['dbt']['inserted'] === [] && $job->native_result === null);
});

test('unsupported native reference preparation is a precise durable Bridge limitation without source invalidation SQL or receiver Failure', function (): void {
    configured(); $original = '<p style="background-image:url(../picture.png)">Native article</p>'; $GLOBALS['dbt']['posts'][1]->post_content = $original;
    mock('V4-01', connection()); $result = FromSynchronizer::serialized(fn($guard) => CmsSourcePublisher::step(1, $guard));
    expect(is_wp_error($result) && $result->get_error_code() === 'discussionbridge_native_preparation_limitation');
    expect((new CmsJournal(1))->read()->local_issue->observation->reason === 'native_css_reference_preparation_unavailable');
    expect($GLOBALS['dbt']['posts'][1]->post_content === $original && count($GLOBALS['dbt']['requests']) === 1);
    configured(); [$client, $conn, $job, $state, $save] = native_job(); $job->source->content_bytes = strlen($original); $job->source->content_sha256 = hash('sha256', $original); $job->hydration->bytes_base64 = base64_encode($original);
    $result = FromSynchronizer::serialized(fn($guard) => CmsNativeWriter::apply($client, $conn, $conn->policy, $job, $state, $save, $guard));
    expect(is_wp_error($result) && $result->get_error_code() === 'discussionbridge_cms_native_body_limitation');
    expect($job->local_issue->native_write_started === false && base64_decode($job->hydration->bytes_base64) === $original && $GLOBALS['dbt']['inserted'] === [] && $GLOBALS['dbt']['requests'] === []);
    $list = NativeContent::relative_targets('<object archive="one.jar two.jar"></object>', 'https://source.example/article/');
    expect(is_wp_error($list) && $list->get_error_data()['reason'] === 'native_url_list_preparation_unavailable');
});

test('actual stripped interactive function is a specific private limitation while its native original and exact state remain intact', function (): void {
    configured(); $original = '<button onclick="document.querySelector(\'#answer\').hidden=false">Show answer</button><p id="answer" hidden>42</p>';
    $GLOBALS['dbt']['posts'][1]->post_content = $original;
    $GLOBALS['dbt']['native_kses_callback'] = fn($html) => preg_replace('/ onclick="[^"]*"/', '', $html);
    mock('V4-01', connection()); $result = FromSynchronizer::serialized(fn($guard) => CmsSourcePublisher::step(1, $guard));
    expect(is_wp_error($result) && $result->get_error_code() === 'discussionbridge_native_function_preservation');
    $state = (new CmsJournal(1))->read(); expect($state->local_issue->observation->kind === 'event_handler' && $state->local_issue->observation->attribute === 'onclick');
    expect($state->pending === null && $GLOBALS['dbt']['posts'][1]->post_content === $original && count($GLOBALS['dbt']['requests']) === 1);
    configured(); $GLOBALS['dbt']['posts'][1]->post_content = '<script>document.getElementById("answer").hidden=false;</script><p id="answer">Answer</p>';
    mock('V4-01', connection()); $result = FromSynchronizer::serialized(fn($guard) => CmsSourcePublisher::step(1, $guard));
    expect(is_wp_error($result) && $result->get_error_data()['kind'] === 'script_function');
});

test('escaped displayed code and supported native details retain source while data scripts are not misreported as executable loss', function (): void {
    configured(); $original = '<pre><code>&lt;script&gt;example()&lt;/script&gt; &lt;button onclick="example()"&gt;</code></pre>'
        . '<!-- <script>example()</script> --><details><summary>Show answer</summary><p>42</p></details>';
    // The isolated KSES fixture removes regex script-looking text in comments; real parsed observation still must not call that active loss.
    $GLOBALS['dbt']['posts'][1]->post_content = $original; $capture = CmsSourceEdition::capture(1, new CmsClient(null, 'to')); expect(is_array($capture));
    expect(str_contains($capture['complete_content'], '&lt;script&gt;example()&lt;/script&gt;') && str_contains($capture['complete_content'], '<details><summary>Show answer</summary><p>42</p></details>'));
    expect($GLOBALS['dbt']['posts'][1]->post_content === $original);
    expect(NativeContent::function_loss('<script type="application/json">{"onclick":"data"}</script>', '') === null);
    expect(NativeContent::function_loss('<script type="application/ld+json">{"name":"data"}</script>', '') === null);
    $GLOBALS['dbt']['posts'][1]->post_content = '<script type="application/json">{"data":42}</script>';
    $removed = CmsSourceEdition::capture(1, new CmsClient(null, 'to'));
    expect(is_wp_error($removed) && $removed->get_error_code() === 'discussionbridge_native_data_preservation' && $removed->get_error_data()['kind'] === 'script_data');
});

test('From final native function loss is observed before SQL and never acknowledged as a working interactive publication', function (): void {
    configured(); [$client, $conn, $job, $state, $save] = native_job(); $original = '<button onclick="this.textContent=\'42\'">Answer</button>';
    $job->source->content_bytes = strlen($original); $job->source->content_sha256 = hash('sha256', $original); $job->hydration->bytes_base64 = base64_encode($original);
    $GLOBALS['dbt']['native_final_data_callback'] = function (array $data): array { $data['post_content'] = wp_slash(preg_replace('/ onclick="[^"]*"/', '', wp_unslash($data['post_content']))); return $data; };
    $result = FromSynchronizer::serialized(fn($guard) => CmsNativeWriter::apply($client, $conn, $conn->policy, $job, $state, $save, $guard));
    expect(is_wp_error($result) && $result->get_error_code() === 'discussionbridge_cms_native_body_limitation');
    expect($job->local_issue->observation->function_loss['kind'] === 'event_handler' && $GLOBALS['dbt']['inserted'] === [] && $job->native_result === null);
    expect(base64_decode($job->hydration->bytes_base64) === $original && $GLOBALS['dbt']['requests'] === []);
});

test('parsed nonempty inert article data removed alongside prose reports specific DATA loss on To and final From without executing it', function (): void {
    configured(); $original = '<p>Article context</p><script type="application/json">{"answer":42,"display":"é"}</script>';
    $GLOBALS['dbt']['posts'][1]->post_content = $original; mock('V4-01', connection());
    $result = FromSynchronizer::serialized(fn($guard) => CmsSourcePublisher::step(1, $guard));
    expect(is_wp_error($result) && $result->get_error_code() === 'discussionbridge_native_data_preservation');
    $state = (new CmsJournal(1))->read(); expect($state->local_issue->observation->kind === 'script_data' && $state->local_issue->observation->type === 'application/json');
    expect($state->local_issue->observation->data_sha256 === hash('sha256', '{"answer":42,"display":"é"}') && $state->pending === null);
    expect($GLOBALS['dbt']['posts'][1]->post_content === $original && count($GLOBALS['dbt']['requests']) === 1);
    expect(NativeContent::data_loss($original, $original) === null && NativeContent::function_loss($original, '<p>Article context</p>') === null);
    expect(NativeContent::data_loss('<pre>&lt;script type="application/json"&gt;data&lt;/script&gt;</pre>', '<pre>&lt;script type="application/json"&gt;data&lt;/script&gt;</pre>') === null);
    configured(); [$client, $conn, $job, $state, $save] = native_job(); $job->source->content_bytes = strlen($original); $job->source->content_sha256 = hash('sha256', $original); $job->hydration->bytes_base64 = base64_encode($original);
    $GLOBALS['dbt']['native_final_data_callback'] = function (array $data): array { $data['post_content'] = wp_slash(preg_replace('#<script\\b[^>]*>.*?</script>#is', '', wp_unslash($data['post_content']))); return $data; };
    $result = FromSynchronizer::serialized(fn($guard) => CmsNativeWriter::apply($client, $conn, $conn->policy, $job, $state, $save, $guard));
    expect(is_wp_error($result) && $result->get_error_code() === 'discussionbridge_cms_native_body_limitation');
    expect($job->local_issue->observation->function_loss === null && $job->local_issue->observation->data_loss['kind'] === 'script_data');
    expect($GLOBALS['dbt']['inserted'] === [] && $job->native_result === null && base64_decode($job->hydration->bytes_base64) === $original && $GLOBALS['dbt']['requests'] === []);
});

function preservation_error(): stdClass {
    return (object) ['outcome' => 'rejected', 'code' => 'content_preservation_unavailable', 'retryable' => false,
        'retry_after_seconds' => null, 'correlation_id' => null, 'core_fallback' => false];
}

test('actual typed client retains exact permanent preservation Error only after strict response decoding', function (): void {
    configured(); $client = new CmsClient(null, 'to'); $snapshot = CmsSourceEdition::capture(1, $client);
    $body = CmsSourceEdition::delivery($snapshot, $client, null); $options = ['body' => (object) ['mutation_id' => wp_generate_uuid4(), 'bridge_record' => $body]];
    mock('V4-02', preservation_error(), 422); $result = $client->request('V4-02', $options);
    expect(is_wp_error($result) && $result->get_error_code() === 'discussionbridge_cms_rejected');
    $data = $result->get_error_data(); expect($data['retryable'] === false && $data['retry_after_seconds'] === null);
    expect($data['preservation_rejection']->route === 'V4-02' && $data['preservation_rejection']->http_status === 422 && $data['preservation_rejection']->error == preservation_error());
    $extra = preservation_error(); $extra->detail = 'not a declared field';
    $retry = preservation_error(); $retry->retryable = true;
    foreach ([[$extra, 422], [$retry, 422], [preservation_error(), 400]] as [$error, $status]) {
        mock('V4-02', $error, $status); $bad = $client->request('V4-02', $options);
        expect(is_wp_error($bad) && $bad->get_error_code() === 'discussionbridge_cms_invalid_response' && !isset($bad->get_error_data()['preservation_rejection']));
    }
    $codec = $client->codec(); expect($codec->validateResponse('V4-29', preservation_error(), 422) == preservation_error());
    foreach (range(1, 31) as $number) {
        $route = sprintf('V4-%02d', $number); if (in_array($route, ['V4-02', 'V4-29'], true)) continue;
        try { $codec->validateResponse($route, preservation_error(), 422); expect(false, 'new Error leaked to ' . $route); }
        catch (\DiscussionBridge\CmsV4\ValidationException) {}
    }
    $failure = fixture('failure'); $failure->code = 'content_preservation_unavailable'; $failure->retry_after_seconds = null;
    try { $codec->validate('Failure', $failure); expect(false, 'new Error accepted as From Failure'); }
    catch (\DiscussionBridge\CmsV4\ValidationException) {}
    $work = fixture('lease')->work; $work->last_failure_code = 'content_preservation_unavailable';
    try { $codec->validate('Work', $work); expect(false, 'new Error accepted as From Work attention'); }
    catch (\DiscussionBridge\CmsV4\ValidationException) {}
});

test('malformed permanent-looking response remains uncertain and preserves exact native request for bounded transport recovery', function (): void {
    configured(); mock('V4-01', connection()); mock('V4-03', (object) ['items' => [], 'next_cursor' => null]);
    $bad = preservation_error(); $bad->detail = 'invalid envelope'; mock('V4-02', $bad, 422);
    $result = FromSynchronizer::serialized(fn($guard) => CmsSourcePublisher::step(1, $guard));
    expect(is_wp_error($result) && $result->get_error_code() === 'discussionbridge_cms_invalid_response');
    $old = (new CmsJournal(1))->read(); expect(!isset($old->terminal) && !isset($old->pending->rejection));
    $first = end($GLOBALS['dbt']['requests'])[1]['body']; mock('V4-02', new WP_Error('timeout'));
    $result = FromSynchronizer::serialized(fn($guard) => CmsSourcePublisher::step(1, $guard));
    expect(is_wp_error($result) && $result->get_error_code() === 'discussionbridge_cms_transport');
    expect(end($GLOBALS['dbt']['requests'])[1]['body'] === $first && (new CmsJournal(1))->read()->pending == $old->pending);
});

test('definitive mutation outcome is encrypted with exact request before outer terminal and restart cannot retransmit it', function (): void {
    configured(); $client = new CmsClient(null, 'to'); $snapshot = CmsSourceEdition::capture(1, $client);
    $body = CmsSourceEdition::delivery($snapshot, $client, null); $state = (object) ['pending' => null]; $store = new CmsJournal(1);
    $save = fn(): bool => $store->write($state, fn() => true); mock('V4-02', preservation_error(), 422);
    $options = ['body' => (object) ['bridge_record' => $body]];
    $result = CmsMutation::perform($client, 'V4-02', $options, $state, $save); expect(is_wp_error($result));
    $persisted = $store->read(); expect(CmsMutation::preservation_rejected($persisted) && !isset($persisted->terminal));
    expect($persisted->pending->rejection->error == preservation_error() && $persisted->pending->rejection->artifacts == CmsArtifacts::binding());
    expect($persisted->pending->rejection->request_identity === $persisted->pending->identity && str_starts_with(get_option($store->option_name()), 'gcm1:'));
    $state = $store->read(); $client = new CmsClient(null, 'to'); $before = count($GLOBALS['dbt']['requests']);
    $result = CmsMutation::perform($client, 'V4-02', $options, $state, $save);
    expect(is_wp_error($result) && $result->get_error_data()['retryable'] === false && count($GLOBALS['dbt']['requests']) === $before && $store->read() == $persisted);
});

test('crash at later terminal persistence retains earlier definitive rejection and no native save or manual queue resends it', function (): void {
    configured(); mock('V4-01', connection()); mock('V4-03', (object) ['items' => [], 'next_cursor' => null]); mock('V4-02', preservation_error(), 422);
    $store = new CmsJournal(1); $GLOBALS['dbt']['option_write_callback'] = function ($key, $value) use ($store): bool {
        if ($key !== $store->option_name()) return true;
        $old = $store->read(); return !$old instanceof stdClass || !CmsMutation::preservation_rejected($old);
    };
    $result = FromSynchronizer::serialized(fn($guard) => CmsSourcePublisher::step(1, $guard));
    expect(is_wp_error($result) && $result->get_error_code() === 'discussionbridge_cms_to_state_persistence');
    $saved = $store->read(); expect(CmsMutation::preservation_rejected($saved) && !isset($saved->terminal));
    $GLOBALS['dbt']['option_write_callback'] = null;
    $before = count(array_filter($GLOBALS['dbt']['requests'], fn($r) => $r[1]['method'] === 'POST'));
    Publisher::post_saved(1, $GLOBALS['dbt']['posts'][1], true, null); $GLOBALS['dbt']['scheduled'] = []; Publisher::deliver(1);
    Publisher::queue(1, true); $GLOBALS['dbt']['scheduled'] = []; Publisher::deliver(1);
    expect($store->read()->pending == $saved->pending && $store->read()->capture == $saved->capture && $GLOBALS['dbt']['scheduled'] === []);
    expect(count(array_filter($GLOBALS['dbt']['requests'], fn($r) => $r[1]['method'] === 'POST')) === $before);
});

test('known permanent preservation failure schedules no automatic retry and does not block another native source', function (): void {
    configured(); mock('V4-01', connection()); mock('V4-03', (object) ['items' => [], 'next_cursor' => null]); mock('V4-02', preservation_error(), 422);
    Publisher::deliver(1); $failed = (new CmsJournal(1))->read();
    expect($GLOBALS['dbt']['scheduled'] === [] && CmsMutation::preservation_rejected($failed) && $GLOBALS['dbt']['meta'][1]['_discussionbridge_status'] === 'attention');
    $GLOBALS['dbt']['posts'][2] = new WP_Post(2, 'publish', 'post', 'Another source', '<p>Independent article</p>', 9, 'another-source');
    $GLOBALS['dbt']['meta'][2] = [Publisher::ENABLED_META => '1'];
    mock('V4-02', function ($url, $args): array { return dbt_response(201, source_receipt(json_decode($args['body'])->bridge_record)); });
    Publisher::post_saved(2, $GLOBALS['dbt']['posts'][2], false, null); $GLOBALS['dbt']['scheduled'] = []; Publisher::deliver(2);
    expect((new CmsJournal(2))->read()->receipt instanceof stdClass && (new CmsJournal(1))->read()->pending == $failed->pending);
});

test('genuine new unbound source snapshot keeps rejected creation immutable and reruns native collision inventory without fabricated detail', function (): void {
    configured(); mock('V4-01', connection()); mock('V4-03', (object) ['items' => [], 'next_cursor' => null]); mock('V4-02', preservation_error(), 422);
    FromSynchronizer::serialized(fn($guard) => CmsSourcePublisher::step(1, $guard)); $old = (new CmsJournal(1))->read();
    $GLOBALS['dbt']['posts'][1]->post_content = '<p>Actual faithfully prepared new source</p>';
    mock('V4-02', function ($url, $args) use ($old): array {
        $request = json_decode($args['body']); expect($request->mutation_id !== $old->pending->options->body->mutation_id);
        expect($request->bridge_record->expected_source_revision === null && $request->bridge_record->source_revision !== $old->capture->body->source_revision);
        return dbt_response(201, source_receipt($request->bridge_record));
    });
    expect(FromSynchronizer::serialized(fn($guard) => CmsSourcePublisher::step(1, $guard)) === 'healthy');
    $new = (new CmsJournal(1))->read(); expect($new->rejected_history[0]->request == $old->pending && $new->rejected_history[0]->capture == $old->capture);
    expect($new->rejected_history[0]->receipt === null && count($new->accepted_history) === 1);
    $get = array_map(fn($r) => $r[0], array_filter($GLOBALS['dbt']['requests'], fn($r) => $r[1]['method'] === 'GET'));
    expect(count(array_filter($get, fn($url) => $url === Settings::server_url() . CmsArtifacts::codec()->route('V4-03')['path'])) === 2);
    expect(count(array_filter($get, fn($url) => preg_match('#/bridge-records/[0-9a-f-]+[.]json$#', $url) === 1)) === 0);
});

test('changed bound source snapshot rechecks actual accepted predecessor before ordinary CAS while preserving rejected edition and earlier receipt', function (): void {
    configured(); $accepted = establish_source(); $GLOBALS['dbt']['posts'][1]->post_content = '<p>Rejected edition B</p>';
    mock('V4-02', preservation_error(), 422); FromSynchronizer::serialized(fn($guard) => CmsSourcePublisher::step(1, $guard)); $old = (new CmsJournal(1))->read();
    $record = record_for($accepted); mock('V4-04', $record, 200, '', ['resource_id' => $record->resource_id]);
    $GLOBALS['dbt']['posts'][1]->post_title = 'Actual new native title';
    mock('V4-02', function ($url, $args) use ($accepted, $old): array {
        $request = json_decode($args['body']); $body = $request->bridge_record;
        expect($request->mutation_id !== $old->pending->options->body->mutation_id && $body->expected_source_revision === $accepted->receipt->source_revision && $body->source_revision !== $old->capture->body->source_revision);
        $receipt = source_receipt($body); $receipt->outcome = 'updated'; $receipt->reason = 'source_revision_updated'; return dbt_response(200, $receipt);
    });
    expect(FromSynchronizer::serialized(fn($guard) => CmsSourcePublisher::step(1, $guard)) === 'healthy');
    $new = (new CmsJournal(1))->read(); expect($new->rejected_history[0]->request == $old->pending && $new->rejected_history[0]->capture == $old->capture && $new->rejected_history[0]->receipt == $accepted->receipt);
    expect($new->accepted_history[0] == $accepted->accepted_history[0] && count($new->accepted_history) === 2 && $new->receipt->resource_id === $accepted->receipt->resource_id && $new->receipt->topic_id === $accepted->receipt->topic_id);
});

test('divergent current receiver predecessor retains rejected request and changed source with no new CAS mutation', function (): void {
    configured(); $accepted = establish_source(); $GLOBALS['dbt']['posts'][1]->post_content = '<p>Rejected B</p>';
    mock('V4-02', preservation_error(), 422); FromSynchronizer::serialized(fn($guard) => CmsSourcePublisher::step(1, $guard)); $old = (new CmsJournal(1))->read();
    $record = record_for($accepted); $record->source_revision = 'unknown-current-edition'; mock('V4-04', $record, 200, '', ['resource_id' => $record->resource_id]);
    $GLOBALS['dbt']['posts'][1]->post_content = '<p>Genuine C requires known current predecessor</p>';
    $before = count(array_filter($GLOBALS['dbt']['requests'], fn($r) => $r[1]['method'] === 'POST'));
    $result = FromSynchronizer::serialized(fn($guard) => CmsSourcePublisher::step(1, $guard));
    expect(is_wp_error($result) && $result->get_error_code() === 'discussionbridge_cms_to_rejected_predecessor_reconciliation');
    $new = (new CmsJournal(1))->read(); expect($new->pending == $old->pending && $new->capture == $old->capture && $new->accepted_history == $old->accepted_history && !isset($new->rejected_history));
    expect(count(array_filter($GLOBALS['dbt']['requests'], fn($r) => $r[1]['method'] === 'POST')) === $before);
});

test('failed definitive-outcome storage is reported as undurable and schedules no automatic retry or invented accepted state', function (): void {
    configured(); mock('V4-01', connection()); mock('V4-03', (object) ['items' => [], 'next_cursor' => null]);
    mock('V4-02', function (): array { $GLOBALS['dbt']['option_write_callback'] = fn() => false; return dbt_response(422, preservation_error()); });
    Publisher::deliver(1); $old = (new CmsJournal(1))->read();
    expect($GLOBALS['dbt']['meta'][1]['_discussionbridge_last_reason'] === 'discussionbridge_cms_rejection_persistence' && $GLOBALS['dbt']['scheduled'] === []);
    expect($old->pending instanceof stdClass && !isset($old->pending->rejection) && !isset($old->terminal) && $old->receipt === null && $old->accepted_history === []);
    expect(count(array_filter($GLOBALS['dbt']['requests'], fn($r) => $r[1]['method'] === 'POST')) === 1);
});

function source_move_model(bool $generated = false, bool $from_policy_enabled = true, ?string $article = null, ?string $origin = null): stdClass {
    configured(); $GLOBALS['dbt']['native_update_merge'] = true;
    if ($origin !== null) $GLOBALS['dbt']['native_origin'] = $origin;
    $GLOBALS['dbt']['posts'][1]->post_content = $article ?? ('<p>' . ($generated ? str_repeat('Whole native article é ', 5500) : 'Whole native article é') . '</p><img src="images/original.png">');
    $accepted = establish_source(); $connection = connection(); $connection->policy->enabled = $from_policy_enabled; mock('V4-01', $connection);
    mock('V4-28', function ($url, $args) use ($accepted): array {
        $body = json_decode($args['body']); expect($body->expected_source_revision === $accepted->receipt->source_revision);
        $move = fixture('source_url_move'); $move->resource_id = $accepted->receipt->resource_id; $move->expected_source_revision = $body->expected_source_revision;
        $move->old_url = $body->old_url; $move->new_url = $body->new_url; $move->state = 'prepared';
        $move->prepared_at = gmdate('Y-m-d\TH:i:s', time() - 2) . '.000Z'; return dbt_response(201, $move);
    }, 201, '', ['resource_id' => $accepted->receipt->resource_id]);
    $prepared = CmsSourceMove::prepare(1, home_url('/moved-source/'), 'fixture-nonce'); expect($prepared === 'working', is_wp_error($prepared) ? $prepared->get_error_code() : 'prepare');
    $stage = FromSynchronizer::serialized(fn($guard) => CmsSourcePublisher::step(1, $guard)); expect($stage === 'awaiting_actual_native_render', is_wp_error($stage) ? $stage->get_error_code() : '28');
    return $accepted;
}
function source_theme_render(): string {
    $GLOBALS['dbt']['queried_id'] = 1; $GLOBALS['more'] = 1; $GLOBALS['post'] = get_post(1);
    $template = __DIR__ . '/fixtures/source-article-theme/' . ($GLOBALS['dbt']['source_template_file'] ?? 'single.php'); CmsSourceArticleProfile::template($template);
    ob_start(); try { include $template; return (string) ob_get_contents(); } finally { ob_end_clean(); }
}
function source_finalize_mock(stdClass $old): void {
    $move = (new CmsJournal(1))->read()->source_move;
    $GLOBALS['dbt']['responses'][$move->request->body->new_url] = function (): array { return dbt_response(200, '<html><body>' . source_theme_render() . '</body></html>', 'text/html; charset=UTF-8'); };
    mock('V4-29', function ($url, $args) use ($old): array {
        $body = json_decode($args['body']); $record = record_for($old);
        $record->canonical_url = $body->verification->url; $record->source_revision = $body->new_source_revision; $record->source_updated_at = $body->source_updated_at;
        return dbt_response(200, $record);
    }, 200, '', ['resource_id' => $old->receipt->resource_id, 'move_id' => $move->receipt->move_id]);
}

test('source move prospective complete snapshot and distinct signed To receipt refuse missing full generated evidence without reconstruction', function (): void {
    configured(); $GLOBALS['dbt']['posts'][1]->post_content = '<p>' . str_repeat('complete source ', 6000) . '</p>'; $accepted = establish_source();
    $source = $accepted->current_native_source;
    expect(CmsSourceOwnership::complete($source, $accepted->current_record) && strlen($source->prepared_article) > strlen($accepted->current_record->content_html));
    expect(CmsSourceOwnership::metadata(1) !== null && get_post_meta(1, CmsPresentation::META, true) === '');
    $historical = $accepted->accepted_history; unset($source->prepared_article);
    expect((new CmsJournal(1))->write($accepted, fn() => true));
    $before = count($GLOBALS['dbt']['requests']); $result = CmsSourceMove::prepare(1, 'https://wordpress.example/next/', 'fixture-nonce');
    expect(is_wp_error($result) && $result->get_error_code() === 'discussionbridge_source_ownership_complete_receipt_unavailable');
    expect(count($GLOBALS['dbt']['requests']) === $before && (new CmsJournal(1))->read()->accepted_history == $historical);
});

test('source move unsupported native permalink and actual collision fail before reservation without route changes', function (): void {
    configured(); establish_source(); $GLOBALS['dbt']['sample_permalink'] = ['https://wordpress.example/?p=1', 'next'];
    $before = count($GLOBALS['dbt']['requests']); expect(is_wp_error(CmsSourceMove::prepare(1, 'https://wordpress.example/next/', 'fixture-nonce')));
    expect(count($GLOBALS['dbt']['requests']) === $before && !isset((new CmsJournal(1))->read()->source_move));
    unset($GLOBALS['dbt']['sample_permalink']); $GLOBALS['dbt']['posts'][2] = new WP_Post(2, 'publish', 'post', 'Other', 'Other', 9, 'next');
    expect(is_wp_error(CmsSourceMove::prepare(1, 'https://wordpress.example/next/', 'fixture-nonce')) && count($GLOBALS['dbt']['requests']) === $before);
});

test('source move real model render is required before SQL and a partial or non-main article never qualifies', function (): void {
    source_move_model(); $old = (new CmsJournal(1))->read();
    expect(FromSynchronizer::serialized(fn($guard) => CmsSourcePublisher::step(1, $guard)) === 'awaiting_actual_native_render');
    expect($GLOBALS['dbt']['inserted'] === [] && !isset($GLOBALS['dbt']['source_theme_renders']));
    $GLOBALS['dbt']['main_query'] = false; source_theme_render(); expect((new CmsJournal(1))->read()->source_move->stage === 'awaiting_actual_native_render');
    $GLOBALS['dbt']['main_query'] = true; $GLOBALS['dbt']['queried_id'] = 1; $GLOBALS['more'] = 1;
    CmsSourceArticleProfile::template(__DIR__ . '/fixtures/source-article-theme/single.php'); CmsSourceArticleProfile::content($GLOBALS['dbt']['posts'][1]->post_content);
    $partial = '<article data-discussionbridge-source-article="wordpress-source-article-1"><h1 data-discussionbridge-source-title>Actual native title</h1><span data-discussionbridge-source-author-id="wordpress-user:' . Settings::site_id() . ':9">Source Author</span><div data-discussionbridge-source-body><p>excerpt</p></div></article>';
    expect(CmsSourceArticleProfile::article($partial) === $partial && (new CmsJournal(1))->read()->source_move->stage === 'awaiting_actual_native_render');
    source_theme_render(); $new = (new CmsJournal(1))->read(); expect($new->source_move->stage === 'render_expected_captured' && $new->source_move->accepted_record == $old->current_record);
});

test('source move full same-post URL commit and independent whole article proof preserve body IDs dates targets and later29 revision', function (): void {
    $old = source_move_model(); $native = clone $GLOBALS['dbt']['posts'][1]; $editor = CmsNativeFence::token(1);
    source_theme_render(); source_finalize_mock($old); $GLOBALS['dbt']['scheduled'] = [];
    $result = FromSynchronizer::serialized(fn($guard) => CmsSourcePublisher::step(1, $guard)); expect($result === 'healthy', is_wp_error($result) ? $result->get_error_code() . ' ' . json_encode($result->get_error_data()) : (string) $result);
    $state = (new CmsJournal(1))->read(); $move = $state->source_move_history[0];
    expect($GLOBALS['dbt']['posts'][1]->ID === $native->ID && $GLOBALS['dbt']['posts'][1]->post_content === $native->post_content && $GLOBALS['dbt']['posts'][1]->post_title === $native->post_title);
    expect($state->receipt->resource_id === $old->receipt->resource_id && $state->receipt->topic_id === $old->receipt->topic_id && $state->current_record->content_html === $old->current_record->content_html);
    expect($state->current_record->source_content_sha256 === $old->current_record->source_content_sha256 && $state->current_record->source_updated_at === null && $state->current_record->source_revision !== $old->current_record->source_revision);
    expect($move->verification->native_publication_sha256 !== $state->current_record->source_content_sha256 && $move->verification->native_ownership_receipt_sha256 === CmsSourceOwnership::digest($move->native_receipt));
    expect($GLOBALS['dbt']['source_theme_renders'] === 2 && $GLOBALS['dbt']['scheduled'] === [] && $old->accepted_history[0] == $state->accepted_history[0]);
    $current = CmsSourceEdition::capture(1, new CmsClient(null, 'to')); expect(is_array($current) && $current['body']->source_revision === $state->receipt->source_revision && $current['complete_content'] === $old->current_native_source->prepared_article);
    expect(str_contains($current['complete_content'], 'https://wordpress.example/native-source/images/original.png'));
    $later_page = source_theme_render();
    expect(str_contains($later_page, 'src="https://wordpress.example/native-source/images/original.png"') && !str_contains($later_page, 'data-proof-nonce'));
    $stale = CmsNativeFence::with_expected(1, $editor, fn() => wp_update_post(['ID' => 1, 'post_content' => '<p>Stale before move</p>'], true)); expect(is_wp_error($stale));
    expect($GLOBALS['dbt']['posts'][1]->post_content === $native->post_content && !isset($state->source_move) && $state->pending === null);
    foreach ($GLOBALS['dbt']['requests'] as [$url, $args]) if ($url === 'https://wordpress.example/moved-source/') expect($args['redirection'] === 0 && $args['cookies'] === [] && !isset($args['headers']['X-DiscussionBridge-Secret']));
});

test('source move generated excerpt changes only owned ReadMore and retains entire native prepared article and selection history', function (): void {
    $old = source_move_model(true); source_theme_render(); source_finalize_mock($old);
    $result = FromSynchronizer::serialized(fn($guard) => CmsSourcePublisher::step(1, $guard)); expect($result === 'healthy', is_wp_error($result) ? $result->get_error_code() : (string) $result);
    $state = (new CmsJournal(1))->read(); $record = $state->current_record; $prior = $old->current_record;
    expect($record->representation->kind === 'generated_excerpt' && $record->source_content_bytes === $prior->source_content_bytes && $record->source_content_sha256 === $prior->source_content_sha256);
    expect(substr($record->content_html, 0, $record->representation->article_content_bytes) === substr($prior->content_html, 0, $prior->representation->article_content_bytes));
    expect($record->representation->selection_source_revision === $prior->representation->selection_source_revision && $record->representation->selection_full_request_bytes === $prior->representation->selection_full_request_bytes);
    expect($record->representation->read_more_url === 'https://wordpress.example/moved-source/' && str_ends_with($record->content_html, CmsArtifacts::codec()->generatedReadMore($record->canonical_url)));
    expect($state->current_native_source->prepared_article === $old->current_native_source->prepared_article && $state->current_native_source->article_url === $old->current_native_source->article_url);
});

test('source move final native hook field mutation veto rolls back before SQL and truthful30 preserves old binding', function (): void {
    $old = source_move_model(); source_theme_render();
    $GLOBALS['dbt']['native_final_data_callback'] = function (array $data): array { $data['post_title'] = 'Unrelated rewrite'; return $data; };
    $result = FromSynchronizer::serialized(fn($guard) => CmsSourcePublisher::step(1, $guard)); expect(is_wp_error($result));
    $state = (new CmsJournal(1))->read(); expect($state->source_move->stage === 'native_not_applied' && $state->source_move->native_issue->rollback_proven && $GLOBALS['dbt']['inserted'] === []);
    expect(get_permalink(1) === $old->current_record->canonical_url && $state->receipt == $old->receipt && $GLOBALS['dbt']['native_lock'] === null);
    $move = $state->source_move->receipt; $aborted = clone $move; $aborted->state = 'aborted';
    mock('V4-30', $aborted, 200, '', ['resource_id' => $old->receipt->resource_id, 'move_id' => $move->move_id]);
    expect(CmsSourceMove::abort(1, 'fixture-nonce') === 'aborted');
    $after = (new CmsJournal(1))->read(); expect(!isset($after->source_move) && $after->receipt == $old->receipt && $after->source_move_history[0]->abort_response->state === 'aborted');
});

test('source move pending native commit after crash never performs a second operation or releases uncertain reservations', function (): void {
    source_move_model(); source_theme_render(); $state = (new CmsJournal(1))->read(); $state->source_move->stage = 'native_commit_pending';
    expect((new CmsJournal(1))->write($state, fn() => true)); $before = count($GLOBALS['dbt']['requests']);
    $result = FromSynchronizer::serialized(fn($guard) => CmsSourcePublisher::step(1, $guard)); expect(is_wp_error($result) && $result->get_error_code() === 'discussionbridge_cms_to_source_move_native_commit_reconciliation');
    expect($GLOBALS['dbt']['inserted'] === [] && is_wp_error(CmsSourceMove::abort(1, 'fixture-nonce')) && (new CmsJournal(1))->read()->source_move->receipt == $state->source_move->receipt);
    expect(count(array_filter(array_slice($GLOBALS['dbt']['requests'], $before), fn($r) => $r[1]['method'] === 'POST')) === 0);
});

test('source move independent verifier rejects copied markers changed whole article wrong nonce redirect and private responses', function (): void {
    $old = source_move_model(); source_theme_render();
    $GLOBALS['dbt']['responses']['https://wordpress.example/moved-source/'] = dbt_response(503, '', 'text/html');
    expect(is_wp_error(FromSynchronizer::serialized(fn($guard) => CmsSourcePublisher::step(1, $guard))));
    $state = (new CmsJournal(1))->read(); expect($state->source_move->stage === 'native_applied'); $page = source_theme_render();
    foreach ([dbt_response(200, str_replace('Whole native article é', 'Changed whole article', $page), 'text/html'),
        dbt_response(200, str_replace($state->source_move->receipt->proof_nonce, 'wrong_nonce', $page), 'text/html'), dbt_response(302, $page, 'text/html'), dbt_response(200, '<p>Login required</p>', 'text/html')] as $response) {
        $GLOBALS['dbt']['responses']['https://wordpress.example/moved-source/'] = $response;
        expect(is_wp_error(CmsSourcePublicVerifier::verify(1, $state, fn() => true)));
    }
    $GLOBALS['dbt']['responses']['https://wordpress.example/moved-source/'] = dbt_response(200, '<html>' . $page . '</html>', 'text/html');
    expect(CmsSourcePublicVerifier::verify(1, $state, fn() => true) instanceof stdClass && is_wp_error(CmsSourceMove::abort(1, 'fixture-nonce')));
});

test('source move uncertain finalize replays exact UUID tree native proof and no second native update after restart', function (): void {
    $old = source_move_model(); source_theme_render(); source_finalize_mock($old); $move = (new CmsJournal(1))->read()->source_move;
    mock('V4-29', new WP_Error('timeout'), 200, '', ['resource_id' => $old->receipt->resource_id, 'move_id' => $move->receipt->move_id]);
    expect(is_wp_error(FromSynchronizer::serialized(fn($guard) => CmsSourcePublisher::step(1, $guard))));
    $pending = (new CmsJournal(1))->read()->pending; $first = end($GLOBALS['dbt']['requests'])[1]['body']; $writes = count($GLOBALS['dbt']['inserted']);
    source_finalize_mock($old); expect(FromSynchronizer::serialized(fn($guard) => CmsSourcePublisher::step(1, $guard)) === 'healthy');
    expect(end($GLOBALS['dbt']['requests'])[1]['body'] === $first && count($GLOBALS['dbt']['inserted']) === $writes);
    expect((new CmsJournal(1))->read()->source_move_history[0]->finalize_mutation == $pending);
});

test('source move definitive29 rejection retains applied native state exact pending and reservations without automatic retry or abort', function (): void {
    $old = source_move_model(); source_theme_render(); source_finalize_mock($old); $move = (new CmsJournal(1))->read()->source_move;
    mock('V4-29', preservation_error(), 422, '', ['resource_id' => $old->receipt->resource_id, 'move_id' => $move->receipt->move_id]);
    Publisher::deliver(1); $state = (new CmsJournal(1))->read(); expect(CmsMutation::preservation_rejected($state) && $state->source_move->native_operation === 'applied');
    $post_count = count(array_filter($GLOBALS['dbt']['requests'], fn($r) => $r[1]['method'] === 'POST')); $writes = count($GLOBALS['dbt']['inserted']);
    $GLOBALS['dbt']['scheduled'] = []; Publisher::deliver(1); expect($GLOBALS['dbt']['scheduled'] === [] && is_wp_error(CmsSourceMove::abort(1, 'fixture-nonce')));
    expect(count(array_filter($GLOBALS['dbt']['requests'], fn($r) => $r[1]['method'] === 'POST')) === $post_count && count($GLOBALS['dbt']['inserted']) === $writes);
    expect((new CmsJournal(1))->read()->receipt == $old->receipt && (new CmsJournal(1))->read()->pending == $state->pending);
});

test('source move positive sealed discovery restores lost eligible schedule but never an uncertain native commit', function (): void {
    source_move_model(); source_theme_render(); $GLOBALS['dbt']['scheduled'] = []; expect(recover_source() === true && count($GLOBALS['dbt']['scheduled']) === 1);
    $state = (new CmsJournal(1))->read(); $state->source_move->stage = 'native_commit_pending'; expect((new CmsJournal(1))->write($state, fn() => true));
    $GLOBALS['dbt']['scheduled'] = []; expect(recover_source() === true && $GLOBALS['dbt']['scheduled'] === []);
});

test('source move actual changed native date and later unchanged02 retain accepted29 edition and genuine later authoring uses its predecessor', function (): void {
    $old = source_move_model(); source_theme_render(); source_finalize_mock($old); $GLOBALS['dbt']['native_modified_time'] = gmdate('Y-m-d H:i:s');
    expect(FromSynchronizer::serialized(fn($guard) => CmsSourcePublisher::step(1, $guard)) === 'healthy'); $moved = (new CmsJournal(1))->read();
    expect($moved->current_record->source_updated_at === NativeSourceSnapshot::native_date($GLOBALS['dbt']['posts'][1]->post_modified_gmt));
    mock('V4-02', function ($url, $args) use ($moved): array {
        $body = json_decode($args['body'])->bridge_record; expect($body->source_revision === $moved->receipt->source_revision && $body->expected_source_revision === $moved->receipt->source_revision);
        $result = source_receipt($body); $result->outcome = 'resolved'; $result->reason = 'already_resolved'; return dbt_response(200, $result);
    });
    expect(FromSynchronizer::serialized(fn($guard) => CmsSourcePublisher::step(1, $guard)) === 'healthy');
    $token = CmsNativeFence::token(1); $request = new WP_REST_Request(['id' => 1, CmsNativeFence::INPUT => $token]);
    expect(!is_wp_error(CmsNativeFence::rest_prepare((object) ['ID' => 1], $request)));
    expect(wp_update_post(['ID' => 1, 'post_content' => '<p>Actual next native author edition</p>'], true) === 1 && CmsNativeFence::rest_finished((object) []) instanceof stdClass);
    $new = CmsSourceEdition::capture(1, new CmsClient(null, 'to')); expect(is_array($new) && $new['body']->source_revision !== $moved->receipt->source_revision);
    mock('V4-02', function ($url, $args) use ($moved): array {
        $body = json_decode($args['body'])->bridge_record; expect($body->source_revision !== $moved->receipt->source_revision && $body->expected_source_revision === $moved->receipt->source_revision);
        $result = source_receipt($body); $result->outcome = 'updated'; $result->reason = 'source_revision_updated'; return dbt_response(200, $result);
    });
    expect(FromSynchronizer::serialized(fn($guard) => CmsSourcePublisher::step(1, $guard)) === 'healthy' && is_wp_error(wp_update_post(['ID' => 1, 'post_title' => 'unversioned'], true)));
});

test('source move physical reconnect after native API write remains unknown and cache is invalidated without a second native write', function (): void {
    source_move_model(); source_theme_render(); $GLOBALS['dbt']['native_cache_enabled'] = true; get_post(1);
    $GLOBALS['dbt']['wp_insert_callback'] = function ($id): int { $GLOBALS['dbt']['connection_id']++; return $id; };
    expect(is_wp_error(FromSynchronizer::serialized(fn($guard) => CmsSourcePublisher::step(1, $guard))));
    $state = (new CmsJournal(1))->read(); expect($state->source_move->stage === 'native_commit_pending' && !isset($GLOBALS['dbt']['post_cache'][1]));
    $writes = count($GLOBALS['dbt']['inserted']); $GLOBALS['dbt']['native_lock'] = null; $GLOBALS['dbt']['wp_insert_callback'] = null;
    expect(is_wp_error(FromSynchronizer::serialized(fn($guard) => CmsSourcePublisher::step(1, $guard))) && count($GLOBALS['dbt']['inserted']) === $writes);
    expect(is_wp_error(CmsSourceMove::abort(1, 'fixture-nonce')) && (new CmsJournal(1))->read()->source_move->stage === 'native_commit_pending');
});

test('source move abort uncertainty replays exact30 and scoped history31 observes without changing accepted source', function (): void {
    $old = source_move_model(); $move = (new CmsJournal(1))->read()->source_move->receipt;
    mock('V4-30', new WP_Error('timeout'), 200, '', ['resource_id' => $old->receipt->resource_id, 'move_id' => $move->move_id]);
    expect(is_wp_error(CmsSourceMove::abort(1, 'fixture-nonce'))); $pending = (new CmsJournal(1))->read()->pending; $first = end($GLOBALS['dbt']['requests'])[1]['body'];
    $aborted = clone $move; $aborted->state = 'aborted'; mock('V4-30', $aborted, 200, '', ['resource_id' => $old->receipt->resource_id, 'move_id' => $move->move_id]);
    expect(FromSynchronizer::serialized(fn($guard) => CmsSourcePublisher::step(1, $guard)) === 'aborted' && end($GLOBALS['dbt']['requests'])[1]['body'] === $first);
    $state = (new CmsJournal(1))->read(); expect($state->source_move_history[0]->abort_mutation == $pending && $state->current_record == $old->current_record);
    $history = (object) ['items' => [], 'next_cursor' => null]; mock('V4-31', $history, 200, '', ['resource_id' => $old->receipt->resource_id]);
    expect(CmsSourceMove::history(1) == $history && (new CmsJournal(1))->read()->accepted_history == $old->accepted_history);
});

test('source move current scope revocation stops exact pending30 before dispatch without rewriting the intent', function (): void {
    $old = source_move_model(); expect(CmsSourceMove::abort(1, 'fixture-nonce') instanceof WP_Error);
    $state = (new CmsJournal(1))->read(); $prior = $state->source_move->abort_request; $count = count(array_filter($GLOBALS['dbt']['requests'], fn($r) => $r[1]['method'] === 'POST'));
    update_option(Settings::LANE_OPTION, 'changed-lane');
    expect(is_wp_error(FromSynchronizer::serialized(fn($guard) => CmsSourcePublisher::step(1, $guard))) && (new CmsJournal(1))->read()->source_move->abort_request == $prior);
    expect(count(array_filter($GLOBALS['dbt']['requests'], fn($r) => $r[1]['method'] === 'POST')) === $count && get_permalink(1) === $old->current_record->canonical_url);
});

test('source move SQL expected-row fence binds the distinct signed To metadata key and preserves unrelated native sources', function (): void {
    $old = source_move_model(); $token = CmsNativeFence::token(1);
    FromSynchronizer::serialized(function ($guard) use ($token): void {
        expect(CmsNativeFence::begin());
        CmsNativeFence::with_expected(1, $token, fn() => CmsNativeFence::before_update(1));
        $query = CmsNativeFence::sql_guard("UPDATE `wp_posts` SET `post_name` = 'new' WHERE `ID` = '1'");
        expect(str_contains($query, CmsSourceOwnership::META) && !str_contains($query, CmsPresentation::META) && str_contains($query, 'CONNECTION_ID() = 11'));
        CmsNativeFence::finish(false);
    });
    $GLOBALS['dbt']['posts'][2] = new WP_Post(2, 'publish', 'post', 'Unrelated', '<p>Ordinary article</p>', 9, 'unrelated');
    expect(wp_update_post(['ID' => 2, 'post_title' => 'Ordinary native edit'], true) === 2 && $GLOBALS['dbt']['posts'][1]->post_content === $old->current_native_source->article);
});

test('source move current To authority permits28 and29 with From policy disabled and retains the same rule for truthful30', function (): void {
    $old = source_move_model(false, false); source_theme_render(); source_finalize_mock($old);
    expect(FromSynchronizer::serialized(fn($guard) => CmsSourcePublisher::step(1, $guard)) === 'healthy');
    $old = source_move_model(false, false); $move = (new CmsJournal(1))->read()->source_move->receipt; $aborted = clone $move; $aborted->state = 'aborted';
    mock('V4-30', $aborted, 200, '', ['resource_id' => $old->receipt->resource_id, 'move_id' => $move->move_id]);
    expect(CmsSourceMove::abort(1, 'fixture-nonce') === 'aborted' && (new CmsJournal(1))->read()->receipt == $old->receipt);
});

test('source move full native source-byte proof preserves displayed code data quoting and separate appended discussion without DOM conversion', function (): void {
    $article = '<pre><code title=\'a &amp; b\'> &lt;script&gt;example()&lt;/script&gt; é</code></pre><span data-value=\'{"answer":42}\'>Unformatted data</span>';
    $old = source_move_model(false, true, $article); $page = source_theme_render();
    $state = (new CmsJournal(1))->read(); expect($state->source_move->expected_native->tree->body === $article && str_contains($page, $article));
    expect(str_contains($page, 'discussionbridge-discussion') && !str_contains($state->source_move->expected_native->tree->body, 'discussionbridge-discussion'));
    source_finalize_mock($old); expect(FromSynchronizer::serialized(fn($guard) => CmsSourcePublisher::step(1, $guard)) === 'healthy');
    expect($GLOBALS['dbt']['posts'][1]->post_content === $article && (new CmsJournal(1))->read()->current_record->source_content_sha256 === hash('sha256', $article));
});

test('source move missing current To receipt cannot bypass existing native editor REST or unversioned API fence', function (): void {
    $old = source_move_model(); unset($GLOBALS['dbt']['meta'][1][CmsSourceOwnership::META]);
    expect(CmsSourceOwnership::owned(1) && is_wp_error(CmsNativeFence::token(1)));
    expect(is_wp_error(wp_update_post(['ID' => 1, 'post_content' => 'unversioned replacement'], true)));
    expect($GLOBALS['dbt']['posts'][1]->post_content === $old->current_native_source->article && is_wp_error(CmsSourceMove::abort(1, 'fixture-nonce')));
});

test('source move actual independent native deletion retains captured closure and exact reservations without finalizing or recreating source', function (): void {
    source_move_model(); source_theme_render(); $before = (new CmsJournal(1))->read();
    $deletion = CmsSourceWithdrawal::deletion_guard(); $post = $GLOBALS['dbt']['posts'][1];
    expect($deletion->before(null, $post, true) === null, 'independent native delete must remain capturable');
    $capture = (new CmsJournal(1))->read();
    expect($capture->source_move == $before->source_move && $capture->pending->route === 'V4-05' && !$capture->withdrawal->confirmed, 'capture retains move and exact05');
    unset($GLOBALS['dbt']['meta'][1], $GLOBALS['dbt']['posts'][1]); $deletion->completed(1, $post); $deletion->after(1, $post);
    $confirmed = (new CmsJournal(1))->read(); $requests = count($GLOBALS['dbt']['requests']);
    $result = FromSynchronizer::serialized(fn($guard) => CmsSourcePublisher::step(1, $guard));
    expect(is_wp_error($result), 'closed native source must not finalize: ' . (is_string($result) ? $result : 'error')); $after = (new CmsJournal(1))->read();
    expect($after->source_move == $before->source_move && $after->pending == $capture->pending && $after->withdrawal->confirmed, 'confirmed delete preserves reservations and request');
    expect($after->accepted_history == $before->accepted_history && $after->receipt == $before->receipt && !isset($GLOBALS['dbt']['posts'][1]), 'accepted history immutable and no recreate');
    expect(is_wp_error(CmsSourceMove::abort(1, 'fixture-nonce')) && $GLOBALS['dbt']['native_lock'] === null);
    foreach (array_slice($GLOBALS['dbt']['requests'], $requests) as [$url, $args]) if ($args['method'] !== 'GET') {
        $body = json_decode($args['body']);
        expect($body->reason === 'source_deleted' && $body->expected_source_revision === $before->receipt->source_revision, 'only actual captured05 closure may dispatch');
    }
});

require __DIR__ . '/administrator-retry.php';
require __DIR__ . '/attention-recovery.php';
require __DIR__ . '/scheduler-recovery.php';
require __DIR__ . '/source-move-loopback.php';

$passed = 0;
$selected = $tests;
if (isset($argv[1])) {
    $selected = array_values(array_filter($tests, fn($test) => preg_match($argv[1], $test[0]) === 1));
    if ($selected === []) throw new RuntimeException('No integration cases matched the requested filter.');
}
foreach ($selected as [$name, $case]) {
    try { $case(); $passed++; fwrite(STDOUT, "ok - {$name}\n"); }
    catch (Throwable $error) { fwrite(STDERR, "not ok - {$name}: {$error->getMessage()} at {$error->getFile()}:{$error->getLine()}\n"); exit(1); }
}
fwrite(STDOUT, "{$passed}/" . count($selected) . " V4 native integration fixture cases passed\n");
