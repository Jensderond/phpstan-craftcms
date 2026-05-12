# phpstan-craftcms

![Packagist Version](https://img.shields.io/packagist/v/jensderond/phpstan-craftcms)

Extension for PHPStan for better support for Craft CMS. The following features are provided:

- Configure autoload of Craft CMS for analysis
- Stubs for `Entry` and `ElementQuery`, narrowing `EntryQuery::one()`, `nth()` and `all()` to return `Entry`
- Dependency injection container support for `Craft::$container->get()`
- Recognises custom field handles as properties on `Element` and `ElementQuery`, including handle overrides from entry-type field layouts (read from your project config)
- Validates the `action` value of Twig `actionInput()` calls against discovered controller routes (including shorthand routes for controller default actions)

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
```

- `yii2.config_path` — path to your Yii/Craft application config used to build the service and route maps.
- `craftcms.projectConfigPath` — path to the Craft project config directory; used to collect custom field handles and entry-type handle overrides.
- `craftActionInput.templatePaths` — directories scanned for Twig `actionInput()` calls.
- `craftActionInput.handleMap` — optional map of additional handle aliases used when resolving `actionInput()` values to controllers.

## Credits

- [studio-stomp/phpstan-craftcms](https://github.com/studio-stomp/phpstan-craftcms)
- [marcusgaius/phpstan](https://github.com/marcusgaius/phpstan)
- [erickskrauch/phpstan-yii2](https://github.com/erickskrauch/phpstan-yii2)
