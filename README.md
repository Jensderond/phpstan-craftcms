# phpstan-craftcms

![Packagist Version](https://img.shields.io/packagist/v/jensderond/phpstan-craftcms)

Extension for PHPStan for better support for Craft CMS. The following features are provided:

- Configure autoload of Craft CMS for analysis
- Stubs for `Entry` and `ElementQuery`, narrowing `EntryQuery::one()`, `nth()` and `all()` to return `Entry`
- Dependency injection container support for `Craft::$container->get()`
- Recognises custom field handles as properties on `Element` and `ElementQuery`, including handle overrides from entry-type field layouts (read from your project config)
- Validates the `action` value of Twig `actionInput()` calls against discovered controller routes (including shorthand routes for controller default actions)
- Detects Twig N+1 query patterns and related performance smells (relational access in loops, queries built in loops, `|length` on queries, unbounded `.all()`), with eager-load (`.with([...])`) and Craft 5 `.eagerly()` suppression

## Install

Via Composer

``` bash
$ composer require --dev jensderond/phpstan-craftcms
```

## Usage

Add `phpstan-craftcms` to the project `phpstan.neon` / `phpstan.neon.dist`:
```neon
includes:
    - vendor/jensderond/phpstan-craftcms/extension.neon
```

## Configuration

The extension exposes the following parameters with sensible defaults:

```neon
parameters:
    yii2:
        config_path: %rootDir%/../../../config/app.php
    craftcms:
        projectConfigPath: %currentWorkingDirectory%/config/project
    craftActionInput:
        templatePaths:
            - %currentWorkingDirectory%/templates
            - %currentWorkingDirectory%/modules
            - %currentWorkingDirectory%/plugins
        handleMap: []
    craftTwigPerformance:
        enabled: true
        templatePaths: %craftActionInput.templatePaths%
        checks:
            nPlusOne: true
            nestedRelationAll: true
            queryInLoop: true
            lengthOnQuery: true
            unboundedAll: false
```

- `yii2.config_path` — path to your Yii/Craft application config used to build the service and route maps.
- `craftcms.projectConfigPath` — path to the Craft project config directory; used to collect custom field handles and entry-type handle overrides.
- `craftActionInput.templatePaths` — directories scanned for Twig `actionInput()` calls.
- `craftActionInput.handleMap` — optional map of additional handle aliases used when resolving `actionInput()` values to controllers.

### Twig performance checks

`craftTwigPerformance.checks` toggles each check; `unboundedAll` is off by default
(it is often intentional). Suppress a check project-wide via `ignoreErrors` using
its identifier:

- `craftcms.twigNPlusOne`
- `craftcms.twigNestedRelationAll`
- `craftcms.twigQueryInLoop`
- `craftcms.twigLengthOnQuery`
- `craftcms.twigUnboundedAll`

Limitations: analysis is per-template — it does not follow loop variables across
`{% include %}`, macros, or block boundaries. Loop-source eager-loading is detected
inline or one `{% set %}` back. `.with(...)` is honored only for literal string
arrays; dynamic arguments suppress the finding. `|length`-on-query detection is a
best-effort static heuristic. Eager-load suppression recognizes both
`.with([...])` and Craft 5's `.eagerly()`.

#### Result cache and template changes

The Twig checks scan the template tree directly, outside PHPStan's per-file
analysis. PHPStan's result cache is keyed on the analysed **PHP** files (and the
config), so it is **not** aware of your `.twig` files. When you change only a
template, a warm result cache can serve stale Twig findings — or none at all —
because PHPStan may short-circuit before the checks re-run.

To get reliable Twig results:

- **In CI**, run against a cold cache (CI containers start without one), or add
  `phpstan clear-result-cache` before `phpstan analyse`.
- **Locally**, run `vendor/bin/phpstan clear-result-cache` after editing
  templates (or pass a fresh `--memory-limit`/config that invalidates the cache)
  before trusting the Twig findings.

This does not affect the PHP-level features above, which participate in the
result cache normally.

## Credits

- [studio-stomp/phpstan-craftcms](https://github.com/studio-stomp/phpstan-craftcms)
- [marcusgaius/phpstan](https://github.com/marcusgaius/phpstan)
- [erickskrauch/phpstan-yii2](https://github.com/erickskrauch/phpstan-yii2)
