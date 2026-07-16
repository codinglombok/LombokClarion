<?php

declare(strict_types=1);

use LombokClarion\Config\ConfigCompiler;
use LombokClarion\Config\Exceptions\ConfigException;

function test_config_schema(): array
{
    return [
        'mail' => [
            'smtp' => [
                'host' => ['type' => 'string', 'env' => 'TEST_MAIL_HOST', 'default' => 'localhost'],
                'port' => ['type' => 'int', 'env' => 'TEST_MAIL_PORT', 'default' => 587],
            ],
        ],
        'app' => [
            'debug' => ['type' => 'bool', 'env' => 'TEST_APP_DEBUG', 'default' => false],
        ],
    ];
}

test('compiled config exposes typed nested property access', function () {
    $compiler = new ConfigCompiler();
    $source = $compiler->compile(test_config_schema(), 'Test_AppConfig1', env: [
        'TEST_MAIL_HOST' => 'smtp.example.com',
        'TEST_MAIL_PORT' => '2525',
        'TEST_APP_DEBUG' => 'true',
    ]);

    $tmpFile = tempnam(sys_get_temp_dir(), 'config_') . '.php';
    file_put_contents($tmpFile, $source);

    $config = require $tmpFile;

    assertSame('smtp.example.com', $config->mail->smtp->host);
    assertSame(2525, $config->mail->smtp->port);
    assertTrue($config->app->debug === true);

    unlink($tmpFile);
});

test('falls back to schema default when env var is absent', function () {
    $compiler = new ConfigCompiler();
    $source = $compiler->compile(test_config_schema(), 'Test_AppConfig2', env: []);
    $tmpFile = tempnam(sys_get_temp_dir(), 'config_') . '.php';
    file_put_contents($tmpFile, $source);

    $config = require $tmpFile;
    assertSame('localhost', $config->mail->smtp->host);
    assertSame(587, $config->mail->smtp->port);
    assertSame(false, $config->app->debug);

    unlink($tmpFile);
});

test('missing env var and no default throws ConfigException', function () {
    $compiler = new ConfigCompiler();
    $schema = ['x' => ['type' => 'string', 'env' => 'TEST_MISSING_XYZ']];
    assertThrows(ConfigException::class, fn () => $compiler->compile($schema, 'Test_AppConfig3', env: []));
});

test('invalid type in schema throws ConfigException', function () {
    $compiler = new ConfigCompiler();
    $schema = ['x' => ['type' => 'not-a-type', 'default' => 'y']];
    assertThrows(ConfigException::class, fn () => $compiler->compile($schema, 'Test_AppConfig4'));
});
