<?php
declare(strict_types=1);

namespace DiscussionBridge\CmsV4\IdnaVendor;

use LogicException;
use DiscussionBridge\CmsV4\IdnaVendor\Symfony\Polyfill\Mbstring\Mbstring;

// The IDNA library uses only these two mbstring functions, with explicit UTF-8.
// Call the bundled pure-PHP implementation directly. Do not install global
// polyfills or select a different implementation from the host application's
// extensions or Composer autoloader. This is not a general mbstring API.
function mb_ord(string $value, string $encoding): int|false
{
    if (strtolower($encoding) !== 'utf-8') throw new LogicException('IDNA requires explicit UTF-8');
    return Mbstring::mb_ord($value, 'UTF-8');
}

function mb_str_split(string $value, int $length, string $encoding): array
{
    if (strtolower($encoding) !== 'utf-8') throw new LogicException('IDNA requires explicit UTF-8');
    return Mbstring::mb_str_split($value, $length, 'UTF-8');
}
