<?php

declare(strict_types=1);

namespace DiscussionBridge\WordPress;

use WP_Error;

/** The SAME site writer is shared by source, From, metadata and native lifecycle work. */
final class NativeWriter
{
    public function name(): string { return 'discussionbridge:' . substr(hash('sha256', home_url('/')), 0, 40); }

    public function acquire(bool $exclusive = false): NativeLock|WP_Error
    {
        global $wpdb;
        $name = $this->name();
        $already_owned = (string) $wpdb->get_var($wpdb->prepare('SELECT IS_USED_LOCK(%s) = CONNECTION_ID()', $name)) === '1';
        if ($exclusive && $already_owned || !$already_owned && (string) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 0)', $name)) !== '1') {
            return new WP_Error('discussionbridge_native_writer_busy', 'Another bridge operation is active. Preserve the current native source.');
        }
        $lock = new NativeLock($wpdb, $name, (string) $wpdb->get_var('SELECT CONNECTION_ID()'), !$already_owned);
        if (!$lock->valid()) { $lock->release(); return new WP_Error('discussionbridge_native_connection_changed', 'The native connection changed; do not assume a committed write or rollback.'); }
        return $lock;
    }

    public function run(callable $operation): mixed
    {
        $lock = $this->acquire(); if (is_wp_error($lock)) return $lock;
        try { return $operation(fn(): bool => $lock->valid()); }
        finally { $lock->release(); }
    }
}
