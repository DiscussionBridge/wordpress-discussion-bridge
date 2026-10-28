<?php

declare(strict_types=1);

namespace DiscussionBridge\WordPress;

use Closure;
use WP_Error;
use WP_Post;

/** Scoped permanent-delete veto and serialization, independent of transport field definitions. */
final class NativeDeletion
{
    private array $contexts = [];
    private ?WP_Error $attention = null;
    private Closure $owns_source;
    private Closure $capture;
    private Closure $confirm;

    public function __construct(private NativeWriter $writer, callable $owns_source, callable $capture, callable $confirm)
    {
        $this->owns_source = Closure::fromCallable($owns_source); $this->capture = Closure::fromCallable($capture); $this->confirm = Closure::fromCallable($confirm);
    }

    public function register(): void
    {
        add_filter('pre_delete_post', [$this, 'before'], PHP_INT_MAX, 3);
        add_action('deleted_post', [$this, 'completed'], PHP_INT_MAX, 2);
        add_action('after_delete_post', [$this, 'after'], PHP_INT_MAX, 2);
        add_action('shutdown', [$this, 'shutdown'], PHP_INT_MAX);
    }

    public function before(mixed $prior, WP_Post $post, bool $force): mixed
    {
        unset($force);
        if ($prior !== null) return $prior;
        try { $owned = ($this->owns_source)($post->ID, $post); }
        catch (\Throwable) { return $this->veto('ownership'); }
        if ($owned === false) return null;
        if ($owned !== true) return $this->veto('ownership');
        $lock = $this->writer->acquire(true);
        if (is_wp_error($lock)) { $this->attention = $lock; return false; }
        try {
            if (($this->capture)($post->ID, $post, fn(): bool => $lock->valid()) !== true || !$lock->valid()) {
                $lock->release(); return $this->veto('capture');
            }
        } catch (\Throwable) { $lock->release(); return $this->veto('capture'); }
        $this->contexts[$post->ID] = ['lock' => $lock, 'confirmed' => false];
        // Core proceeds while the same writer remains held across metadata removal and actual delete.
        return null;
    }

    public function completed(int $id, WP_Post $post): void
    {
        if (!isset($this->contexts[$id])) return;
        $lock = $this->contexts[$id]['lock'];
        try {
            if (!$lock->valid() || ($this->confirm)($id, $post, fn(): bool => $lock->valid()) !== true || !$lock->valid()) {
                $this->attention = new WP_Error('discussionbridge_native_delete_confirmation', 'Native deletion completed; its protected bridge confirmation needs recovery. Preserve the captured journal.');
                return;
            }
            $this->contexts[$id]['confirmed'] = true;
        } catch (\Throwable) {
            $this->attention = new WP_Error('discussionbridge_native_delete_confirmation', 'Native deletion completed; its protected bridge confirmation needs recovery. Preserve the captured journal.');
        }
    }

    public function after(int $id, WP_Post $post): void { unset($post); $this->release($id); }

    public function shutdown(): void
    {
        foreach (array_keys($this->contexts) as $id) {
            if (!$this->contexts[$id]['confirmed'] && $this->attention === null) $this->attention = new WP_Error('discussionbridge_native_delete_unresolved', 'The deletion has no durable completed event. Preserve the captured journal; do not infer rollback or recreate the native post.');
            $this->release($id);
        }
    }

    public function attention(): ?WP_Error { return $this->attention; }

    private function release(int $id): void
    {
        if (!isset($this->contexts[$id])) return;
        $this->contexts[$id]['lock']->release(); unset($this->contexts[$id]);
    }

    private function veto(string $reason): bool
    {
        $this->attention = new WP_Error('discussionbridge_native_delete_' . $reason, 'Deletion paused because the owned source could not be captured durably. Reconcile its protected state before deleting.');
        return false;
    }
}
