<?php

declare (strict_types=1);
namespace DiscussionBridge\CmsV4\IdnaVendor\Rowbot\Idna;

use function chr;
use function DiscussionBridge\CmsV4\IdnaVendor\mb_str_split;
use function DiscussionBridge\CmsV4\IdnaVendor\mb_ord;
use function ord;
use function unpack;
final class CodePoint
{
    /**
     * Takes a Unicode code point and encodes it. The return behavior is undefined if the given
     * code point is outside the range 0..10FFFF.
     *
     * @see https://encoding.spec.whatwg.org/#utf-8-encoder
     */
    public static function encode(int $codePoint): string
    {
        if ($codePoint >= 0x0 && $codePoint <= 0x7f) {
            return chr($codePoint);
        }
        $count = 0;
        $offset = 0;
        if ($codePoint >= 0x80 && $codePoint <= 0x7ff) {
            $count = 1;
            $offset = 0xc0;
        } elseif ($codePoint >= 0x800 && $codePoint <= 0xffff) {
            $count = 2;
            $offset = 0xe0;
        } elseif ($codePoint >= 0x10000 && $codePoint <= 0x10ffff) {
            $count = 3;
            $offset = 0xf0;
        }
        $bytes = chr(($codePoint >> 6 * $count) + $offset);
        while ($count > 0) {
            $temp = $codePoint >> 6 * ($count - 1);
            $bytes .= chr(0x80 | $temp & 0x3f);
            --$count;
        }
        return $bytes;
    }
    /**
     * @return list<int>
     */
    public static function utf8Decode(string $input): array
    {
        $codePoints = [];
        foreach (mb_str_split($input, 1, 'utf-8') as $s) {
            // First, try the common case of a valid UTF-8 codepoint. mb_ord() will return false if the code point is
            // not valid such as an unpaired surrogate.
            $code = mb_ord($s, 'utf-8');
            if ($code !== \false) {
                $codePoints[] = $code;
                continue;
            }
            // Next, check if the code point was an unpaired surrogate as we still want to pass them through and
            // mb_str_split() will give us the unpaired surrogate despite mb_ord() returning false for it.
            if ($s >= "í €" && $s <= "í­¿" || $s >= "í°€" && $s <= "í¿¿") {
                /** @var array<int>|false $code */
                $code = unpack('C*', $s);
                if ($code === \false) {
                    // Is outputing a replacement character the right thing to do here? Should we throw instead, even
                    // though  nothing else throws?
                    $codePoints[] = 0xfffd;
                    continue;
                }
                $codePoints[] = ($code[1] - 0xe0 << 12) + ($code[2] - 0x80 << 6) + $code[3] - 0x80;
                continue;
            }
            // Lastly, no codepoint could be formed and mb_str_split() should be returning individual bytes.
            $codePoints[] = ord($s);
        }
        return $codePoints;
    }
}
