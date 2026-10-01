# Contributing

Thanks for helping improve LS AI Provider for ElevenLabs. Bug reports, fixes and new
features are all welcome. For a larger change, open an issue first so we can agree on
the approach before you spend time on it.

Setting up a development environment, running the tests and starting a local WordPress
site are covered in the README's [Development](README.md#development) section.

## Version markers

Never guess the version a change will ship in. Use the `n.e.x.t` placeholder, and the
real version number is filled in when the release is cut.

- New classes, methods, constants, filters and options:

  ```php
  /**
   * Resolves the default model.
   *
   * @since n.e.x.t
   */
  ```

- A changed existing API keeps its original `@since` and gains a line saying what
  changed:

  ```php
  * @since 0.1.0
  * @since n.e.x.t Narrows the text-to-speech models to the allowed models.
  ```

- Filters get a `@since` in the docblock above the `apply_filters()` call.

- Changelog entries go under a `= n.e.x.t =` heading at the top of the
  `== Changelog ==` section in `readme.txt`. Add the heading if it isn't there yet.

Leave the plugin header `Version:` and the readme `Stable tag:` alone. They change
only as part of a release.

## Changelog

Every change a site owner or developer could notice gets a `readme.txt` changelog
entry: new features, behaviour changes, new filters or options, and bug fixes. Write it
for the person reading it, not the person who wrote the code. Say what changed and why
it matters, and name any filter, option or constant involved:

```
* Use Eleven v4 by default when a prompt names no model. The provider previously listed models alphabetically, which made the English-only Flash v2 the default
```

Internal refactors, test-only changes and CI tweaks don't need an entry.

## Compatibility

The plugin runs in two contexts, and every change has to work in both.

- **As a WordPress plugin.** WordPress 7.0 and later bundle the PHP AI Client SDK. The
  plugin registers itself as a provider on `init`.
- **As a Composer package outside WordPress** (`saarnilauri/ai-provider-for-elevenlabs`),
  used directly with the PHP AI Client. See [As a Standalone Package](README.md#as-a-standalone-package).
  Here no WordPress function exists at all.

So:

- Guard every WordPress function call with `function_exists()`, and give the code a
  sensible behaviour without it. For example, an option lookup is skipped and an
  environment variable or PHP constant is read instead:

  ```php
  if (function_exists('apply_filters')) {
      $modelId = apply_filters('ai_provider_for_elevenlabs_default_model_id', $modelId);
  }
  ```

- Code under `src/` must not depend on WordPress being loaded. WordPress-only wiring
  belongs in the plugin bootstrap, `ls-ai-provider-for-elevenlabs.php`.
- Settings that can be configured in WordPress should also be configurable without it,
  the way `ELEVENLABS_DEFAULT_VOICE_ID` works as an environment variable, a constant and
  an option.
- The minimum PHP version is **7.4**. `composer lint` runs PHPCompatibility against it,
  so features such as enums, `readonly`, `match` and named arguments fail the lint.
  `array_is_list()`, `str_contains()`, `str_starts_with()` and `str_ends_with()` are
  fine: the PHP AI Client SDK polyfills them, and it is always loaded alongside the
  provider, with or without WordPress.

## Public API stability

These names are a public API. Existing sites and integrations depend on them, so they
don't change:

- filter, option and transient names, all prefixed `ai_provider_for_elevenlabs_`. They
  kept that prefix when the plugin was renamed to "LS AI Provider for ElevenLabs";
- environment variables and constants such as `ELEVENLABS_API_KEY` and
  `ELEVENLABS_DEFAULT_VOICE_ID`;
- the `AiProviderForElevenLabs\` namespace and public method signatures;
- model IDs the provider registers, such as `elevenlabs-sound-generation`.

Adding to them is fine. Renaming or removing one is a breaking change: discuss it in an
issue first.

## Before opening a pull request

```bash
composer test   # unit tests
composer lint   # PHPCS (PSR-12 + PHPCompatibility) and PHPStan at level max
```

Both must pass, and CI runs them on every pull request.

- **Add or update unit tests** for the behaviour you changed. Unit tests run without
  WordPress. A test that needs WordPress functions defines minimal stand-ins and runs
  in a separate process (`@runTestsInSeparateProcesses`), so those global functions
  can't leak into other tests.
- **Integration tests** call the live ElevenLabs API with the key in `.env`.
  **Text-to-speech and sound generation tests make billed requests** and use your
  account's credits. Tests that only list voices or models are free. Run just the ones
  you need:

  ```bash
  composer test:integration -- --filter VoiceDirectoryIntegrationTest
  ```

- **Code style** follows the existing code: PSR-12, camelCase variables, typed
  parameters and return values, and a docblock with `@since` on every class, method and
  filter.

## WordPress.org requirements

The plugin is listed in the WordPress.org directory, so a few of its rules apply to
every change:

- **Document every external request.** A new ElevenLabs endpoint, or new data sent to
  an existing one, goes into the "External services" section of `readme.txt`: what is
  sent, and when.
- **Nothing is sent unless site code asks for it.** No telemetry, analytics or
  phone-home requests.
- **Don't read the core Connectors option** (`connectors_ai_elevenlabs_api_key`)
  directly. WordPress passes that key to the AI Client, and the plugin re-wraps it for
  the `xi-api-key` header. Reading it directly was a WordPress.org review finding.
- **Escape output and sanitize input** in any code that renders HTML or accepts
  requests.

## Commits and pull requests

- Write commit subjects in the imperative ("Add …", "Fix …"), with a body explaining why
  the change is needed when that isn't obvious from the subject.
- Keep each pull request to one change. Unrelated fixes go in their own PR.
- In the description, say what changed, why, and how you tested it, including which
  integration tests you ran, if any.

## Credit

Contributions are credited where they land. A substantial contribution names its author
in the docblock of the code it added and in its changelog entry, for example
"(contributed by Jake Spurlock)". Larger contributions are also listed in the README's
Credits section. If you'd like to be credited under a different name or link, say so in
your pull request.

## Security issues

Please don't report security vulnerabilities in public issues or pull requests. See
[SECURITY.md](SECURITY.md) for how to report them privately.
