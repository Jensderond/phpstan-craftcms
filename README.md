# phpstan-craftcms

[![Latest Version on Packagist][ico-version]][link-packagist]

Extension for PHPStan for better support for Craft CMS. The following features are provided:

- Configure autoload of Craft CMS for analysis
- Stub to support different `ElementQuery`-classes

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

- [Studio Stomp][link-author]
- [marcusgaius/phpstan](https://github.com/marcusgaius/phpstan)
- [erickskrauch/phpstan-yii2](https://github.com/erickskrauch/phpstan-yii2)
