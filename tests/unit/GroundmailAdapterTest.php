<?php

namespace groundmail\craft\tests;

use Groundmail\Client;
use Groundmail\Symfony\Transport\GroundmailTransport;
use groundmail\craft\mail\CraftMessageMapper;
use groundmail\craft\mail\GroundmailAdapter;
use PHPUnit\Framework\TestCase;
use yii\base\InvalidConfigException;

final class GroundmailAdapterTest extends TestCase
{
    protected function tearDown(): void
    {
        putenv('GROUNDMAIL_API_KEY');
        putenv('GROUNDMAIL_TEST_BASE_URL');
        unset($_SERVER['GROUNDMAIL_API_KEY']);
    }

    public function testDisplayName(): void
    {
        self::assertSame('Groundmail', GroundmailAdapter::displayName());
    }

    public function testBuildsTheGroundmailTransport(): void
    {
        $transport = (new GroundmailAdapter(['apiKey' => 'gm_live_key']))->defineTransport();

        self::assertInstanceOf(GroundmailTransport::class, $transport);
        self::assertSame('gm_live_key', $this->http($transport, 'apiKey'));
        self::assertSame('https://api.groundmail.dev', $this->http($transport, 'baseUrl'));
        self::assertSame('transactional', $this->property($this->property($transport, 'mapper'), 'category'));
    }

    public function testReadsTheKeyFromAnEnvironmentVariable(): void
    {
        putenv('GROUNDMAIL_API_KEY=gm_from_env');

        $transport = (new GroundmailAdapter(['apiKey' => '$GROUNDMAIL_API_KEY']))->defineTransport();

        self::assertSame('gm_from_env', $this->http($transport, 'apiKey'));
    }

    public function testBaseUrlAndCategory(): void
    {
        putenv('GROUNDMAIL_TEST_BASE_URL=https://api.example.test/');

        $transport = (new GroundmailAdapter([
            'apiKey' => 'gm_x',
            'baseUrl' => '$GROUNDMAIL_TEST_BASE_URL',
            'category' => 'product',
        ]))->defineTransport();

        self::assertSame('https://api.example.test', $this->http($transport, 'baseUrl'));
        self::assertSame('product', $this->property($this->property($transport, 'mapper'), 'category'));
    }

    public function testMissingKeyIsAConfigError(): void
    {
        $this->expectException(InvalidConfigException::class);

        (new GroundmailAdapter(['apiKey' => '$GROUNDMAIL_API_KEY']))->defineTransport();
    }

    public function testValidation(): void
    {
        $adapter = new GroundmailAdapter(['apiKey' => '']);
        self::assertFalse($adapter->validate());
        self::assertTrue($adapter->hasErrors('apiKey'));

        $adapter = new GroundmailAdapter(['apiKey' => 'gmsmtp_abc']);
        self::assertFalse($adapter->validate());
        self::assertStringContainsString('gm_', $adapter->getFirstError('apiKey'));

        putenv('GROUNDMAIL_API_KEY=gm_from_env');
        $adapter = new GroundmailAdapter(['apiKey' => '$GROUNDMAIL_API_KEY']);
        self::assertTrue($adapter->validate());
        self::assertSame('$GROUNDMAIL_API_KEY', $adapter->apiKey, 'the setting keeps the variable name, not the key');
        self::assertTrue((new GroundmailAdapter(['apiKey' => 'gm_ok', 'category' => 'product']))->validate());

        $adapter = new GroundmailAdapter(['apiKey' => 'gm_ok', 'category' => 'newsletter']);
        self::assertFalse($adapter->validate());
        self::assertTrue($adapter->hasErrors('category'));
    }

    public function testSendsThroughTheApi(): void
    {
        $request = null;
        $client = new Client('gm_test', 'https://api.example.test', function (string $method, string $path, ?array $body) use (&$request): array {
            $request = compact('method', 'path', 'body');

            return ['id' => 'msg_craft_1'];
        });
        $transport = new GroundmailTransport($client, new CraftMessageMapper('transactional'));
        $email = (new \Symfony\Component\Mime\Email())
            ->from('Site <noreply@example.com>')->to('user@example.com')->subject('Activate your account')->html('<p>Hi</p>');

        $sent = $transport->send($email);

        self::assertSame('msg_craft_1', $sent?->getMessageId());
        self::assertSame('/emails', $request['path']);
        self::assertSame('transactional', $request['body']['category']);
        self::assertSame('Site <noreply@example.com>', $request['body']['from']);
    }

    private function http(object $transport, string $name): mixed
    {
        return $this->property($this->property($this->property($transport, 'client'), 'http'), $name);
    }

    private function property(object $object, string $name): mixed
    {
        $class = new \ReflectionClass($object);
        while (!$class->hasProperty($name) && $parent = $class->getParentClass()) {
            $class = $parent;
        }

        return $class->getProperty($name)->getValue($object);
    }
}
