<?php
declare(strict_types=1);

// Actual verifier and parser; reduced WordPress host/row/HTTP model, no live WP/DB/network.
require __DIR__.'/integration-bootstrap.php';

use DiscussionBridge\WordPress\CmsArtifacts;
use DiscussionBridge\WordPress\CmsPresentation;
use DiscussionBridge\WordPress\CmsSourceArticleProfile;
use DiscussionBridge\WordPress\CmsSourceNativeMove;
use DiscussionBridge\WordPress\CmsSourceOwnership;
use DiscussionBridge\WordPress\CmsSourcePublicVerifier;
use DiscussionBridge\WordPress\CmsSourcePublisher;
use DiscussionBridge\WordPress\Settings;

$tests = $assertions = $failures = 0;
function check(bool $condition, string $message): void {
    $GLOBALS['assertions']++;
    if (!$condition) throw new RuntimeException($message);
}
function run_case(string $name, callable $body): void {
    $GLOBALS['tests']++;
    try { $body(); echo "PASS $name\n"; }
    catch (Throwable $error) { $GLOBALS['failures']++; echo "FAIL $name: ".$error->getMessage()."\n"; }
}
function model(): array {
    dbt_reset();
    update_option(Settings::CONNECTION_SECRET_OPTION, Settings::sanitize_connection_secret(str_repeat('a', 43)));
    $GLOBALS['dbt']['posts'][1] = new WP_Post(1, 'publish', 'post', 'Native é title', '<p>Entire é article.</p>', 9, 'moved-source');
    $GLOBALS['dbt']['meta'][1] = [CmsPresentation::META => ['fixture' => 'persisted-revision'], '_discussionbridge_enabled' => '1'];
    $template = (object) ['sha256' => str_repeat('b', 64), 'relative_path' => 'single.php'];
    $article = '<article data-discussionbridge-source-article="'.CmsSourceOwnership::PROFILE.'"><h1 data-discussionbridge-source-title>Native é title</h1><span data-discussionbridge-source-author-id="native-9">Author</span><div data-discussionbridge-source-body>'.CmsSourceArticleProfile::BEGIN.'<p>Entire é article.</p><template>Legitimate source example</template><span hidden>Source-owned detail</span>'.CmsSourceArticleProfile::END.'</div></article>';
    $parts = CmsSourceArticleProfile::parts($article, 'https://wordpress.example/original/');
    check(is_array($parts), 'ordinary source profile unavailable');
    $sha = hash('sha256', $parts['tree']->body);
    $state = (object) [
        'artifacts' => CmsArtifacts::binding(), 'scope' => CmsSourcePublisher::scope(1),
        'receipt' => (object) ['resource_id' => '11111111-1111-4111-8111-000000000002'],
        'current_record' => (object) ['external_id' => 'post:'.Settings::site_id().':1', 'source_content_sha256' => $sha],
        'current_native_source' => (object) ['article_url' => 'https://wordpress.example/original/'],
        'source_move' => (object) [
            'native_operation' => 'applied', 'after_row' => CmsSourceNativeMove::row(1),
            'request' => (object) ['body' => (object) ['new_url' => get_permalink(1)]],
            'new_source_revision' => 'native-source-moved', 'native_receipt' => (object) ['actual_fixture' => true],
            'expected_native' => (object) ['template' => $template, 'tree' => $parts['tree'], 'sha256' => CmsSourceOwnership::digest($parts['tree']), 'observed_at' => '2020-01-01T00:00:00.000Z'],
            'receipt' => (object) ['move_id' => '11111111-1111-4111-8111-000000000003', 'proof_nonce' => 'issued-fixture-nonce', 'prepared_at' => '2020-01-01T00:00:00.000Z'],
        ],
    ];
    $attributes = ['data-site-id' => Settings::site_id(), 'data-native-id' => '1', 'data-resource-id' => $state->receipt->resource_id,
        'data-external-id' => $state->current_record->external_id, 'data-source-revision' => $state->source_move->new_source_revision,
        'data-source-content-sha256' => $sha, 'data-move-id' => $state->source_move->receipt->move_id,
        'data-proof-nonce' => $state->source_move->receipt->proof_nonce, 'data-template-sha256' => CmsSourceOwnership::digest($template)];
    $tags = new WP_HTML_Tag_Processor($article); $tags->next_tag('ARTICLE');
    foreach ($attributes as $key => $value) $tags->set_attribute($key, $value);
    return [$state, $tags->get_updated_html()];
}
function verify_page(stdClass $state, string $page, string $media = 'text/html; charset=UTF-8'): stdClass|WP_Error {
    $before = serialize($state);
    $GLOBALS['dbt']['responses'][get_permalink(1)] = dbt_response(200, $page, $media);
    $result = CmsSourcePublicVerifier::verify(1, $state, fn() => true);
    check(serialize($state) === $before, 'verifier changed move/accepted evidence');
    $request = end($GLOBALS['dbt']['requests'])[1];
    check($request['redirection'] === 0 && $request['cookies'] === [] && !isset($request['headers']['Authorization'], $request['headers']['X-DiscussionBridge-Secret']), 'anonymous proof request changed');
    return $result;
}

$wrappers = [
    'template' => ['<template>', '</template>'], 'hidden' => ['<div hidden>', '</div>'],
    'hidden-until-found' => ['<div hidden="until-found">', '</div>'], 'inert' => ['<div inert>', '</div>'],
    'display-none' => ['<div style="color:red; DISPLAY : none !important">', '</div>'],
    'visibility-hidden' => ['<div style="visibility:hidden">', '</div>'],
    'content-visibility-hidden' => ['<div style="content-visibility: hidden">', '</div>'],
    'script-data' => ['<script type="application/json">', '</script>'], 'textarea' => ['<textarea>', '</textarea>'],
    'noscript' => ['<noscript>', '</noscript>'],
];
foreach ($wrappers as $name => [$before, $after]) run_case("reject $name ancestor", function () use ($before, $after): void {
    [$state, $article] = model();
    check(is_wp_error(verify_page($state, '<html><body>'.$before.$article.$after.'</body></html>')), 'inactive article qualified');
});
foreach (['article' => 'data-discussionbridge-source-article', 'title' => 'data-discussionbridge-source-title', 'body' => 'data-discussionbridge-source-body', 'author' => 'data-discussionbridge-source-author-id'] as $role => $attribute) run_case("reject hidden owned $role", function () use ($attribute): void {
    [$state, $article] = model();
    $article = str_replace($attribute, 'hidden '.$attribute, $article);
    check(is_wp_error(verify_page($state, '<html><body>'.$article.'</body></html>')), 'hidden owned boundary qualified');
});
foreach (['display:none;display:block', 'display:none!important;display:block!important', 'display:block!important;display:none',
    'visibility:hidden;visibility:visible', 'content-visibility:hidden;content-visibility:visible',
    "--sample:'display:none;';display:block", '/* display:none */ display:block', 'di/**/splay:none'] as $style) run_case("accept inline $style", function () use ($style): void {
    [$state, $article] = model();
    check(verify_page($state, '<html><body><main style="'.htmlspecialchars($style, ENT_QUOTES, 'UTF-8').'">'.$article.'</main></body></html>') instanceof stdClass, 'active inline context rejected');
});
foreach (['display:none!important;display:block', 'display:none;display:unknown-value'] as $style) run_case("reject inline $style", function () use ($style): void {
    [$state, $article] = model();
    check(is_wp_error(verify_page($state, '<html><body><main style="'.$style.'">'.$article.'</main></body></html>')), 'hidden/uncertain inline override qualified');
});
foreach (['text/html', ' TEXT/HTML ; Charset="UTF-8" ', 'text/html; charset=utf-8', 'text/html; profile="native;article"; charset=UTF-8', 'text/html; profile=native'] as $media) run_case("accept media $media", function () use ($media): void {
    [$state, $article] = model();
    $proof = verify_page($state, '<html><body><main style="display:block">'.$article.'</main></body></html>', $media);
    check($proof instanceof stdClass, 'ordinary native proof rejected');
    check($proof->source_content_sha256 === $state->current_record->source_content_sha256 && $proof->native_ownership_receipt_sha256 === CmsSourceOwnership::digest($state->source_move->native_receipt), 'identity proof changed');
});
foreach (['text/plain', 'text/htmlx', 'application/xhtml+xml', 'text/html; charset=ISO-8859-1', 'text/html; charset=utf8',
    'text/html; charset=UTF-8; charset=UTF-8', 'text/html; charset=UTF-8; charset=ISO-8859-1', 'text/html; charset=',
    'text/html; charset', 'text/html; charset="UTF-8', 'text/html, text/html', 'text/html; charset=UTF-8, text/html',
    'text/html; profile="unterminated', "text/html; charset=UTF-8\r\n", 'text/html; charset="UTF-8,UTF-8"'] as $media) run_case("reject media ".json_encode($media), function () use ($media): void {
    [$state, $article] = model();
    check(is_wp_error(verify_page($state, '<html><body>'.$article.'</body></html>', $media)), 'ambiguous/conflicting media qualified');
});
foreach (['body', 'nonce', 'duplicate'] as $fault) run_case("retain existing $fault rejection", function () use ($fault): void {
    [$state, $article] = model();
    $page = match ($fault) { 'body' => str_replace('Entire é article.', 'Changed article.', $article), 'nonce' => str_replace('issued-fixture-nonce', 'wrong-nonce', $article), default => $article.$article };
    check(is_wp_error(verify_page($state, '<html><body>'.$page.'</body></html>')), 'existing exact proof guard weakened');
});
echo "RESULT tests=$tests assertions=$assertions failures=$failures runtime=".PHP_VERSION."\n";
exit($failures === 0 ? 0 : 1);
