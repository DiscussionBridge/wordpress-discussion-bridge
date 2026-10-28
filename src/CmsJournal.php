<?php

declare(strict_types=1);

namespace DiscussionBridge\WordPress;

use stdClass;
use WP_Error;

/** Current encrypted local receipt/recovery ledger, never publication authority. */
final class CmsJournal
{
    private ProtectedJournal $storage;
    private ?int $source_post_id;

    public function __construct(?int $source_post_id = null)
    {
        if ($source_post_id !== null && $source_post_id <= 0) throw new \InvalidArgumentException('A native source ID is required.');
        $this->source_post_id = $source_post_id;
        $identity = home_url('/') . "\0" . Settings::server_url() . "\0" . Settings::connection_id();
        $this->storage = new ProtectedJournal('cms:' . $identity . ($source_post_id === null ? ':from' : ':to:' . $source_post_id));
    }

    public function option_name(): string { return $this->storage->option_name(); }
    public function read(): stdClass|WP_Error|null { return $this->storage->read(); }
    public function write(stdClass $tree, callable $guard): bool
    {
        // Register before the journal write: a crash cannot leave a new protected source tree undiscoverable.
        if ($this->source_post_id !== null && !CmsSourceRecovery::register($this->source_post_id, $this->option_name(), $guard)) return false;
        return $this->storage->write($tree, $guard);
    }
}
