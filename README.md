# phpstan-craftcms

![Packagist Version](https://img.shields.io/packagist/v/jensderond/phpstan-craftcms)

Extension for PHPStan for better support for Craft CMS. The following features are provided:

- Configure autoload of Craft CMS for analysis
- Stub to support different `ElementQuery`-classes
- Dependency injection container with `Craft::$container->get()`

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

## Credits

- [studio-stomp/phpstan-craftcms][[link-author](https://github.com/studio-stomp/phpstan-craftcms)]
- [marcusgaius/phpstan](https://github.com/marcusgaius/phpstan)
- [erickskrauch/phpstan-yii2](https://github.com/erickskrauch/phpstan-yii2)
