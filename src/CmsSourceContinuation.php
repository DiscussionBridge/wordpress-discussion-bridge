<?php

declare(strict_types=1);

namespace DiscussionBridge\WordPress;

use stdClass;
use WP_Error;

/** One page or at most four source chunks per invocation; every page is staged before its cursor. */
final class CmsSourceContinuation
{
    public static function fresh(): stdClass
    {
        return (object) ['stream' => 'initial', 'cursor' => null, 'checkpoint' => null, 'page' => null,
            'events' => [], 'last_sequence' => null, 'drain_sequences' => [], 'initial_last_source_id' => null,
            'cursor_history' => [], 'reconciliation' => null];
    }

    public static function intake(CmsClient $client, stdClass $state, callable $save): bool|WP_Error
    {
        if ($state->page !== null) return true; // A committed page's events must be consumed first.
        if ($state->reconciliation !== null) return new WP_Error('discussionbridge_cms_reconciliation', 'The source cursor requires explicit reconciliation.');
        $query = (object) ['stream' => $state->stream];
        if ($state->cursor !== null) $query->cursor = $state->cursor;
        $page = $client->request('V4-06', ['query' => $query]);
        if (is_wp_error($page)) {
            $code = $page->get_error_data()['code'] ?? '';
            if (in_array($code, ['cursor_expired', 'cursor_invalid', 'cursor_scope_changed'], true)) {
                $state->reconciliation = $code;
                if (!$save()) return self::stale();
            }
            return $page;
        }
        if ($page->stream !== $state->stream) return self::invalid();
        $ids = []; $sequences = []; $last = $state->last_sequence; $last_source = $state->initial_last_source_id;
        if ($page->next_cursor !== null && ($page->next_cursor === $state->cursor || in_array($page->next_cursor, $state->cursor_history, true))) return self::invalid();
        foreach ($page->items as $event) {
            if (isset($ids[$event->event_id]) || isset($sequences[$event->event_sequence])
                || in_array($event->event_sequence, $state->drain_sequences, true)) return self::invalid();
            if ($state->stream === 'changes' && $last !== null && self::compare($event->event_sequence, $last) <= 0) return self::invalid();
            if ($state->stream === 'initial') {
                if ($event->event_type !== 'publication' || $last_source !== null && strcmp($event->source->source_id, $last_source) <= 0) return self::invalid();
                $last_source = $event->source->source_id;
            }
            $ids[$event->event_id] = true; $sequences[$event->event_sequence] = true;
            $last = $event->event_sequence;
        }
        $state->page = $page; $state->events = $page->items;
        // This durable commit includes every event. The request cursor has not advanced yet.
        return $save() ? true : self::stale();
    }

    /** Commit only after every staged event is in the durable local processing queue. */
    public static function finish_page(stdClass $state, callable $save): bool|WP_Error
    {
        if ($state->page === null || $state->events !== []) return self::invalid();
        foreach ($state->page->items as $event) {
            $state->drain_sequences[] = $event->event_sequence;
            if ($state->stream === 'changes') $state->last_sequence = $event->event_sequence;
            else $state->initial_last_source_id = $event->source->source_id;
        }
        $page = $state->page;
        if ($page->next_cursor !== null) { $state->cursor_history[] = $page->next_cursor; $state->cursor = $page->next_cursor; }
        else {
            $state->checkpoint = $page->checkpoint_cursor; $state->cursor = $page->checkpoint_cursor;
            $state->stream = 'changes'; $state->drain_sequences = []; $state->cursor_history = [];
        }
        $state->page = null;
        return $save() ? true : self::stale();
    }

    /** Native staging bytes may contain a split UTF8 character; validate only the complete source. */
    public static function content(CmsClient $client, stdClass $source, ?stdClass &$progress, callable $save): string|WP_Error
    {
        $identity = hash('sha256', $client->codec()->semanticIdentity($source));
        if ($progress === null) $progress = (object) ['identity' => $identity, 'offset' => 0, 'bytes_base64' => '', 'complete' => false];
        if ($progress->identity !== $identity) return self::invalid();
        $bytes = base64_decode($progress->bytes_base64, true);
        if ($bytes === false || strlen($bytes) !== $progress->offset) return self::invalid();
        for ($i = 0; $i < 4 && !$progress->complete; $i++) {
            $chunk = $client->request('V4-07', ['path' => ['source_id' => $source->source_id, 'source_revision' => $source->source_revision],
                'query' => (object) ['offset' => $progress->offset]]);
            if (is_wp_error($chunk)) return $chunk;
            if ($chunk->source_id !== $source->source_id || $chunk->source_revision !== $source->source_revision
                || $chunk->content_bytes !== $source->content_bytes || $chunk->content_sha256 !== $source->content_sha256
                || $chunk->offset !== $progress->offset) return self::invalid();
            $part = base64_decode($chunk->content_base64, true);
            if ($part === false || strlen($part) > 32768) return self::invalid();
            $end = $progress->offset + strlen($part);
            if ($end > $source->content_bytes || ($chunk->next_offset !== null && ($chunk->next_offset !== $end || $end <= $progress->offset))
                || ($chunk->next_offset === null && $end !== $source->content_bytes)) return self::invalid();
            $bytes .= $part; $progress->offset = $end; $progress->bytes_base64 = base64_encode($bytes);
            $progress->complete = $chunk->next_offset === null;
            if (!$save()) return self::stale();
        }
        if (!$progress->complete) return new WP_Error('discussionbridge_cms_content_pending', 'The complete source is still being staged.');
        if (strlen($bytes) !== $source->content_bytes || !hash_equals($source->content_sha256, hash('sha256', $bytes))
            || preg_match('//u', $bytes) !== 1) return self::invalid();
        return $bytes;
    }

    private static function compare(string $left, string $right): int
    {
        return strlen($left) <=> strlen($right) ?: strcmp($left, $right);
    }
    private static function invalid(): WP_Error { return new WP_Error('discussionbridge_cms_source_inconsistent', 'The source continuation is inconsistent; preserve progress for reconciliation.'); }
    private static function stale(): WP_Error { return new WP_Error('discussionbridge_stale_worker', 'This worker cannot commit current CMS progress.'); }
}
