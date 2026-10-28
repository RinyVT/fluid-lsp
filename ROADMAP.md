# Roadmap

Features and fixes not yet built. Largely based on the community proposal
([talk.typo3.org](https://talk.typo3.org/t/typo3-specific-lsp-implementations-fluid-q2-2025/6160)).

## Features

### `f:render`, `f:layout`, `f:section`
- Complete `partial="…"` with the partials under the configured partial root paths.
- Complete `section="…"` with the sections in the target partial or the current template.
- Complete `<f:layout name="…">` with the available layouts.
- Go to definition on all three.
- Open question: root paths come from TypoScript or the controller, not from the template. Start with the convention
  `Resources/Private/{Partials,Layouts}` of the extension that owns the file.

### `f:translate` and `LLL:`
- Complete `key="…"` from the extension's `locallang.xlf`, taking `extensionName` into account.
- Show the translated text on hover.
- Go to definition: jump to the `trans-unit` in the XLF file.
- Do the same for full `LLL:EXT:…:key` strings.

### `EXT:` paths
Complete and follow `EXT:ext_key/Resources/…` paths, for example in `f:uri.resource`, `f:image` and `f:asset.*`.

### Fluid components (v14)
`namespacesFor()` currently drops `ViewHelperResolverDelegateInterface` entries. Delegates that implement
`ComponentListProviderInterface` and `ComponentDefinitionProviderInterface` provide component names and arguments.
`fluid:schema:generate` in TYPO3 14 shows how.

### Template variables
Complete `{variable.property}`. The proposal leaves this out: the variables come from the controller, and the template
doesn't declare them. Possible sources are `f:for as="…"`, `f:variable name="…"` and `f:argument` (Fluid 4+).

### Other ViewHelper-specific completions
The proposal also lists:
- cObject paths for `<f:cObject typoscriptObjectPath="…">`
- extension names for `extensionName`

## Limitations

- **PHP version:** the server runs on the host's PHP, and only deprecations are muted. Use the project's version
  instead: read `php_version` from `.ddev/config.yaml` and pick `/opt/homebrew/opt/php@X.Y/bin/php`. This can be done
  in the nvim `cmd`.
- **Stale ViewHelper list:** `ViewHelperRegistry` caches the list for the server's lifetime, so a newly added
  ViewHelper needs `:LspRestart`. Fix: rescan on `workspace/didChangeWatchedFiles` for `*ViewHelper.php`.
- **Extension repos:** repos with `.Build/vendor` aren't detected. The root detection looks for `vendor/typo3/cms-core`
  only, and such repos usually have no `settings.php`.
- **Classic (non-composer) installs:** untested.
- **Diagnostics:**
  - Fluid's parser stops at the first error and only gives a reliable line, so the whole line is marked.
  - Partials that use a namespace declared only in the calling template get "Unknown Namespace" (e.g. `be:` in v12).
  - The ViewHelper checks skip prefixes with resolver delegates (components).
- **Cursor scanner (`CursorContext`):**
  - It looks back 4000 bytes at most.
  - Inline arguments break on nested parentheses.
  - `<` and `{` in `<script>` and `<style>` can trigger name completion.
- **UTF-16 clients:** positions count code points, not UTF-16 units, so they drift by one per emoji. Neovim
  negotiates UTF-8 and isn't affected.
- **Document sync:** full text on every change. Incremental sync only matters for very large templates.

## Housekeeping

- Add Rector (MaxServ standard sets), skipping `DisallowedEmptyRuleFixerRector`.
- Unit tests for `LanguageServer` request handling. The smoke test covers it end to end now.
