<?php

// Loads Craft's classes without booting a Craft site: the adapter and its helpers only need
// Yii's base classes, App::parseEnv() and the validators.
require dirname(__DIR__).'/vendor/autoload.php';
require_once dirname(__DIR__).'/vendor/yiisoft/yii2/Yii.php';
require_once dirname(__DIR__).'/vendor/craftcms/cms/src/Craft.php';

// Validation runs Craft's env-var parser, which asks the app's security service which values are
// sensitive. Craft's own service needs a full Craft config, so the tests use this stand-in.
final class GroundmailTestSecurity extends yii\base\Security
{
    public function isSensitive(string $key): bool
    {
        return true;
    }
}

new yii\console\Application([
    'id' => 'groundmail-craft-tests',
    'basePath' => __DIR__,
    'components' => [
        'security' => ['class' => GroundmailTestSecurity::class],
        // Craft registers a plugin's message category itself; outside Craft the tests do.
        'i18n' => ['translations' => ['groundmail' => ['class' => yii\i18n\PhpMessageSource::class, 'basePath' => __DIR__]]],
    ],
]);
