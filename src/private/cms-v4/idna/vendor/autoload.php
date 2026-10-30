<?php
declare(strict_types=1);

namespace DiscussionBridge\CmsV4\IdnaVendor;

// Generated private authoritative class map. Do not register Composer's global
// engine/vendor metadata: the host must not discover these private packages as
// installations providing their original, unprefixed classes.
$runtimeIdentity = 'ccd4e7c048763f284be65e9def040a0194e3439f38088d261ca232edca7edce8';
$loadedIdentity = __NAMESPACE__ . '\\PRIVATE_RUNTIME_ID';
if (defined($loadedIdentity)) {
    if (constant($loadedIdentity) !== $runtimeIdentity) {
        throw new \RuntimeException('Different private IDNA runtime already loaded');
    }
    return;
}
$classMap = require __DIR__ . '/composer/autoload_classmap.php';
spl_autoload_register(static function (string $class) use ($classMap): void {
    if (str_starts_with($class, __NAMESPACE__ . '\\') && isset($classMap[$class])) {
        require_once $classMap[$class];
    }
}, true, true);
require_once dirname(__DIR__) . '/bridge.php';
define($loadedIdentity, $runtimeIdentity);

