<?php

declare(strict_types=1);

// Actual hash-checked WordPress 6.6 parser and production observer. Prepared
// strings are explicit boundary fixtures, not an installed KSES/publication run.
require __DIR__ . '/native-preparation-api.php';
require __DIR__ . '/../src/NativeContent.php';

use DiscussionBridge\WordPress\NativeContent;

$tests = [];
$test = static function (string $name, callable $body) use (&$tests): void { $tests[] = [$name, $body]; };
$expect = static function (bool $condition): void { if (!$condition) throw new RuntimeException('Assertion failed.'); };
$program = 'PROGRAM_BODY_SENTINEL(); return "answer";';
$encoded = htmlspecialchars($program, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

foreach (['onclick', 'onfocus', 'onblur', 'onanimationend', 'onanimationstart', 'ontransitionend', 'onwheel',
    'onpointerenter', 'onpointerleave', 'onpointercancel', 'ongotpointercapture', 'onlostpointercapture',
    'onbeforeinput', 'oncompositionend', 'ondragstart', 'ondrop', 'oncontextmenu', 'onselect', 'onbeforetoggle'] as $attribute) {
    $original = '<button ' . $attribute . '="' . $encoded . '">Answer</button>';
    $test('detects removed ' . $attribute, static function () use ($original, $attribute, $expect, $program): void {
        $loss = NativeContent::function_loss($original, '<button>Answer</button>');
        $expect(is_array($loss) && $loss['kind'] === 'event_handler' && $loss['tag'] === 'BUTTON' &&
            $loss['attribute'] === $attribute && $loss['function_sha256'] === hash('sha256', $program) &&
            $loss['count'] === 1 && $loss['prepared_count'] === 0);
        $expect(!str_contains(json_encode($loss, JSON_THROW_ON_ERROR), 'PROGRAM_BODY_SENTINEL'));
    });
    $test('detects changed ' . $attribute, static function () use ($original, $attribute, $expect): void {
        $prepared = '<button ' . $attribute . '="differentFunction()">Answer</button>';
        $loss = NativeContent::function_loss($original, $prepared);
        $expect(is_array($loss) && $loss['kind'] === 'event_handler' && $loss['attribute'] === $attribute);
    });
    $test('accepts faithfully retained ' . $attribute, static function () use ($original, $expect): void {
        $expect(NativeContent::function_loss($original, $original) === null);
    });
}

$test('uses native case-insensitive names and decoded attribute values', static function () use ($expect): void {
    $loss = NativeContent::function_loss('<input OnFoCuS="&#97;nswer()">', '<input>');
    $expect(is_array($loss) && $loss['attribute'] === 'onfocus' && $loss['function_sha256'] === hash('sha256', 'answer()'));
    $expect(NativeContent::function_loss('<input OnFoCuS="&#97;nswer()">', '<input onfocus="answer()">') === null);
});

$test('does not satisfy one lost event with another retained event', static function () use ($expect): void {
    $original = '<button onclick="answer()" onfocus="answer()">Answer</button>';
    $loss = NativeContent::function_loss($original, '<button onclick="answer()">Answer</button>');
    $expect(is_array($loss) && $loss['attribute'] === 'onfocus');
});

$test('observes duplicate handler multiplicity and tolerates retained additional native handlers', static function () use ($expect): void {
    $one = '<button onfocus="answer()">Answer</button>';
    $loss = NativeContent::function_loss($one . $one, $one);
    $expect(is_array($loss) && $loss['count'] === 2 && $loss['prepared_count'] === 1);
    $expect(NativeContent::function_loss($one, $one . $one) === null);
});

foreach (['onfocus', 'onfocus=""', 'onfocus=" &#9; "'] as $empty) {
    $test('does not invent a nonempty handler from ' . $empty, static function () use ($empty, $expect): void {
        $expect(NativeContent::function_loss('<input ' . $empty . '>', '<input>') === null);
    });
}

foreach ([
    '<pre><code>&lt;input onfocus="answer()"&gt;</code></pre>',
    '<!-- <input onfocus="answer()"> -->',
    '<textarea><input onfocus="answer()"></textarea>',
    '<p title="onfocus=answer()" data-example="onwheel=answer()">Article</p>',
    '<script type="application/json">{"onfocus":"answer()","example":"<input onwheel=answer()>"}</script>',
    '<script type="application/ld+json">{"onanimationend":"data"}</script>',
] as $index => $content) {
    $test('does not classify displayed/comment/inert handler text ' . $index, static function () use ($content, $expect): void {
        $expect(NativeContent::function_loss($content, '') === null);
    });
}

$test('retains separate active-script and inert-data loss observations', static function () use ($expect): void {
    $script = NativeContent::function_loss('<script>PROGRAM_BODY_SENTINEL()</script>', '');
    $data = NativeContent::data_loss('<script type="application/json">{"value":"DATA_BODY_SENTINEL"}</script>', '');
    $expect(is_array($script) && $script['kind'] === 'script_function');
    $expect(is_array($data) && $data['kind'] === 'script_data');
    $expect(!str_contains(json_encode([$script, $data], JSON_THROW_ON_ERROR), 'BODY_SENTINEL'));
});

$test('keeps dynamically observed names bounded without merging their identities', static function () use ($expect): void {
    $tag = 'x-' . str_repeat('component', 20);
    $first = 'on' . str_repeat('event', 20) . 'a';
    $second = 'on' . str_repeat('event', 20) . 'b';
    $original = '<' . $tag . ' ' . $first . '="answer()"></' . $tag . '>';
    $prepared = '<' . $tag . ' ' . $second . '="answer()"></' . $tag . '>';
    $loss = NativeContent::function_loss($original, $prepared);
    $expect(is_array($loss) && $loss['kind'] === 'event_handler' && strlen($loss['tag']) <= 64 && strlen($loss['attribute']) <= 64);
});

$passed = 0;
$failed = 0;
foreach ($tests as [$name, $body]) {
    try { $body(); $passed++; fwrite(STDOUT, 'ok - ' . $name . "\n"); }
    catch (Throwable $error) { $failed++; fwrite(STDERR, 'not ok - ' . $name . ': ' . $error::class . ': ' . $error->getMessage() . "\n"); }
}
fwrite(STDOUT, json_encode(['tests' => count($tests), 'passed' => $passed, 'failed' => $failed,
    'parser' => 'hash-checked WordPress 6.6 HTML Tag Processor',
    'limits' => 'Production loss observer with explicit before/after fixtures; installed KSES, publication, browser behavior and persistence unqualified'], JSON_THROW_ON_ERROR) . "\n");
exit($failed === 0 ? 0 : 1);
