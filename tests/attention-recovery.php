<?php

declare(strict_types=1);

// Actual scheduler/admin/collector/coordinator methods; reduced WordPress cron/SQL/HTTP fixture host.
use DiscussionBridge\WordPress\CmsAttentionRecovery;
use DiscussionBridge\WordPress\CmsCoordinator;
use DiscussionBridge\WordPress\CmsJournal;
use DiscussionBridge\WordPress\CmsNativeFence;
use DiscussionBridge\WordPress\FromSynchronizer;
use DiscussionBridge\WordPress\Admin;
use DiscussionBridge\WordPress\CmsClient;
use DiscussionBridge\WordPress\CmsArtifacts;
use DiscussionBridge\WordPress\CmsNativeWriter;
use DiscussionBridge\WordPress\CmsPresentation;
use DiscussionBridge\WordPress\CmsPublicVerifier;
use DiscussionBridge\WordPress\Settings;

function ar_sync_fail(): void {
    mock('V4-01', new WP_Error('timeout'));
    wp_clear_scheduled_hook(FromSynchronizer::HOOK);
    FromSynchronizer::run();
}

function ar_export_attention(string $id, stdClass $inspection, stdClass $observation, string $status, ?string $issue = null): void {
    if (getenv('DB_WORDPRESS_RECOVERY_INTEROP') !== '1') return;
    $item = (object) ['id' => $id, 'profile' => 'wordpress', 'publication_kind' => 'dynamic',
        'inspection' => $inspection, 'observation' => $observation, 'expected_status' => $status];
    if ($issue !== null) $item->expected_issue = $issue;
    $GLOBALS['wordpress_attention_interop'][$id] = $item;
    $path = dirname(__DIR__, 4) . '/planning/v4-attention-recovery-fixes-20261025/WORDPRESS_CORE_INTEROP.json';
    file_put_contents($path, json_encode(array_values($GLOBALS['wordpress_attention_interop']), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
}

test('attention recovery scheduler continues beyond the third transient read with bounded60 120 300 backoff', function (): void {
    configured();
    $journal = CmsCoordinator::fresh('retained'); $journal->attention_jobs->{'old-failed'} = (object) ['protected' => 'failure-history'];
    expect((new CmsJournal())->write($journal, fn() => true));
    $before = (new CmsJournal())->read();
    foreach ([60, 120, 300, 300, 300] as $index => $delay) {
        $start = time(); ar_sync_fail(); $state = get_option(FromSynchronizer::STATE_OPTION);
        expect($state['status'] === 'local_error' && $state['failures'] === $index + 1, 'local failures are not Work attention');
        expect($state['next_due'] >= $start + $delay && $state['next_due'] <= time() + $delay, 'exact bounded delay');
        expect(count($GLOBALS['dbt']['scheduled']) === 1 && $state['last_attempt'] !== null);
    }
    expect((new CmsJournal())->read() == $before, 'failed reads cannot change any protected publication history');
});

test('attention recovery scheduler repairs legacy local attention and preserves one retained due time across init', function (): void {
    configured(); FromSynchronizer::ensure_scheduled(); $old = get_option(FromSynchronizer::STATE_OPTION);
    $old['status'] = 'attention'; $old['failures'] = 3; $old['next_due'] = time() + 299;
    update_option(FromSynchronizer::STATE_OPTION, $old, false); wp_clear_scheduled_hook(FromSynchronizer::HOOK);
    FromSynchronizer::ensure_scheduled(); $new = get_option(FromSynchronizer::STATE_OPTION);
    expect($new['epoch'] === $old['epoch'] && $new['failures'] === 3 && $new['status'] === 'local_error');
    $due = wp_next_scheduled(FromSynchronizer::HOOK);
    FromSynchronizer::ensure_scheduled(); FromSynchronizer::ensure_scheduled();
    expect(count($GLOBALS['dbt']['scheduled']) === 1 && wp_next_scheduled(FromSynchronizer::HOOK) === $due && $due === $old['next_due']);
});

test('attention recovery scheduler successful read resets local streak only and retains attention holds', function (): void {
    configured(); ar_sync_fail(); ar_sync_fail(); ar_sync_fail();
    $state = get_option(FromSynchronizer::STATE_OPTION); $epoch = $state['epoch'];
    $connection = connection(); $connection->policy->enabled = false;
    mock('V4-01', $connection); mock('V4-08', $connection->policy);
    wp_clear_scheduled_hook(FromSynchronizer::HOOK); FromSynchronizer::run(); $new = get_option(FromSynchronizer::STATE_OPTION);
    expect($new['status'] === 'healthy' && $new['failures'] === 0 && $new['last_success'] !== null && $new['epoch'] === $epoch);
    expect($new['next_due'] >= time() + 299 && $new['receiver_disabled'] === true);
});

test('attention recovery native wake is idempotent and does not change any publication journal', function (): void {
    configured(); ar_sync_fail(); ar_sync_fail(); ar_sync_fail();
    $state = get_option(FromSynchronizer::STATE_OPTION); $journal = CmsCoordinator::fresh('retained');
    $journal->attention_jobs->{'old-failed'} = (object) ['failure_count' => 3, 'pending' => 'exact-history'];
    expect((new CmsJournal())->write($journal, fn() => true)); $before = (new CmsJournal())->read();
    expect(FromSynchronizer::retry_synchronization() === 'queued');
    expect(FromSynchronizer::retry_synchronization() === 'already_queued');
    $after = get_option(FromSynchronizer::STATE_OPTION);
    expect($after['epoch'] === $state['epoch'] && $after['failures'] === 3 && count($GLOBALS['dbt']['scheduled']) === 1);
    expect((new CmsJournal())->read() == $before && $after['next_due'] <= time() + 1);
    expect(count(array_filter($GLOBALS['dbt']['requests'], fn($r) => $r[1]['method'] === 'POST')) === 0);
});

test('attention recovery native wake enforces capability nonce and method in actual registered handler', function (): void {
    configured(); $_SERVER['REQUEST_METHOD'] = 'GET'; $_POST = [];
    try { Admin::retry_synchronization(); expect(false); } catch (ArDenied) {}
    $_SERVER['REQUEST_METHOD'] = 'POST'; $GLOBALS['dbt']['capabilities']['manage_options'] = false;
    try { Admin::retry_synchronization(); expect(false); } catch (ArDenied) {}
    $GLOBALS['dbt']['capabilities']['manage_options'] = true; $GLOBALS['dbt']['nonce_valid'] = false;
    try { Admin::retry_synchronization(); expect(false); } catch (ArDenied) {}
    expect($GLOBALS['dbt']['scheduled'] === []);
    $GLOBALS['dbt']['nonce_valid'] = true;
    try { Admin::retry_synchronization(); expect(false); } catch (ArRedirect) {}
    expect(wp_next_scheduled(FromSynchronizer::HOOK) !== false && $GLOBALS['dbt']['nonce_actions'] === ['discussionbridge_retry_synchronization', 'discussionbridge_retry_synchronization']);
});

test('attention recovery scheduler detects overdue but initialization never floods missed polls', function (): void {
    configured(); FromSynchronizer::ensure_scheduled(); $state = get_option(FromSynchronizer::STATE_OPTION);
    $state['next_due'] = time() - 601; $state['last_success'] = time() - 1000;
    update_option(FromSynchronizer::STATE_OPTION, $state, false); wp_clear_scheduled_hook(FromSynchronizer::HOOK);
    expect(FromSynchronizer::status()['overdue'] === true);
    FromSynchronizer::ensure_scheduled(); FromSynchronizer::ensure_scheduled();
    expect(count($GLOBALS['dbt']['scheduled']) === 1 && wp_next_scheduled(FromSynchronizer::HOOK) <= time() + 1);
});

test('attention recovery busy writer does not consume a local or receiver attempt', function (): void {
    configured(); FromSynchronizer::ensure_scheduled(); $state = get_option(FromSynchronizer::STATE_OPTION);
    wp_clear_scheduled_hook(FromSynchronizer::HOOK); $GLOBALS['dbt']['native_lock'] = 'other';
    FromSynchronizer::run(); expect(get_option(FromSynchronizer::STATE_OPTION) === $state);
    expect(count($GLOBALS['dbt']['scheduled']) === 1 && $GLOBALS['dbt']['requests'] === []);
});

test('attention recovery collector revision error performs no native or option write', function (): void {
    configured(); $before = serialize([$GLOBALS['dbt']['options'], $GLOBALS['dbt']['posts'], $GLOBALS['dbt']['meta']]);
    expect(is_wp_error(CmsNativeFence::revision(999, false, null, true)));
    expect(serialize([$GLOBALS['dbt']['options'], $GLOBALS['dbt']['posts'], $GLOBALS['dbt']['meta']]) === $before, 'read-only collector failure must not write attention option');
});

function ar_attention_native(bool $applied = true): array {
    configured(); [$client, $connection, $job, $state, $save] = native_job();
    if ($applied) {
        $result = FromSynchronizer::serialized(fn($guard) => CmsNativeWriter::apply($client, $connection, $connection->policy, $job, $state, $save, $guard));
        expect($result instanceof stdClass, 'native test setup must apply a real owned fixture');
        $job->mapping->destination_native_id = $result->native_id; $job->mapping->destination_native_revision = $result->native_revision;
    }
    $work = clone $state->lease->work; $work->state = 'attention'; $work->last_failure_code = 'public_verification_failed';
    $job->event = null; $job->prepared = true; $job->synchronized = false; $job->verification = null;
    $state->attention_jobs->{$work->work_id} = $job; $state->jobs = []; $state->lease = null; $state->lease_deadline = 0;
    $state->attention_recovery = CmsAttentionRecovery::fresh(); $state->receiver_scope = $connection->scope_revision . "\0" . $connection->policy->policy_revision;
    $state->mapping_complete = true; $state->mappings->{$job->source->source_id} = $job->mapping;
    $inspection = (object) ['sequence' => 1, 'inspection_id' => wp_generate_uuid4(), 'context_sha256' => str_repeat('a', 64),
        'work' => $work, 'mapping' => $job->mapping, 'source' => $job->source, 'native_result' => $job->native_result,
        'verified_native_result' => null, 'verification' => null, 'prepared_transaction' => null, 'created_at' => gmdate('Y-m-d\TH:i:s') . '.000Z'];
    expect($save());
    return [$client, $connection, $job, $state, $save, $inspection];
}

function ar_recovery_mocks(stdClass $connection): void {
    mock('V4-32', (object) ['protocol_revision' => 'attention-recovery-1', 'candidate_id' => CmsArtifacts::CANDIDATE,
        'inspection_profiles' => [CmsAttentionRecovery::PROFILE]]);
    mock('V4-33', (object) ['items' => [], 'next_after' => 0, 'has_more' => false], 200, 'after=0');
    mock('V4-35', (object) ['items' => [], 'next_after' => 0, 'has_more' => false], 200, 'after=0');
    mock('V4-01', $connection); mock('V4-08', $connection->policy);
}

test('attention recovery actual owned candidate Check is read only and emits factual typed evidence', function (): void {
    [$client, $connection, $job, $state, $save, $inspection] = ar_attention_native();
    $before = serialize([$GLOBALS['dbt']['posts'], $GLOBALS['dbt']['meta'], $GLOBALS['dbt']['options'], $state]);
    $observed = FromSynchronizer::serialized(fn($guard) => CmsAttentionRecovery::inspect($client, $connection, $connection->policy, $inspection, $state, $guard));
    $client->codec()->validate('AttentionObservation', $observed);
    expect($observed->native_state === 'candidate_applied' && $observed->issue === 'none' && $observed->ownership->native_id === $job->mapping->destination_native_id);
    expect($before === serialize([$GLOBALS['dbt']['posts'], $GLOBALS['dbt']['meta'], $GLOBALS['dbt']['options'], $state]), 'Check must not alter native objects/private journal/option');
    expect($GLOBALS['dbt']['requests'] === []);
    ar_export_attention('owned-candidate', $inspection, $observed, 'ready', 'none');
    if (getenv('DB_WORDPRESS_RECOVERY_INTEROP') === '1') {
        $path = dirname(__DIR__, 4) . '/planning/v4-attention-recovery-fixes-20261025/WORDPRESS_INTEROP_OBSERVATION.json';
        file_put_contents($path, json_encode((object) ['inspection' => $inspection, 'observation' => $observed], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
    }
});

test('attention recovery collector proves never applied only with known old job clear ownership and no uncertain create', function (): void {
    [$client, $connection, $job, $state, $save, $inspection] = ar_attention_native(false);
    $read = fn() => FromSynchronizer::serialized(fn($guard) => CmsAttentionRecovery::inspect($client, $connection, $connection->policy, $inspection, $state, $guard));
    $never = $read(); expect($never->native_state === 'never_applied');
    ar_export_attention('never-applied', $inspection, $never, 'ready', 'none');
    $state->fences->{$job->mapping->mapping_id} = (object) ['generation' => 1, 'work_id' => $inspection->work->work_id, 'unresolved_create' => wp_generate_uuid4()];
    expect($read()->issue === 'uncertain_mutation');
    $state->fences->{$job->mapping->mapping_id}->unresolved_create = null; $state->attention_jobs = new stdClass();
    expect($read()->native_state === 'unknown', 'missing old native evidence must not fabricate absence');
});

test('attention recovery collector preserves native edits and unknown pending request without repair or replay', function (): void {
    [$client, $connection, $job, $state, $save, $inspection] = ar_attention_native();
    $id = (int) $job->mapping->destination_native_id; $GLOBALS['dbt']['posts'][$id]->post_title = 'Native owner edit';
    $observed = FromSynchronizer::serialized(fn($guard) => CmsAttentionRecovery::inspect($client, $connection, $connection->policy, $inspection, $state, $guard));
    expect($observed->issue === 'native_conflict' && $GLOBALS['dbt']['posts'][$id]->post_title === 'Native owner edit');
    ar_export_attention('foreign-edit', $inspection, $observed, 'not_ready', 'native_conflict');
    $state->pending = (object) ['identity' => 'exact-unknown']; $before = serialize($state);
    $observed = FromSynchronizer::serialized(fn($guard) => CmsAttentionRecovery::inspect($client, $connection, $connection->policy, $inspection, $state, $guard));
    expect($observed->pending_mutation === 'uncertain' && $observed->issue === 'uncertain_mutation' && $before === serialize($state));
    ar_export_attention('uncertain-native-request', $inspection, $observed, 'not_ready', 'uncertain_mutation');
    expect($GLOBALS['dbt']['requests'] === []);
});

test('attention recovery exact retained baseline stays read only and differs from failed candidate intent', function (): void {
    [$client, $connection, $job, $state, $save, $inspection] = ar_attention_native();
    // Model the prior normal synchronized/verified receiver projection from actual native result/public proof.
    $inspection->mapping->synchronized_source_revision = $job->source->source_revision;
    $inspection->mapping->publicly_verified_source_revision = $job->source->source_revision;
    $inspection->mapping->synchronized_at = gmdate('Y-m-d\TH:i:s', time() - 1) . '.000Z';
    $id = (int) $job->native_result->native_id; $metadata = get_post_meta($id, CmsPresentation::META, true);
    $page = CmsPresentation::expected($GLOBALS['dbt']['posts'][$id], $metadata);
    $GLOBALS['dbt']['responses'][$inspection->mapping->canonical_url] = dbt_response(200, '<html><body>' . $page . '</body></html>', 'text/html; charset=UTF-8');
    $proof = CmsPublicVerifier::verify($client, $inspection->mapping, $job->native_result, $job->source, fn() => true);
    expect($proof instanceof stdClass, 'baseline fixture must have actual normal public verification');
    $inspection->mapping->publicly_verified_at = $proof->checked_at; $inspection->verification = $proof;
    $old_key = $inspection->work->work_id; $inspection->work->work_id = wp_generate_uuid4(); $inspection->work->attempt_cycle = wp_generate_uuid4();
    $state->attention_jobs->{$inspection->work->work_id} = $job; unset($state->attention_jobs->$old_key);
    $inspection->verified_native_result = $inspection->native_result;
    $before = serialize([$state, $GLOBALS['dbt']['posts'], $GLOBALS['dbt']['meta'], $GLOBALS['dbt']['options']]);
    $observed = FromSynchronizer::serialized(fn($guard) => CmsAttentionRecovery::inspect($client, $connection, $connection->policy, $inspection, $state, $guard));
    expect($observed->native_state === 'baseline' && $observed->current_sha256 === $observed->baseline_sha256);
    expect($before === serialize([$state, $GLOBALS['dbt']['posts'], $GLOBALS['dbt']['meta'], $GLOBALS['dbt']['options']]));
    ar_export_attention('retained-baseline', $inspection, $observed, 'ready', 'none');
});

test('attention recovery exact diagnostic report survives lost response and never creates work or publication mutation', function (): void {
    [$client, $connection, $job, $state, $save, $inspection] = ar_attention_native(); ar_recovery_mocks($connection);
    mock('V4-14', (object) ['work' => $inspection->work, 'mapping' => $inspection->mapping, 'lease_expires_at' => null], 200, '', ['work_id' => $inspection->work->work_id]);
    mock('V4-33', (object) ['items' => [$inspection], 'next_after' => 1, 'has_more' => false], 200, 'after=0');
    $url = mock('V4-34', new WP_Error('timeout'), 200, '', ['inspection_id' => $inspection->inspection_id]);
    expect(FromSynchronizer::serialized(fn($guard) => CmsAttentionRecovery::step($client, $connection, $connection->policy, $state, $save, $guard)) === true);
    expect($state->attention_recovery->report->pending->route === 'V4-34' && $state->attention_recovery->inspection_after === 0 && $state->pending === null);
    $first = array_values(array_filter($GLOBALS['dbt']['requests'], fn($r) => $r[1]['method'] === 'POST'))[0][1]['body'];
    $GLOBALS['dbt']['responses'][$url] = dbt_response(200, (object) ['inspection_id' => $inspection->inspection_id, 'context_sha256' => $inspection->context_sha256,
        'status' => 'ready', 'issue' => 'none', 'observed_at' => json_decode($first)->observation->observed_at]);
    expect(FromSynchronizer::serialized(fn($guard) => CmsAttentionRecovery::step($client, $connection, $connection->policy, $state, $save, $guard)) === true);
    $posts = array_values(array_filter($GLOBALS['dbt']['requests'], fn($r) => $r[1]['method'] === 'POST'));
    expect(count($posts) === 2 && $posts[1][1]['body'] === $first && $state->attention_recovery->inspection_after === 1 && $state->attention_recovery->report === null);
    expect($state->jobs === [] && $state->lease === null && count(get_object_vars($state->attention_jobs)) === 1);
});

function ar_successor_event(stdClass $inspection): stdClass {
    $work = clone $inspection->work; $work->work_id = wp_generate_uuid4(); $work->attempt_cycle = wp_generate_uuid4();
    $work->state = 'pending'; $work->attempt = 0; $work->lease_generation = 0; $work->last_failure_code = null;
    return (object) ['sequence' => 1, 'recovery_id' => wp_generate_uuid4(), 'inspection_id' => $inspection->inspection_id,
        'context_sha256' => $inspection->context_sha256, 'predecessor_work_id' => $inspection->work->work_id,
        'predecessor_attempt_cycle' => $inspection->work->attempt_cycle, 'work' => $work, 'mapping' => $inspection->mapping,
        'source' => $inspection->source, 'mode' => 'retry', 'native_baseline' => $inspection->native_result, 'verification' => null, 'created_at' => $inspection->created_at];
}

test('attention recovery same unchanged source discovers exact successor with no feed event and preserves failed history', function (): void {
    [$client, $connection, $job, $state, $save, $inspection] = ar_attention_native(); ar_recovery_mocks($connection);
    $old = serialize($job); $event = ar_successor_event($inspection);
    $status = clone $inspection->work; $status->state = 'superseded';
    mock('V4-14', (object) ['work' => $status, 'mapping' => $inspection->mapping, 'lease_expires_at' => null], 200, '', ['work_id' => $inspection->work->work_id]);
    mock('V4-14', (object) ['work' => $event->work, 'mapping' => $event->mapping, 'lease_expires_at' => null], 200, '', ['work_id' => $event->work->work_id]);
    mock('V4-35', (object) ['items' => [$event], 'next_after' => 1, 'has_more' => false], 200, 'after=0');
    expect(FromSynchronizer::serialized(fn($guard) => CmsAttentionRecovery::step($client, $connection, $connection->policy, $state, $save, $guard)) === true);
    expect(count($state->jobs) === 1 && $state->jobs[0]->recovery->work->work_id === $event->work->work_id && $state->jobs[0]->source->source_revision === $job->source->source_revision);
    expect($state->jobs[0]->native_result === null && !$state->jobs[0]->synchronized && $state->jobs[0]->hydration === null);
    expect(serialize($state->attention_recovery->history->{$inspection->work->work_id}->job) === $old && $state->lease === null);
    expect($state->attention_recovery->recovery_after === 1 && $state->fences->{$job->mapping->mapping_id}->generation === 1);
    expect(count(array_filter($GLOBALS['dbt']['requests'], fn($r) => $r[1]['method'] === 'POST')) === 0, 'discovery itself is not a lease or publication');
});

test('attention recovery successor uses targeted claim and refuses a different leased work before native write', function (): void {
    [$client, $connection, $job, $state, $save, $inspection] = ar_attention_native(); $event = ar_successor_event($inspection);
    $next = (object) ['event' => null, 'source' => $event->source, 'plan' => $job->plan, 'mapping' => $event->mapping, 'prepared' => true,
        'hydration' => null, 'native_result' => null, 'synchronized' => false, 'verification' => null, 'recovery' => $event];
    $wrong = fixture('lease'); $wrong->server_time = gmdate('Y-m-d\TH:i:s') . '.000Z'; $wrong->expires_at = gmdate('Y-m-d\TH:i:s', time() + 300) . '.000Z';
    mock('V4-36', $wrong, 200, '', ['recovery_id' => $event->recovery_id]);
    $method = new ReflectionMethod(CmsCoordinator::class, 'work');
    $before = serialize([$GLOBALS['dbt']['posts'], $GLOBALS['dbt']['meta']]);
    $result = FromSynchronizer::serialized(fn($guard) => $method->invoke(null, $client, $connection, $connection->policy, $next, $state, $save, $guard));
    expect(is_wp_error($result) && $result->get_error_code() === 'discussionbridge_cms_recovery_claim_identity');
    $request = end($GLOBALS['dbt']['requests']); expect(str_contains($request[0], '/recoveries/' . $event->recovery_id . '/claim.json') && json_decode($request[1]['body'])->work_id === $event->work->work_id);
    expect($state->pending->route === 'V4-36' && $state->lease === null && serialize([$GLOBALS['dbt']['posts'], $GLOBALS['dbt']['meta']]) === $before);
});

test('attention recovery new cycle rechecks native revision and preserves foreign edit after prior ready check', function (): void {
    [$client, $connection, $job, $state, $save, $inspection] = ar_attention_native();
    $observed = FromSynchronizer::serialized(fn($guard) => CmsAttentionRecovery::inspect($client, $connection, $connection->policy, $inspection, $state, $guard)); expect($observed->native_state === 'candidate_applied');
    $event = ar_successor_event($inspection); $lease = fixture('lease'); $lease->work = clone $event->work; $lease->work->state = 'leased';
    $lease->work->attempt = 1; $lease->work->lease_generation = 2; $lease->lease_generation = 2;
    $state->lease = $lease; $state->lease_deadline = time() + 280; $job->native_result = null;
    $id = (int) $job->mapping->destination_native_id; $GLOBALS['dbt']['posts'][$id]->post_content = '<p>Native owner changed this after Check.</p>';
    $result = FromSynchronizer::serialized(fn($guard) => CmsNativeWriter::apply($client, $connection, $connection->policy, $job, $state, $save, $guard));
    expect(is_wp_error($result) && $result->get_error_code() === 'discussionbridge_cms_native_native_drift');
    expect($GLOBALS['dbt']['posts'][$id]->post_content === '<p>Native owner changed this after Check.</p>' && $state->fences->{$job->mapping->mapping_id}->generation === 2);
});

test('attention recovery scheduling failure is truthful and does not claim queued', function (): void {
    configured(); $GLOBALS['dbt']['schedule_failure'] = true;
    $result = FromSynchronizer::retry_synchronization();
    expect(is_wp_error($result) && get_option(FromSynchronizer::STATE_OPTION)['status'] === 'scheduling_unavailable');
    expect(get_option(FromSynchronizer::STATE_OPTION)['next_due'] === null && $GLOBALS['dbt']['scheduled'] === []);
    $GLOBALS['dbt']['schedule_failure'] = false; FromSynchronizer::ensure_scheduled();
    expect(count($GLOBALS['dbt']['scheduled']) === 1);
});

test('attention recovery configuration epoch invalidates an in flight scheduler before any post-step write', function (): void {
    configured(); FromSynchronizer::ensure_scheduled(); $before = get_option(FromSynchronizer::STATE_OPTION);
    mock('V4-01', function () use ($before): array {
        $new = $before; $new['epoch'] = wp_generate_uuid4(); $new['reason'] = 'configuration_changed';
        update_option(FromSynchronizer::STATE_OPTION, $new, false);
        return dbt_response(200, connection());
    });
    mock('V4-08', connection()->policy); wp_clear_scheduled_hook(FromSynchronizer::HOOK); FromSynchronizer::run();
    $after = get_option(FromSynchronizer::STATE_OPTION);
    expect($after['epoch'] !== $before['epoch'] && $after['reason'] === 'configuration_changed' && $after['failures'] === $before['failures']);
    expect($GLOBALS['dbt']['scheduled'] === [] && (new CmsJournal())->read() === null);
});

test('attention recovery exact prior r5 journal migrates provenance without changing pending receipt bytes', function (): void {
    configured(); $old = CmsCoordinator::fresh('fixture');
    $old->artifacts = (object) ['candidate' => 'discussionbridge-v4-core-20261015-r5', 'wire' => 'v4',
        'codec_sha256' => '717513f474719538be0d40ad8cc4aea274051c17cf522274898ee29abf64a481', 'schema_sha256' => '7dee282601e4195459f38b2f2451a5f0f03af39a5f60affc65c49b34664e3f7a'];
    $client = new CmsClient(); $pending = DiscussionBridge\WordPress\CmsMutation::prepare($client, 'V4-13', ['body' => (object) []]); expect($pending instanceof stdClass);
    $old->pending = $pending; $before = serialize($old->pending); $prior = clone $old->artifacts;
    expect(CmsArtifacts::upgrade_from_r5($old, fn() => true));
    expect(serialize($old->pending) === $before && $old->artifact_history[0] == $prior && CmsArtifacts::matches($old->artifacts));
    $old->artifacts = $prior; $old->artifacts->schema_sha256 = str_repeat('0', 64);
    expect(!CmsArtifacts::upgrade_from_r5($old, fn() => true), 'unknown descriptors cannot select a decoder');
});

test('attention recovery actual coordinator discovers unchanged source and retains a known successor after empty claim', function (): void {
    [$client, $connection, $job, $state, $save, $inspection] = ar_attention_native(); ar_recovery_mocks($connection);
    $event = ar_successor_event($inspection); $old_work = clone $inspection->work; $old_work->state = 'superseded';
    mock('V4-14', (object) ['work' => $old_work, 'mapping' => $inspection->mapping, 'lease_expires_at' => null], 200, '', ['work_id' => $inspection->work->work_id]);
    mock('V4-14', (object) ['work' => $event->work, 'mapping' => $event->mapping, 'lease_expires_at' => null], 200, '', ['work_id' => $event->work->work_id]);
    mock('V4-35', (object) ['items' => [$event], 'next_after' => 1, 'has_more' => false], 200, 'after=0');
    $state->sources->stream = 'changes'; $state->sources->cursor = fixture('changes_feed')->checkpoint_cursor;
    $empty = fixture('changes_feed'); $empty->items = []; mock('V4-06', $empty, 200, 'stream=changes&cursor=' . $state->sources->cursor);
    mock('V4-36', '', 204, '', ['recovery_id' => $event->recovery_id]); expect($save());
    $before = serialize([$GLOBALS['dbt']['posts'], $GLOBALS['dbt']['meta']]);
    $result = FromSynchronizer::serialized(fn($guard) => CmsCoordinator::step($state->scope, $guard));
    expect($result === 'working', is_wp_error($result) ? $result->get_error_code() : (string) $result);
    $stored = (new CmsJournal())->read(); expect(count($stored->jobs) === 1 && $stored->jobs[0]->recovery_status->work->work_id === $event->work->work_id);
    expect($stored->lease === null && $stored->pending === null && $stored->jobs[0]->native_result === null);
    $posts = array_values(array_filter($GLOBALS['dbt']['requests'], fn($r) => $r[1]['method'] === 'POST'));
    expect(count($posts) === 1 && str_contains($posts[0][0], '/work/recoveries/' . $event->recovery_id . '/claim.json'));
    expect($before === serialize([$GLOBALS['dbt']['posts'], $GLOBALS['dbt']['meta']]));
});

test('attention recovery known committed create before receiver acknowledgement preserves exact private native identity', function (): void {
    [$client, $connection, $job, $state, $save, $inspection] = ar_attention_native();
    $known_id = $job->native_result->native_id; $inspection->mapping->destination_native_id = null; $inspection->mapping->destination_native_revision = null;
    $inspection->native_result = null; $inspection->verified_native_result = null;
    $observed = FromSynchronizer::serialized(fn($guard) => CmsAttentionRecovery::inspect($client, $connection, $connection->policy, $inspection, $state, $guard));
    expect($observed->native_state === 'candidate_applied' && $observed->native_id === $known_id);
    ar_export_attention('known-create-before-ack', $inspection, $observed, 'ready', 'none');
    $job->native_result = null; $job->creation_attempt = null;
    $unknown = FromSynchronizer::serialized(fn($guard) => CmsAttentionRecovery::inspect($client, $connection, $connection->policy, $inspection, $state, $guard));
    expect($unknown->issue === 'native_conflict', 'resource marker alone cannot adopt an unproven created object');
});

test('attention recovery fresh successor reuses owned created post with higher fence and no prior success transplantation', function (): void {
    [$client, $connection, $old, $state, $save, $inspection] = ar_attention_native(); $old_bytes = serialize($old);
    $event = ar_successor_event($inspection); $next = unserialize(serialize($old)); $next->native_result = null; $next->creation_attempt = null;
    $next->synchronized = false; $next->verification = null;
    $lease = fixture('lease'); $lease->work = clone $event->work; $lease->work->state = 'leased'; $lease->work->attempt = 1;
    $lease->work->lease_generation = 2; $lease->lease_generation = 2; $state->lease = $lease; $state->lease_deadline = time() + 280;
    $native_keys = array_keys($GLOBALS['dbt']['posts']); $next_id = $GLOBALS['dbt']['next_post_id'];
    $result = FromSynchronizer::serialized(fn($guard) => CmsNativeWriter::apply($client, $connection, $connection->policy, $next, $state, $save, $guard));
    expect($result instanceof stdClass, is_wp_error($result) ? $result->get_error_code() : 'new native result absent');
    expect($result->native_id === $old->native_result->native_id, 'native ID changed');
    // This host funnels wp_update_post through wp_insert_post and logs both in inserted.
    expect(array_keys($GLOBALS['dbt']['posts']) === $native_keys && $GLOBALS['dbt']['next_post_id'] === $next_id, 'a new native object was created');
    $receipt = get_post_meta((int) $result->native_id, CmsPresentation::META, true);
    expect($receipt['work_id'] === $event->work->work_id && $receipt['receipt']['last_lease_generation'] === 2, 'new native receipt identity');
    expect($state->fences->{$old->mapping->mapping_id}->generation === 2, 'new fence not retained');
    expect(serialize($old) === $old_bytes, 'old failed job changed');
    expect(!$next->synchronized && $next->verification === null, 'old success progress was transplanted');
});

test('attention recovery current native selection availability is checked without rewriting policy or native state', function (): void {
    [$client, $connection, $job, $state, $save, $inspection] = ar_attention_native();
    $connection->policy->author_ids = ['999']; $before = serialize([$GLOBALS['dbt']['options'], $GLOBALS['dbt']['posts'], $GLOBALS['dbt']['meta'], $connection->policy]);
    $observed = FromSynchronizer::serialized(fn($guard) => CmsAttentionRecovery::inspect($client, $connection, $connection->policy, $inspection, $state, $guard));
    expect($observed->issue === 'metadata_unavailable' && $connection->policy->author_ids === ['999']);
    expect($before === serialize([$GLOBALS['dbt']['options'], $GLOBALS['dbt']['posts'], $GLOBALS['dbt']['meta'], $connection->policy]));
    ar_export_attention('selected-author-missing', $inspection, $observed, 'not_ready', 'metadata_unavailable');
});

test('attention recovery extension probes capability and unsupported server cannot release an attention hold', function (): void {
    [$client, $connection, $job, $state, $save, $inspection] = ar_attention_native(); $old = serialize($state->attention_jobs);
    expect(FromSynchronizer::serialized(fn($guard) => CmsAttentionRecovery::step($client, $connection, $connection->policy, $state, $save, $guard)) === true);
    expect($state->attention_recovery->status === 'unsupported' && serialize($state->attention_jobs) === $old && $state->jobs === []);
    expect(count($GLOBALS['dbt']['requests']) === 1 && str_contains($GLOBALS['dbt']['requests'][0][0], '/attention-recovery.json'));
});

test('attention recovery From capability is inaccessible to the separate To transport', function (): void {
    configured(); $client = new CmsClient(null, 'to');
    expect(is_wp_error($client->request('V4-32')) && $GLOBALS['dbt']['requests'] === []);
});

test('attention recovery verified withdrawn baseline retains draft content and resumes same native ID under a fresh fence', function (): void {
    [$client, $connection, $published, $state, $save, $inspection] = ar_attention_native();
    $source = unserialize(serialize($published->source)); $hydration = unserialize(serialize($published->hydration));
    $native_id = $published->native_result->native_id; $article = $GLOBALS['dbt']['posts'][(int) $native_id]->post_content;
    $withdraw = unserialize(serialize($published)); $withdraw->source = null; $withdraw->hydration = null;
    $withdraw->native_result = null; $withdraw->creation_attempt = null; $withdraw->synchronized = false; $withdraw->verification = null;
    $lease = fixture('lease'); $lease->work = clone $inspection->work; $lease->work->work_id = wp_generate_uuid4();
    $lease->work->attempt_cycle = wp_generate_uuid4(); $lease->work->action = 'withdraw'; $lease->work->source_revision = null;
    $lease->work->state = 'leased'; $lease->work->attempt = 1; $lease->work->last_failure_code = null;
    $lease->work->lease_generation = 2; $lease->lease_generation = 2; $state->lease = $lease; $state->lease_deadline = time() + 280;
    $withdrawn = FromSynchronizer::serialized(fn($guard) => CmsNativeWriter::apply($client, $connection, $connection->policy, $withdraw, $state, $save, $guard));
    expect($withdrawn instanceof stdClass && $withdrawn->kind === 'withdraw' && $withdrawn->native_id === $native_id, 'normal native withdrawal must retain the post');
    expect($GLOBALS['dbt']['posts'][(int) $native_id]->post_status === 'draft' && $GLOBALS['dbt']['posts'][(int) $native_id]->post_content === $article, 'withdrawal preserves complete article');
    $receipt = get_post_meta((int) $native_id, CmsPresentation::META, true);
    expect($receipt['receipt']['source_revision'] === null && $receipt['source_sha256'] === $source->content_sha256,
        'actual WP receipt uses null withdrawal revision while separately retaining source content hash');
    $mapping = $withdraw->mapping; $mapping->destination_native_revision = $withdrawn->native_revision;
    $mapping->synchronized_source_revision = null; $mapping->publicly_verified_source_revision = null;
    $mapping->synchronized_at = gmdate('Y-m-d\TH:i:s', time() - 1) . '.000Z';
    $GLOBALS['dbt']['responses'][$mapping->canonical_url] = dbt_response(410, '', 'text/html; charset=UTF-8');
    $proof = CmsPublicVerifier::verify($client, $mapping, $withdrawn, null, fn() => true);
    expect($proof instanceof stdClass && $proof->kind === 'absent', 'normal public withdrawal verifier establishes the prior accepted baseline');
    $mapping->publicly_verified_at = $proof->checked_at;
    $failed = clone $inspection->work; $failed->work_id = wp_generate_uuid4(); $failed->attempt_cycle = wp_generate_uuid4();
    $failed->lease_generation = 3; $failed->attempt = 1; $failed->last_failure_code = 'metadata_unavailable';
    $inspection->work = $failed; $inspection->mapping = $mapping; $inspection->source = $source;
    $inspection->native_result = $withdrawn; $inspection->verified_native_result = $withdrawn; $inspection->verification = $proof;
    $held = (object) ['event' => null, 'source' => $source, 'mapping' => $mapping, 'plan' => $published->plan,
        'prepared' => true, 'hydration' => $hydration, 'native_result' => null, 'synchronized' => false, 'verification' => null, 'creation_attempt' => null];
    $state->attention_jobs = (object) [$failed->work_id => $held]; $state->lease = null; $state->lease_deadline = 0;
    $before = serialize([$state, $GLOBALS['dbt']['posts'], $GLOBALS['dbt']['meta'], $GLOBALS['dbt']['options']]);
    $observed = FromSynchronizer::serialized(fn($guard) => CmsAttentionRecovery::inspect($client, $connection, $connection->policy, $inspection, $state, $guard));
    expect($observed->native_state === 'baseline' && $observed->native_result->kind === 'withdraw' && $observed->source_revision === null);
    expect($before === serialize([$state, $GLOBALS['dbt']['posts'], $GLOBALS['dbt']['meta'], $GLOBALS['dbt']['options']]), 'withdrawn baseline Check is read only');
    ar_export_attention('verified-withdrawn-baseline', $inspection, $observed, 'ready', 'none');
    $held_before = serialize($held); $next = unserialize(serialize($held)); $event = ar_successor_event($inspection);
    $lease->work = clone $event->work; $lease->work->state = 'leased'; $lease->work->attempt = 1; $lease->work->lease_generation = 4;
    $lease->lease_generation = 4; $state->lease = $lease; $state->lease_deadline = time() + 280;
    $keys = array_keys($GLOBALS['dbt']['posts']); $next_id = $GLOBALS['dbt']['next_post_id'];
    $resumed = FromSynchronizer::serialized(fn($guard) => CmsNativeWriter::apply($client, $connection, $connection->policy, $next, $state, $save, $guard));
    expect($resumed instanceof stdClass && $resumed->native_id === $native_id && $GLOBALS['dbt']['posts'][(int) $native_id]->post_status === 'publish');
    expect($GLOBALS['dbt']['posts'][(int) $native_id]->post_content === $article && array_keys($GLOBALS['dbt']['posts']) === $keys && $GLOBALS['dbt']['next_post_id'] === $next_id);
    expect($state->fences->{$mapping->mapping_id}->generation === 4 && serialize($held) === $held_before && !$next->synchronized && $next->verification === null);
});

