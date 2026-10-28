<?php

declare(strict_types=1);

namespace DiscussionBridge\WordPress;

/** Private native preparation observation; it is not a protocol Failure. */
final class CmsNativeBodyMismatch extends \RuntimeException
{
    public function __construct(public readonly array $observation)
    {
        parent::__construct('Native final preparation changed the complete owned source body before SQL.');
    }
}
