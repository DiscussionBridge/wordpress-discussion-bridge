<?php
// GENERATED V4 candidate; no earlier-version compatibility.
declare(strict_types=1);

namespace DiscussionBridge\CmsV4;

use DateTimeImmutable;
use DateTimeZone;
use JsonException;
use RuntimeException;
use stdClass;

/** Local typed validation only; callers own HTTP/authentication/native authority. */
final class ValidationException extends RuntimeException
{
    public function __construct(
        public readonly string $validationCode,
        public readonly string $path = '$',
        public readonly int $httpStatus = 422
    ) {
        parent::__construct('CMS ' . $validationCode . ' at ' . $path);
    }
}

/** An implementation limitation, not acceptance or a newly defined wire error. */
final class UnsupportedBehaviorException extends RuntimeException {}

enum NoContent { case VALUE; }

/** Internal lexical provenance. Never extract, clone or encode before validation. */
final class InvalidNumericValue {}

final class Codec
{
    public const SCHEMA_SHA256 = 'e6a15a6d70f7bc8b8ee82aa22a0b9c2ff1bbf2da6277f7087118be9e026ce5c0';
    public const IDNA_SHA256 = '478d6e5c8f96255f7124e5bd0d28f79975d4b8466fea097ef2edb2fb1151c282';
    public const IMPLEMENTATION_VERSION = '0.4.0-alpha.1';
    public const CANDIDATE_ID = 'discussionbridge-v4-core-20261029-r7';
    public const WIRE_VERSION = 'v4';
    private const SAFE_INTEGER = 9007199254740991;
    private const WHITE = '\x{0009}-\x{000D}\x{0020}\x{00A0}\x{1680}\x{2000}-\x{200A}\x{2028}\x{2029}\x{202F}\x{205F}\x{3000}\x{FEFF}';
    private array $schema;
    private array $routes = [];
    private array $errors = [];
    private static bool $idnaVerified = false;

    public function __construct(string $schemaPath)
    {
        if (PHP_INT_SIZE < 8) {
            throw new UnsupportedBehaviorException('CMS codec requires 64-bit PHP integers');
        }
        $bytes = @file_get_contents($schemaPath);
        if ($bytes === false || hash('sha256', $bytes) !== self::SCHEMA_SHA256) {
            throw new RuntimeException('CMS extracted grammar identity mismatch');
        }
        $this->schema = json_decode($bytes, true, 512, JSON_THROW_ON_ERROR);
        self::loadIdna();
        if (IdnaVendor\Rowbot\Idna\Idna::UNICODE_VERSION !== $this->schema['types']['Url']['domain_policy']['unicode_version']) {
            throw new RuntimeException('CMS IDNA policy identity mismatch');
        }
        foreach ($this->schema['routes'] as $route) {
            $this->routes[$route['id']] = $route;
        }
        foreach ($this->schema['errors'] as $error) {
            $this->errors[$error['code']] = $error;
        }
    }

    private static function loadIdna(): void
    {
        if (self::$idnaVerified) return;
        $base = __DIR__ . '/idna/';
        $bytes = @file_get_contents($base . 'DEPENDENCIES.json');
        if ($bytes === false || hash('sha256', $bytes) !== self::IDNA_SHA256) {
            throw new RuntimeException('CMS private IDNA identity mismatch');
        }
        $manifest = json_decode($bytes, true, 512, JSON_THROW_ON_ERROR);
        // The exact trusted manifest identifies every runtime member, including
        // Private bootstrap, classes, Unicode data and upstream licenses.
        foreach ($manifest['members'] as $member) {
            $content = @file_get_contents($base . $member['path']);
            if ($content === false || strlen($content) !== $member['bytes'] || hash('sha256', $content) !== $member['sha256']) {
                throw new RuntimeException('CMS private IDNA member mismatch');
            }
        }
        require_once $base . 'vendor/autoload.php';
        self::$idnaVerified = true;
    }

    public function route(string $id): array
    {
        return $this->routes[$id] ?? throw new RuntimeException('Unavailable CMS route');
    }

    public function hasType(string $name): bool
    {
        return isset($this->schema['types'][$name]);
    }

    /** Local comparison facts; never replacement URL/identity or stored key. */
    public function urlAuthority(mixed $value): array
    {
        $this->validate('Url', $value);
        $parsed = $this->url($value, '$');
        return ['rawHost' => $parsed['host'], 'asciiHost' => $parsed['_cms_ascii_host'],
            'effectivePort' => $parsed['port'] ?? 443];
    }

    private function fail(string $path = '$', string $code = 'invalid_field'): never
    {
        throw new ValidationException($code, $path, $this->errors[$code]['http'] ?? 422);
    }

    private function type(string $name): array
    {
        return $this->schema['types'][$name] ?? throw new RuntimeException('Unavailable CMS type');
    }

    private function utf8(string $value, string $path): void
    {
        if (preg_match('//u', $value) !== 1) {
            $this->fail($path);
        }
    }

    private function integer(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value >= 0 && $value <= self::SAFE_INTEGER ? $value : null;
        }
        if (is_float($value) && is_finite($value) && $value >= 0 && $value <= self::SAFE_INTEGER
            && floor($value) === $value && !($value == 0 && fdiv(1.0, $value) < 0)) {
            return (int) $value;
        }
        return null;
    }

    private function same(mixed $left, mixed $right): bool
    {
        if ((is_int($left) || is_float($left)) && (is_int($right) || is_float($right))) {
            return $this->integer($left) !== null && $this->integer($left) === $this->integer($right);
        }
        return $left === $right;
    }

    private function base64(string $value, bool $url, string $path): string
    {
        $pattern = $url ? '~^[A-Za-z0-9_-]*$~D'
            : '~^(?:[A-Za-z0-9+/]{4})*(?:[A-Za-z0-9+/]{2}==|[A-Za-z0-9+/]{3}=)?$~D';
        if (preg_match($pattern, $value) !== 1) {
            $this->fail($path);
        }
        $decoded = base64_decode($url ? strtr($value, '-_', '+/') : $value, true);
        if ($decoded === false) {
            $this->fail($path);
        }
        $encoded = $url ? rtrim(strtr(base64_encode($decoded), '+/', '-_'), '=') : base64_encode($decoded);
        if ($encoded !== $value) {
            $this->fail($path);
        }
        return $decoded;
    }

    private function instant(string $value, string $path): void
    {
        if (preg_match('~^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{3}Z$~D', $value) !== 1) {
            $this->fail($path);
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s.v\Z', $value, new DateTimeZone('UTC'));
        if ($date === false || $date->format('Y-m-d\TH:i:s.v\Z') !== $value) {
            $this->fail($path);
        }
    }

    /** WHATWG IPv4 number syntax, bounded before arithmetic can overflow. */
    private function ipv4Number(string $value): ?int
    {
        if ($value === '') return null;
        $radix = 10;
        if (strlen($value) >= 2 && strtolower(substr($value, 0, 2)) === '0x') {
            $value = substr($value, 2);
            $radix = 16;
        } elseif (strlen($value) >= 2 && $value[0] === '0') {
            $value = substr($value, 1);
            $radix = 8;
        }
        if ($value === '') return 0;
        $pattern = match ($radix) { 16 => '~^[a-fA-F0-9]+$~D', 8 => '~^[0-7]+$~D', default => '~^[0-9]+$~D' };
        if (preg_match($pattern, $value) !== 1) return null;
        $number = 0;
        foreach (str_split(strtolower($value)) as $digit) {
            $ordinal = ord($digit);
            $integer = $ordinal >= 97 ? $ordinal - 87 : $ordinal - 48;
            // All accepted IPv4 numbers are <= 2^32 - 1. Preserve an overflow
            // sentinel for rejection, never float/cast/wrap a caller's input.
            if ($number > intdiv(4294967295 - $integer, $radix)) return 4294967296;
            $number = $number * $radix + $integer;
        }
        return $number;
    }

    /** Validate numeric hosts without replacing the original URL/identity bytes. */
    private function ipv4Host(string $host, string $path): void
    {
        // https://url.spec.whatwg.org/#ends-in-a-number and #concept-ipv4-parser
        // Remove only one terminal empty part; multiple dots can be a DNS host.
        $parts = explode('.', $host);
        if (end($parts) === '') array_pop($parts);
        $last = end($parts);
        if ($last === false || (preg_match('~^[0-9]+$~D', $last) !== 1 && $this->ipv4Number($last) === null)) return;
        if (count($parts) > 4) $this->fail($path);
        foreach ($parts as $index => $part) {
            $number = $this->ipv4Number($part);
            $maximum = $index === count($parts) - 1 ? 256 ** (5 - count($parts)) - 1 : 255;
            if ($number === null || $number > $maximum) $this->fail($path);
        }
    }

    /**
     * Validate HTTPS hosts using the bundled non-transitional UTS46 algorithm
     * with WHATWG options, then numeric IPv4 or bracketed IPv6 checks. Converted
     * host bytes are local validation evidence only, never caller identity.
     */
    private function url(string $value, string $path): array
    {
        if (preg_match('~^https://([^/?#]+)([^?#]*)(\?[^#]*)?$~iD', $value, $parts) !== 1
            || preg_match('~[' . self::WHITE . '\p{Cc}]~u', $value) === 1
            || str_contains($parts[1], '@') || preg_match('~%(?![a-f\d]{2})~i', $value) === 1) {
            $this->fail($path);
        }
        if (str_contains($value, '\\')) {
            $this->fail($path);
        }
        // Native parse_url admits signed ports and rejects some long leading-
        // zero spellings. Use the same bounded authority lexer as JavaScript;
        // these are local components, never a reserialized caller URL.
        if (preg_match('~^(\[[^\]]+\]|[^:]+)(?::([0-9]*))?$~D', $parts[1], $authority) !== 1) $this->fail($path);
        $port = 443;
        if (isset($authority[2]) && $authority[2] !== '') {
            $port = 0;
            for ($index = 0, $count = strlen($authority[2]); $index < $count; ++$index) {
                $port = $port * 10 + ord($authority[2][$index]) - 48;
                if ($port > 65535) $this->fail($path);
            }
        }
        $parsed = ['scheme' => 'https', 'host' => $authority[1], 'port' => $port, 'path' => $parts[2]];
        if (isset($parts[3])) $parsed['query'] = substr($parts[3], 1);
        foreach (explode('/', $parts[2]) as $part) {
            $dots = preg_replace('~%2e~i', '.', $part);
            if ($dots === '.' || $dots === '..') {
                $this->fail($path);
            }
        }
        $host = rawurldecode($parsed['host']);
        $this->utf8($host, $path);
        $ascii = strtolower($parsed['host']);
        if (str_starts_with($host, '[') && str_ends_with($host, ']')) {
            if (!str_starts_with($parsed['host'], '[') || str_contains($parsed['host'], '%')) $this->fail($path);
            $ip = @inet_pton(substr($host, 1, -1));
            if ($ip === false || strlen($ip) !== 16) {
                $this->fail($path);
            }
        } else {
            if (preg_match('~[\x00-\x20\x7f%#/:<>?@\[\]\\\\^|]~', $host) === 1) {
                $this->fail($path);
            }
            $policy = $this->schema['types']['Url']['domain_policy'];
            $result = IdnaVendor\Rowbot\Idna\Idna::toAscii($host, [
                'CheckHyphens' => $policy['check_hyphens'], 'CheckBidi' => $policy['check_bidi'],
                'CheckJoiners' => $policy['check_joiners'], 'UseSTD3ASCIIRules' => $policy['use_std3_ascii_rules'],
                'Transitional_Processing' => $policy['transitional_processing'],
                'IgnoreInvalidPunycode' => $policy['ignore_invalid_punycode'], 'VerifyDnsLength' => $policy['verify_dns_length'],
            ]);
            $ascii = $result->getDomain();
            if ($result->hasErrors() || $ascii === ''
                || preg_match('~[^\x21-\x7e]|[%#/:<>?@\[\]\\\\^|]~', $ascii) === 1) $this->fail($path);
            $this->ipv4Host($ascii, $path);
        }
        return $parsed + ['_cms_ascii_host' => $ascii];
    }

    private function string(string $name, array $spec, mixed $value, string $path): void
    {
        if (!is_string($value)) {
            $this->fail($path);
        }
        $this->utf8($value, $path);
        $bytes = strlen($value);
        if ($bytes < ($spec['utf8_minimum_bytes'] ?? 0) || $bytes > ($spec['utf8_maximum_bytes'] ?? PHP_INT_MAX)) {
            $this->fail($path);
        }
        if (isset($spec['pattern']) && preg_match('~' . str_replace('~', '\~', $spec['pattern']) . '~uD', $value) !== 1) {
            $this->fail($path);
        }
        if (($spec['control_characters'] ?? null) === 'reject' && preg_match('~\p{Cc}~u', $value) === 1) {
            $this->fail($path);
        }
        if (in_array($name, ['OpaqueId', 'Revision', 'Label', 'Title'], true)
            && preg_match('~^[' . self::WHITE . ']*$~uD', $value) === 1) {
            $this->fail($path);
        }
        if ($name === 'UUID' && $value === '00000000-0000-0000-0000-000000000000') {
            $this->fail($path);
        }
        if ($name === 'CoreId') {
            if (strlen($value) > 19 || (strlen($value) === 19 && strcmp($value, '9223372036854775807') > 0)) {
                $this->fail($path);
            }
        }
        if ($name === 'Instant') {
            $this->instant($value, $path);
        }
        if ($name === 'Url') {
            $this->url($value, $path);
        }
        if (in_array($name, ['Base64', 'Base64Url', 'LeaseToken', 'PublicNonce'], true)) {
            $decoded = $this->base64($value, $name !== 'Base64', $path);
            if (isset($spec['decoded_bytes']) && strlen($decoded) !== $spec['decoded_bytes']) {
                $this->fail($path);
            }
        }
    }

    private function shape(string $name, mixed $value, string $path): void
    {
        if ($value instanceof InvalidNumericValue) {
            $this->fail($path);
        }
        $spec = $this->type($name);
        switch ($spec['kind']) {
            case 'string':
                $this->string($name, $spec, $value, $path);
                break;
            case 'integer':
                $integer = $this->integer($value);
                if ($integer === null || $integer < $spec['minimum'] || $integer > $spec['maximum']) {
                    $this->fail($path);
                }
                break;
            case 'boolean':
                if (!is_bool($value)) {
                    $this->fail($path);
                }
                break;
            case 'enum':
                foreach ($spec['values'] as $item) {
                    if ($this->same($value, $item)) {
                        return;
                    }
                }
                $this->fail($path);
            case 'array':
                if (!is_array($value) || !array_is_list($value)
                    || count($value) < ($spec['minimum_items'] ?? 0)
                    || count($value) > ($spec['maximum_items'] ?? PHP_INT_MAX)) {
                    $this->fail($path);
                }
                $identities = [];
                foreach ($value as $index => $item) {
                    $this->shape($spec['items'], $item, $path . '[' . $index . ']');
                    if ($spec['unique_items'] ?? false) {
                        $identity = $this->semanticIdentity($item);
                        if (array_key_exists($identity, $identities)) {
                            $this->fail($path);
                        }
                        $identities[$identity] = true;
                    }
                }
                break;
            case 'object':
                if (!$value instanceof stdClass) {
                    $this->fail($path);
                }
                foreach ($spec['fields'] as $field => $rule) {
                    if (!property_exists($value, $field)) {
                        if ($rule['required'] ?? false) {
                            $this->fail($path . '.' . $field);
                        }
                    } elseif ($value->$field === null) {
                        if (!($rule['nullable'] ?? false)) {
                            $this->fail($path . '.' . $field);
                        }
                    } else {
                        $this->shape($rule['type'], $value->$field, $path . '.' . $field);
                    }
                }
                foreach (array_keys(get_object_vars($value)) as $field) {
                    if (!array_key_exists($field, $spec['fields'])) {
                        $this->fail($path, 'unknown_field');
                    }
                }
                break;
            case 'tagged_union':
                $field = $spec['field'];
                if (!$value instanceof stdClass || !property_exists($value, $field)
                    || !is_string($value->$field) || !isset($spec['variants'][$value->$field])) {
                    $this->fail($path . '.' . $field);
                }
                $this->shape($spec['variants'][$value->$field], $value, $path);
                break;
            default:
                throw new UnsupportedBehaviorException('Unsupported CMS grammar kind');
        }
    }

    private function semantics(string $name, mixed $value, string $path): void
    {
        $spec = $this->type($name);
        if ($spec['kind'] === 'object') {
            foreach ($spec['fields'] as $field => $rule) {
                if (property_exists($value, $field) && $value->$field !== null) {
                    $this->semantics($rule['type'], $value->$field, $path . '.' . $field);
                }
            }
            if (isset($value->source_created_at, $value->source_updated_at) && $value->source_updated_at < $value->source_created_at) {
                $this->fail($path . '.source_updated_at');
            }
        } elseif ($spec['kind'] === 'array') {
            foreach ($value as $index => $item) {
                $this->semantics($spec['items'], $item, $path . '[' . $index . ']');
            }
        } elseif ($spec['kind'] === 'tagged_union') {
            $this->semantics($spec['variants'][$value->{$spec['field']}], $value, $path);
        }
        if ($name === 'SourceAuthors') {
            $seen = [];
            foreach ($value as $author) {
                if (in_array($author->id, $seen, true)) {
                    $this->fail($path);
                }
                $seen[] = $author->id;
            }
        }
        if ($name === 'ToRecord') {
            if (isset($value->primary_source_author_id)
                && !in_array($value->primary_source_author_id, array_map(static fn($author) => $author->id, $value->source_authors ?? []), true)) {
                $this->fail($path . '.primary_source_author_id');
            }
            if ($value->representation->kind === 'full'
                && (strlen($value->content_html) !== $this->integer($value->source_content_bytes)
                    || hash('sha256', $value->content_html) !== $value->source_content_sha256)) {
                $this->fail($path . '.source_content_sha256', 'content_hash_mismatch');
            }
        }
        if ($name === 'ToRecord' && $value->representation->kind === 'generated_excerpt') {
            $this->checkGeneratedExcerpt($value, $path);
        }
        if ($name === 'SourceFeedQuery' && $value->stream === 'changes' && !property_exists($value, 'cursor')) {
            $this->fail($path . '.cursor');
        }
        if ($name === 'SourceFeed') {
            if (($value->stream === 'initial' && (($value->next_cursor === null) === ($value->checkpoint_cursor === null)))
                || ($value->stream === 'changes' && $value->checkpoint_cursor === null)) {
                $this->fail($path . '.checkpoint_cursor');
            }
        }
        if ($name === 'ContentChunk') {
            $length = strlen($this->base64($value->content_base64, false, $path . '.content_base64'));
            $end = $value->offset + $length;
            if ($length > 32768 || $end > self::SAFE_INTEGER || $end > $value->content_bytes
                || ($value->next_offset === null
                    ? $end != $value->content_bytes
                    : $value->next_offset != $end || $end <= $value->offset || $end >= $value->content_bytes)) {
                $this->fail($path . '.next_offset');
            }
        }
        if ($name === 'Policy') {
            if (($value->selection === 'categories') !== (count($value->category_ids) > 0)) {
                $this->fail($path . '.category_ids');
            }
            foreach ($value->include_topic_ids as $id) {
                if (in_array($id, $value->exclude_topic_ids, true)) {
                    $this->fail($path . '.exclude_topic_ids');
                }
            }
        }
        if ($name === 'Connection') {
            $url = $this->url($value->destination_origin, $path . '.destination_origin');
            if (!in_array($url['path'] ?? '', ['', '/'], true) || isset($url['query']) || strpbrk($value->destination_origin, '?#') !== false) {
                $this->fail($path . '.destination_origin');
            }
        }
        if ($name === 'Work' && (($value->action === 'withdraw') !== ($value->source_revision === null) || $value->attempt > 3)) {
            $this->fail($path);
        }
        if ($name === 'Work' && $value->last_failure_code === 'content_preservation_unavailable') {
            $this->fail($path . '.last_failure_code');
        }
        if ($name === 'Lease' && (!$this->same($value->lease_generation, $value->work->lease_generation) || $value->expires_at <= $value->server_time)) {
            $this->fail($path . '.lease_generation');
        }
        if ($name === 'Failure' || $name === 'Error') {
            if ($name === 'Failure' && $value->code === 'content_preservation_unavailable') {
                $this->fail($path . '.code');
            }
            $error = $this->errors[$value->code];
            if ($name === 'Error' && $value->retryable !== $error['retryable']) {
                $this->fail($path . '.retryable');
            }
            if ($value->retry_after_seconds !== null && (!$error['retryable'] || $value->retry_after_seconds < 1 || $value->retry_after_seconds > 3600)) {
                $this->fail($path . '.retry_after_seconds');
            }
        }
        if ($name === 'PublishedVerification' && $value->source_content_sha256 !== $value->representation->source_content_sha256) {
            $this->fail($path . '.representation.source_content_sha256');
        }
        if ($name === 'SourceUrlMoveFinalize' && ($value->new_source_revision === $value->expected_source_revision
            || $value->verification->source_revision !== $value->new_source_revision)) {
            $this->fail($path . '.new_source_revision');
        }
        if ($name === 'AttentionPreparedTransaction') {
            if (($value->action === 'withdraw') !== ($value->source_revision === null) || $value->native_result->kind !== $value->action
                || ($value->deployment_id === null) !== ($value->deployed_at === null)
                || ($value->deployed_at !== null && $value->deployed_at < $value->prepared_at)) {
                $this->fail($path);
            }
        }
        if ($name === 'AttentionInspection' || $name === 'AttentionRecovery') {
            $work = $value->work; $mapping = $value->mapping; $source = $value->source;
            if ($work->mapping_id !== $mapping->mapping_id || $work->source_id !== $mapping->source_id
                || ($work->action === 'publish_revision' && $source === null)
                || ($source !== null && ($source->source_id !== $work->source_id || ($work->action === 'publish_revision' && $source->source_revision !== $work->source_revision)))) {
                $this->fail($path, 'identity_conflict');
            }
            if ($name === 'AttentionInspection') {
                if ($work->state !== 'attention') $this->fail($path . '.work.state');
                if ($value->prepared_transaction !== null) {
                    foreach (['work_id', 'action', 'source_revision', 'policy_revision', 'lease_generation'] as $key) {
                        if (!$this->same($value->prepared_transaction->$key, $work->$key)) $this->fail($path . '.prepared_transaction', 'identity_conflict');
                    }
                }
            } else {
                if ($work->work_id === $value->predecessor_work_id || $work->attempt_cycle === $value->predecessor_attempt_cycle || $work->state !== 'pending'
                    || $this->integer($work->attempt) !== 0 || $work->retry_at !== null || $work->last_failure_code !== null) $this->fail($path . '.work');
                if ($value->mode === 'settle_restored_baseline' && ($value->native_baseline === null || $value->verification === null
                    || $value->native_baseline->native_id !== $value->verification->native_id || $value->native_baseline->canonical_url !== $value->verification->url)) $this->fail($path . '.native_baseline');
            }
        }
        if ($name === 'AttentionInspectionPage' || $name === 'AttentionRecoveryPage') {
            $previous = null;
            foreach ($value->items as $item) {
                if ($previous !== null && $item->sequence <= $previous) $this->fail($path . '.next_after');
                $previous = $item->sequence;
            }
            if (($previous !== null && !$this->same($value->next_after, $previous)) || ($previous === null && $value->has_more)) $this->fail($path . '.next_after');
        }
        if ($name === 'AttentionObservation') {
            foreach (['ownership', 'native_result', 'verification'] as $field) {
                if ($value->$field !== null && ($value->native_id === null || $value->$field->native_id !== $value->native_id)) $this->fail($path . '.' . $field, 'identity_conflict');
            }
        }
        if ($name === 'AttentionInspectionResult' && $value->status === 'ready' && ($value->observed_at === null || $value->issue !== 'none')) $this->fail($path);
        if ($name === 'AttentionCheckSelections' || $name === 'AttentionRetrySelections') {
            $key = $name === 'AttentionCheckSelections' ? 'work_id' : 'inspection_id'; $seen = [];
            foreach ($value as $item) {
                if (in_array($item->$key, $seen, true)) $this->fail($path);
                $seen[] = $item->$key;
            }
        }
        if ($name === 'AttentionAdminItemResult' && $value->status === 'queued' && ($value->inspection_id === null || $value->recovery_id === null
            || $value->successor_work_id === null || $value->successor_work_id === $value->work_id)) $this->fail($path);
        if ($name === 'ResolveResult') {
            $conflict = $value->outcome === 'reconciliation_required';
            foreach (['resource_id', 'topic_id', 'topic_url', 'source_revision', 'synchronized_at'] as $field) {
                if (($value->$field === null) !== $conflict) {
                    $this->fail($path);
                }
            }
            if ($conflict !== in_array($value->reason, ['identity_conflict', 'canonical_url_conflict', 'native_ownership_conflict', 'adoption_unavailable'], true)) {
                $this->fail($path . '.reason');
            }
        }
    }

    public function validate(string $name, mixed $value): mixed
    {
        $this->shape($name, $value, '$');
        $this->semantics($name, $value, '$');
        return $value;
    }

    public function assertAttentionRecoveryCapability(stdClass $capability, string $profile): stdClass
    {
        $this->validate('AttentionRecoveryCapability', $capability);
        $this->validate('AttentionInspectionProfile', $profile);
        if (!in_array($profile, $capability->inspection_profiles, true)) $this->fail('$.inspection_profiles');
        return $capability;
    }

    public function validateAttentionFeedPage(string $type, stdClass $page, int $after = 0): stdClass
    {
        if (!in_array($type, ['AttentionInspectionPage', 'AttentionRecoveryPage'], true)) throw new UnsupportedBehaviorException('Unavailable attention feed');
        $this->validate('UInt', $after); $this->validate($type, $page);
        foreach ($page->items as $item) if ($item->sequence <= $after) $this->fail('$.next_after');
        if ($page->items === [] && !$this->same($page->next_after, $after)) $this->fail('$.next_after');
        return $page;
    }

    public function validateAttentionRecoveryLease(stdClass $recovery, stdClass $lease): stdClass
    {
        $this->validate('AttentionRecovery', $recovery); $this->validate('Lease', $lease);
        foreach (['work_id', 'mapping_id', 'source_id', 'source_revision', 'policy_revision', 'action', 'attempt_cycle'] as $key) {
            if (!$this->same($recovery->work->$key, $lease->work->$key)) $this->fail('$.work', 'identity_conflict');
        }
        if ($lease->lease_generation <= $recovery->work->lease_generation) $this->fail('$.work', 'identity_conflict');
        return $lease;
    }

    public function defaults(string $name, mixed $value): mixed
    {
        $this->validate($name, $value);
        return $this->applyDefaults($name, $value);
    }

    private function applyDefaults(string $name, mixed $value): mixed
    {
        $spec = $this->type($name);
        if ($spec['kind'] === 'object') {
            $result = new stdClass();
            foreach ($spec['fields'] as $field => $rule) {
                if (property_exists($value, $field)) {
                    $result->$field = $value->$field === null ? null : $this->applyDefaults($rule['type'], $value->$field);
                } elseif (array_key_exists('default', $rule)) {
                    $result->$field = $rule['default'];
                }
            }
            return $result;
        }
        if ($spec['kind'] === 'array') {
            return array_map(fn($item) => $this->applyDefaults($spec['items'], $item), $value);
        }
        if ($spec['kind'] === 'tagged_union') {
            return $this->applyDefaults($spec['variants'][$value->{$spec['field']}], $value);
        }
        return $value;
    }

    public function parseJson(string $raw, int $maximumBytes = 65536, bool $rejectInvalidNumbers = true): stdClass
    {
        return (new StrictJson($raw, $maximumBytes, $rejectInvalidNumbers))->parse();
    }

    public function parseQuery(?string $type, string $raw = ''): stdClass
    {
        $spec = $type === null ? ['fields' => []] : $this->type($type);
        if ($type !== null && $spec['kind'] !== 'object') {
            $this->fail('$.query');
        }
        $result = new stdClass();
        if ($raw !== '') {
            foreach (explode('&', $raw) as $member) {
                if ($member === '') {
                    $this->fail('$.query');
                }
                $pair = explode('=', $member, 2);
                $decode = function (string $encoded): string {
                    if (preg_match('~%(?![a-f\d]{2})~i', $encoded) === 1) {
                        $this->fail('$.query');
                    }
                    $decoded = rawurldecode(str_replace('+', ' ', $encoded));
                    $this->utf8($decoded, '$.query');
                    return $decoded;
                };
                $key = $decode($pair[0]);
                $value = $decode($pair[1] ?? '');
                if (!array_key_exists($key, $spec['fields'])) {
                    $this->fail('$.query', 'unknown_field');
                }
                if (property_exists($result, $key)) {
                    $this->fail('$.query');
                }
                if ($this->type($spec['fields'][$key]['type'])['kind'] === 'integer') {
                    if (preg_match('~^(?:0|[1-9]\d*)$~D', $value) !== 1) {
                        $this->fail('$.query');
                    }
                    $value = strlen($value) > 16 || (strlen($value) === 16 && strcmp($value, '9007199254740991') > 0)
                        ? new InvalidNumericValue() : (int) $value;
                }
                $result->$key = $value;
            }
        }
        if ($type !== null) {
            $this->validate($type, $result);
        }
        return $result;
    }

    public function parseRequest(string $id, array $options = []): array
    {
        $route = $this->route($id);
        if (array_key_exists('method', $options) && $options['method'] !== $route['method']) {
            $this->fail();
        }
        $validated = [];
        if ($route['method'] === 'POST') {
            if (!is_string($options['mediaType'] ?? null)
                || preg_match('~^application/json(?:[' . self::WHITE . ']*;[' . self::WHITE . ']*charset[' . self::WHITE . ']*=[' . self::WHITE . ']*(?:UTF-8|"UTF-8"))?[' . self::WHITE . ']*$~iuD', $options['mediaType']) !== 1
                || (array_key_exists('contentEncoding', $options) && strtolower((string) $options['contentEncoding']) !== 'identity')) {
                $this->fail('$', 'unsupported_media_type');
            }
            if (!is_string($options['rawBody'] ?? null)) {
                $this->fail('$', 'invalid_json');
            }
            $validated['body'] = $this->parseJson($options['rawBody'], 65536, false);
        } elseif (array_key_exists('rawBody', $options) && $options['rawBody'] !== '') {
            $this->fail();
        }
        $queryRaw = array_key_exists('queryString', $options) ? $options['queryString'] : '';
        if (!is_string($queryRaw)) {
            $this->fail('$.query');
        }
        $validated['query'] = $this->parseQuery($route['query'], $queryRaw);
        foreach (['path', 'method'] as $field) {
            if (array_key_exists($field, $options)) {
                $validated[$field] = $options[$field];
            }
        }
        $this->validateRoute($id, $validated);
        return array_intersect_key($validated, ['body' => true, 'query' => true]);
    }

    public function validateRoute(string $id, array $options = []): array
    {
        $route = $this->route($id);
        if (array_key_exists('method', $options) && $options['method'] !== $route['method']) {
            $this->fail();
        }
        if ($route['input'] !== null) {
            $this->validate($route['input'], $options['body'] ?? NoContent::VALUE);
        } elseif (array_key_exists('body', $options)) {
            $this->fail();
        }
        $query = array_key_exists('query', $options) ? $options['query'] : new stdClass();
        if ($route['query'] !== null) {
            $this->validate($route['query'], $query);
        } elseif (!$query instanceof stdClass || count(get_object_vars($query)) !== 0) {
            $this->fail('$.query', 'unknown_field');
        }
        $path = $options['path'] ?? new stdClass();
        if (is_array($path)) {
            $path = (object) $path;
        }
        if (!$path instanceof stdClass) {
            $this->fail();
        }
        preg_match_all('~\{([^}]+)\}~', $route['path'], $matches);
        foreach ($matches[1] as $key) {
            $type = $key === 'source_id' ? 'OpaqueId' : ($key === 'source_revision' ? 'Revision' : 'UUID');
            $this->validate($type, $path->$key ?? NoContent::VALUE);
            $body = $options['body'] ?? null;
            $bodyValue = $body->$key ?? $body->authority->$key ?? null;
            if ($bodyValue !== null && $bodyValue !== $path->$key) {
                $this->fail('$.' . $key, 'identity_conflict');
            }
        }
        return $route;
    }

    public function validateResponse(string $id, mixed $value, int $status): mixed
    {
        $route = $this->route($id);
        if ($status === 204 && in_array(204, $route['success_http'], true)) {
            if ($value !== NoContent::VALUE) {
                $this->fail();
            }
            return $value;
        }
        if (in_array($status, $route['success_http'], true)) {
            $this->validate($route['response'], $value);
            if ($id === 'V4-02') {
                $expectedStatus = ['created' => 201, 'resolved' => 200, 'updated' => 200, 'reconciliation_required' => 409];
                if ($status !== $expectedStatus[$value->outcome]) {
                    $this->fail();
                }
            }
        } elseif ($value instanceof stdClass && ($value->outcome ?? null) === 'rejected') {
            $this->validate('Error', $value);
            if ($value->code === 'content_preservation_unavailable' && !in_array($id, ['V4-02', 'V4-29'], true)) {
                $this->fail('$.code');
            }
            if ($this->errors[$value->code]['http'] !== $status) {
                $this->fail();
            }
        } elseif ($id === 'V4-02' && $status === 409 && $value instanceof stdClass
            && ($value->outcome ?? null) === 'reconciliation_required') {
            $this->validate('ResolveResult', $value);
        } else {
            $this->fail();
        }
        $maximum = $route['response'] === 'ContentChunk' ? 65536 : 262144;
        if (strlen($this->encodeJson($value)) > $maximum) {
            $this->fail('$', 'metadata_unavailable');
        }
        return $value;
    }

    public function decodeResponse(string $id, string $raw, int $status): mixed
    {
        $route = $this->route($id);
        if ($status === 204 && in_array(204, $route['success_http'], true)) {
            return $this->validateResponse($id, $raw === '' ? NoContent::VALUE : null, $status);
        }
        $value = $this->parseJson($raw, $route['response'] === 'ContentChunk' ? 65536 : 262144, false);
        return $this->validateResponse($id, $value, $status);
    }

    private function encodable(mixed $value, int $depth = 0): void
    {
        if ($depth > 512) {
            throw new UnsupportedBehaviorException('CMS JSON depth above 512 is unsupported');
        }
        if ($value instanceof stdClass) {
            foreach (get_object_vars($value) as $key => $item) {
                $this->utf8((string) $key, '$');
                $this->encodable($item, $depth + 1);
            }
        } elseif (is_array($value) && array_is_list($value)) {
            foreach ($value as $item) {
                $this->encodable($item, $depth + 1);
            }
        } elseif (is_string($value)) {
            $this->utf8($value, '$');
        } elseif (is_int($value) || is_float($value)) {
            if ($this->integer($value) === null) {
                $this->fail();
            }
        } elseif ($value !== null && !is_bool($value)) {
            $this->fail();
        }
    }

    public function encodeJson(mixed $value): string
    {
        $this->encodable($value);
        try {
            return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE
                | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_LINE_TERMINATORS, 514);
        } catch (JsonException) {
            $this->fail('$', 'invalid_json');
        }
    }

    public function semanticIdentity(mixed $value): string
    {
        if ($value instanceof stdClass) {
            $fields = get_object_vars($value);
            ksort($fields, SORT_STRING);
            $parts = [];
            foreach ($fields as $key => $item) {
                $parts[] = $this->encodeJson((string) $key) . ':' . $this->semanticIdentity($item);
            }
            return '{' . implode(',', $parts) . '}';
        }
        if (is_array($value) && array_is_list($value)) {
            return '[' . implode(',', array_map(fn($item) => $this->semanticIdentity($item), $value)) . ']';
        }
        return $this->encodeJson($value);
    }

    // AMENDMENT.json implementation source, inserted into the generated class.
    private function nonblank(string $value): bool
    {
        return preg_match('~[^' . self::WHITE . ']~u', $value) === 1;
    }

    private function fullProjection(stdClass $record, string $complete): stdClass
    {
        $projected = clone $record;
        $projected->content_html = $complete;
        $projected->representation = (object) ['kind' => 'full'];
        foreach ($this->schema['generated_excerpt']['selection']['diagnostic_fields'] as $field) {
            unset($projected->$field);
        }
        return (object) [
            'mutation_id' => $this->schema['generated_excerpt']['selection']['measurement_mutation_id'],
            'bridge_record' => $projected,
        ];
    }

    public function generatedReadMore(string $url): string
    {
        $this->validate('Url', $url);
        $link = $this->schema['generated_excerpt']['generated_link'];
        $escaped = str_replace(array_keys($link['attribute_escape']), array_values($link['attribute_escape']), $url);
        return $link['prefix'] . $escaped . $link['suffix'];
    }

    public function measureToFullEnvelope(stdClass $record, string $completeSanitizedHtml): int
    {
        $this->utf8($completeSanitizedHtml, '$.content_html');
        if (!$this->nonblank($completeSanitizedHtml)) {
            $this->fail('$.content_html');
        }
        $fields = $this->type('ToRecord')['fields'];
        foreach ($fields as $field => $rule) {
            if (!property_exists($record, $field)) {
                if ($rule['required']) {
                    $this->fail('$.' . $field);
                }
            } elseif ($record->$field === null) {
                if (!($rule['nullable'] ?? false)) {
                    $this->fail('$.' . $field);
                }
            } elseif (!in_array($field, ['content_html', 'representation'], true)) {
                $this->shape($rule['type'], $record->$field, '$.' . $field);
            }
        }
        foreach (array_keys(get_object_vars($record)) as $field) {
            if (!array_key_exists($field, $fields)) {
                $this->fail('$', 'unknown_field');
            }
        }
        $full = clone $record;
        $full->content_html = $completeSanitizedHtml;
        $full->representation = (object) ['kind' => 'full'];
        $this->semantics('ToRecord', $full, '$');
        if (strlen($completeSanitizedHtml) !== $this->integer($record->source_content_bytes)
            || hash('sha256', $completeSanitizedHtml) !== $record->source_content_sha256) {
            $this->fail('$.source_content_sha256', 'content_hash_mismatch');
        }
        return strlen($this->semanticIdentity($this->fullProjection($record, $completeSanitizedHtml)));
    }

    private function generatedArticle(stdClass $record, string $path): string
    {
        $rep = $record->representation;
        $article = substr($record->content_html, 0, $this->integer($rep->article_content_bytes));
        if (preg_match('//u', $article) !== 1 || !$this->nonblank($article)
            || stripos($article, $this->schema['generated_excerpt']['generated_link']['reserved_marker']) !== false
            || hash('sha256', $article) !== $rep->article_content_sha256
            || substr($record->content_html, $this->integer($rep->article_content_bytes)) !== $this->generatedReadMore($rep->read_more_url)) {
            $this->fail($path . '.representation');
        }
        return $article;
    }

    private function checkGeneratedExcerpt(stdClass $record, string $path): void
    {
        $rep = $record->representation;
        if ($rep->read_more_url !== $record->canonical_url
            || !($record->source_content_bytes > 49152 || $rep->selection_full_request_bytes > 65536)) {
            $this->fail($path . '.representation');
        }
        $this->generatedArticle($record, $path);
    }

    public function generatedSourceIdentity(stdClass $record): string
    {
        $this->validate('ToRecord', $record);
        $normalized = clone $record;
        foreach ([...$this->schema['generated_excerpt']['selection']['diagnostic_fields'], 'expected_source_revision'] as $field) {
            unset($normalized->$field);
        }
        $normalized->visibility ??= 'unlisted';
        $normalized->lane ??= '';
        $normalized->source_authors ??= [];
        return hash('sha256', $this->semanticIdentity($normalized));
    }

    /** Stored context must come from the receiver, never an untrusted wire witness. */
    public function validateGeneratedSelection(stdClass $record, ?stdClass $storedCurrentRecord = null): stdClass
    {
        $this->validate('ToRecord', $record);
        if ($record->representation->kind !== 'generated_excerpt') {
            $this->fail('$.representation');
        }
        if ($storedCurrentRecord !== null) {
            $this->validate('ToRecord', $storedCurrentRecord);
            if ($record->source_revision === $storedCurrentRecord->source_revision) {
                if ($this->generatedSourceIdentity($record) !== $this->generatedSourceIdentity($storedCurrentRecord)) {
                    $this->fail('$', 'identity_conflict');
                }
                return $record;
            }
        }
        $rep = $record->representation;
        if ($rep->selection_source_revision !== $record->source_revision) {
            $this->fail('$.representation.selection_source_revision');
        }
        if ($record->source_content_bytes <= 49152) {
            $empty = strlen($this->semanticIdentity($this->fullProjection($record, '')));
            if ($rep->selection_full_request_bytes < $empty + $record->source_content_bytes
                || $rep->selection_full_request_bytes > $empty + 6 * $record->source_content_bytes) {
                $this->fail('$.representation.selection_full_request_bytes');
            }
        }
        return $record;
    }

    public function createGeneratedExcerpt(stdClass $record, string $completeSanitizedHtml, string $articleHtml): stdClass
    {
        $size = $this->measureToFullEnvelope($record, $completeSanitizedHtml);
        $this->utf8($articleHtml, '$.content_html');
        // All input metadata/provenance validated before this deep copy.
        $result = $this->parseJson($this->encodeJson($record), max(65536, strlen($this->encodeJson($record))));
        $result->content_html = $articleHtml . $this->generatedReadMore($record->canonical_url);
        $result->representation = (object) [
            'kind' => 'generated_excerpt',
            'read_more_url' => $record->canonical_url,
            'article_content_bytes' => strlen($articleHtml),
            'article_content_sha256' => hash('sha256', $articleHtml),
            'selection_source_revision' => $record->source_revision,
            'selection_full_request_bytes' => $size,
        ];
        $this->validate('ToRecord', $result);
        $this->validateGeneratedSelection($result);
        $this->validateGeneratedEnvelope($result);
        return $result;
    }

    public function validateGeneratedEnvelope(stdClass $record): stdClass
    {
        if (isset($record->content_html) && is_string($record->content_html) && strlen($record->content_html) > 49152) {
            $this->fail('$.content_html', 'body_too_large');
        }
        $this->validate('ToRecord', $record);
        $envelope = (object) [
            'mutation_id' => $this->schema['generated_excerpt']['selection']['measurement_mutation_id'],
            'bridge_record' => $record,
        ];
        if (strlen($this->semanticIdentity($envelope)) > 65536) {
            $this->fail('$', 'body_too_large');
        }
        return $record;
    }

    /** Value-local projection only. Caller owns actual authority/transaction. */
    public function projectGeneratedReadMoreMove(stdClass $record, string $newUrl, string $newRevision, ?string $updatedAt): stdClass
    {
        $this->validate('ToRecord', $record);
        if ($record->representation->kind !== 'generated_excerpt') {
            $this->fail('$.representation');
        }
        $this->validate('Url', $newUrl);
        $this->validate('Revision', $newRevision);
        if ($updatedAt !== null) {
            $this->validate('Instant', $updatedAt);
        }
        if ($newUrl === $record->canonical_url || $newRevision === $record->source_revision) {
            $this->fail('$.representation');
        }
        $article = $this->generatedArticle($record, '$');
        $projected = $this->parseJson($this->encodeJson($record));
        $projected->canonical_url = $newUrl;
        $projected->expected_source_revision = $record->source_revision;
        $projected->source_revision = $newRevision;
        $projected->source_updated_at = $updatedAt;
        $projected->content_html = $article . $this->generatedReadMore($newUrl);
        $projected->representation->read_more_url = $newUrl;
        return $this->validateGeneratedEnvelope($projected);
    }

    public function nativeOwnershipReceiptSha256(stdClass $receipt): string
    {
        $this->validate('NativeOwnershipReceipt', $receipt);
        $bytes = '';
        foreach ($this->type('NativeOwnershipReceipt')['fields'] as $field => $rule) {
            $value = $receipt->$field;
            if ($value === null) {
                $bytes .= pack('N', 0xffffffff);
            } else {
                $string = $this->type($rule['type'])['kind'] === 'integer' ? (string) $this->integer($value) : $value;
                $bytes .= pack('N', strlen($string)) . $string;
            }
        }
        return hash('sha256', $bytes);
    }
}

/** Strict raw ingress, including exact numeric lexemes before PHP conversion. */
final class StrictJson
{
    private int $at = 0;
    private bool $invalidNumberSeen = false;

    public function __construct(
        private readonly string $raw,
        private readonly int $maximumBytes,
        private readonly bool $rejectInvalidNumbers
    ) {}

    private function fail(string $code = 'invalid_json'): never
    {
        $status = $code === 'body_too_large' ? 413 : ($code === 'invalid_field' ? 422 : 400);
        throw new ValidationException($code, '$', $status);
    }

    private function whitespace(): void
    {
        while (isset($this->raw[$this->at]) && str_contains(" \t\n\r", $this->raw[$this->at])) {
            $this->at++;
        }
    }

    private function string(): string
    {
        $start = $this->at++;
        $length = strlen($this->raw);
        while ($this->at < $length) {
            if ($this->raw[$this->at] === '\\') {
                $this->at += 2;
                continue;
            }
            if ($this->raw[$this->at++] === '"') {
                try {
                    $value = json_decode(substr($this->raw, $start, $this->at - $start), false, 512, JSON_THROW_ON_ERROR);
                } catch (JsonException) {
                    $this->fail();
                }
                if (!is_string($value) || preg_match('//u', $value) !== 1) {
                    $this->fail();
                }
                return $value;
            }
        }
        $this->fail();
    }

    private function exactInteger(string $token): ?int
    {
        if (preg_match('~^(-?)(0|[1-9]\d*)(?:\.(\d+))?(?:[eE]([+-]?)(\d+))?$~D', $token, $parts) !== 1 || $parts[1] === '-') {
            return null;
        }
        $fraction = $parts[3] ?? '';
        $digits = $parts[2] . $fraction;
        $first = strspn($digits, '0');
        if ($first === strlen($digits)) {
            return 0;
        }
        $end = strlen(rtrim($digits, '0'));
        $significant = substr($digits, $first, $end - $first);
        $limit = strlen($token) + 17;
        $exponent = 0;
        // PHP 8.1 str_split('') returns ['']; scan only actual exponent digits.
        $exponentDigits = $parts[5] ?? '';
        for ($index = 0, $count = strlen($exponentDigits); $index < $count; ++$index) {
            $digit = $exponentDigits[$index];
            $exponent = min($limit, $exponent * 10 + ord($digit) - 48);
            if ($exponent === $limit) {
                break;
            }
        }
        if (($parts[4] ?? '') === '-') {
            $exponent = -$exponent;
        }
        $scale = $exponent - strlen($fraction) + strlen($digits) - $end;
        $length = strlen($significant) + $scale;
        if ($scale < 0 || $length > 16) {
            return null;
        }
        $canonical = $significant . str_repeat('0', $scale);
        if ($length === 16 && strcmp($canonical, '9007199254740991') > 0) {
            return null;
        }
        return (int) $canonical;
    }

    private function value(int $depth = 0): mixed
    {
        if ($depth > 512) {
            throw new UnsupportedBehaviorException('CMS JSON depth above 512 is unsupported');
        }
        $this->whitespace();
        $character = $this->raw[$this->at] ?? '';
        if ($character === '"') {
            return $this->string();
        }
        if ($character === '{') {
            $this->at++;
            $this->whitespace();
            $result = new stdClass();
            if (($this->raw[$this->at] ?? '') === '}') {
                $this->at++;
                return $result;
            }
            for (;;) {
                if (($this->raw[$this->at] ?? '') !== '"') {
                    $this->fail();
                }
                $key = $this->string();
                if (str_starts_with($key, "\0")) {
                    throw new UnsupportedBehaviorException('CMS JSON object keys starting with NUL are unsupported');
                }
                if (property_exists($result, $key)) {
                    $this->fail();
                }
                $this->whitespace();
                if (($this->raw[$this->at++] ?? '') !== ':') {
                    $this->fail();
                }
                $result->$key = $this->value($depth + 1);
                $this->whitespace();
                if (($this->raw[$this->at] ?? '') === '}') {
                    $this->at++;
                    return $result;
                }
                if (($this->raw[$this->at++] ?? '') !== ',') {
                    $this->fail();
                }
                $this->whitespace();
            }
        }
        if ($character === '[') {
            $this->at++;
            $this->whitespace();
            $result = [];
            if (($this->raw[$this->at] ?? '') === ']') {
                $this->at++;
                return $result;
            }
            for (;;) {
                $result[] = $this->value($depth + 1);
                $this->whitespace();
                if (($this->raw[$this->at] ?? '') === ']') {
                    $this->at++;
                    return $result;
                }
                if (($this->raw[$this->at++] ?? '') !== ',') {
                    $this->fail();
                }
            }
        }
        if (preg_match('~\G(?:true|false|null|-?(?:0|[1-9]\d*)(?:\.\d+)?(?:[eE][+-]?\d+)?)~A', $this->raw, $parts, 0, $this->at) !== 1) {
            $this->fail();
        }
        $token = $parts[0];
        $this->at += strlen($token);
        if ($token === 'true') {
            return true;
        }
        if ($token === 'false') {
            return false;
        }
        if ($token === 'null') {
            return null;
        }
        $integer = $this->exactInteger($token);
        if ($integer !== null) {
            return $integer;
        }
        $this->invalidNumberSeen = true;
        return new InvalidNumericValue();
    }

    public function parse(): stdClass
    {
        if (strlen($this->raw) > $this->maximumBytes) {
            $this->fail('body_too_large');
        }
        if (str_starts_with($this->raw, "\xef\xbb\xbf") || preg_match('//u', $this->raw) !== 1) {
            $this->fail();
        }
        $result = $this->value();
        $this->whitespace();
        if ($this->at !== strlen($this->raw) || !$result instanceof stdClass) {
            $this->fail();
        }
        if ($this->rejectInvalidNumbers && $this->invalidNumberSeen) {
            $this->fail('invalid_field');
        }
        return $result;
    }
}
