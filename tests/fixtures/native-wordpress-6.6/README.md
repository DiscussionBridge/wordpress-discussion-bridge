# Native preparation API fixtures

These unmodified files come from the official WordPress/WordPress `6.6` tag, the native platform minimum already declared by this component. They execute the actual HTML Tag Processor, HTML entity decoder and bundled Requests IRI resolver in the isolated preparation tests. `SOURCE_MANIFEST.json` records exact source URLs, bytes and SHA-256. The loader checks the recorded identities before loading code.

The native WordPress source retains its GPL notices and `license.txt`. The Requests source retains its own notices; `REQUESTS-LICENSE.txt` is the upstream Requests v2.0.11 ISC/ComplexPie license text. These licenses apply to their respective test fixture files; the component's own license is unchanged.

Production uses the installed WordPress APIs, never these fixtures. No WordPress bootstrap, database, network transport, provider execution or native installation is supplied by this directory. The existing test harness still models KSES, native escaping, posts, hooks, storage and HTTP; successful fixture execution does not qualify those installed behaviors or define a new URI/provider/content policy.

Primary API/grammar references: [WordPress HTML Tag Processor](https://developer.wordpress.org/reference/classes/wp_html_tag_processor/), [native URI attributes](https://developer.wordpress.org/reference/functions/wp_kses_uri_attributes/), [bundled IRI resolution](https://developer.wordpress.org/reference/classes/wporg-requests-iri/absolutize/), and [HTML srcset URL token boundaries](https://html.spec.whatwg.org/multipage/images.html#parse-a-srcset-attribute). The srcset preparation retains descriptors and changes URL tokens only; it does not grant media or scheme permission.
