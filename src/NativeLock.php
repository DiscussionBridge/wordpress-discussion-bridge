<?php

declare(strict_types=1);

namespace DiscussionBridge\WordPress;

/** Connection-owned token; a reconnection cannot extend its authority. */
final class NativeLock
{
    private bool $released = false;

    public function __construct(private object $database, private string $name, private string $connection, private bool $owns_release) {}

    public function valid(): bool
    {
        global $wpdb;
        return !$this->released && $wpdb === $this->database && $this->connection !== ''
            && (string) $this->database->get_var('SELECT CONNECTION_ID()') === $this->connection
            && (string) $this->database->get_var($this->database->prepare('SELECT IS_USED_LOCK(%s) = CONNECTION_ID()', $this->name)) === '1';
    }

    public function release(): void
    {
        if ($this->released) return;
        $this->released = true;
        // Release only our original physical connection's lock, never a replacement connection's.
        if (!$this->owns_release) return;
        try {
            if ((string) $this->database->get_var('SELECT CONNECTION_ID()') === $this->connection
                && (string) $this->database->get_var($this->database->prepare('SELECT IS_USED_LOCK(%s) = CONNECTION_ID()', $this->name)) === '1') {
                $this->database->get_var($this->database->prepare('SELECT RELEASE_LOCK(%s)', $this->name));
            }
        } catch (\Throwable) { /* Loss of a connection does not prove native rollback. */ }
    }

    public function __destruct() { $this->release(); }
}
