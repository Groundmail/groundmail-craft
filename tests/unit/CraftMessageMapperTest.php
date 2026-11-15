<?php

namespace groundmail\craft\tests;

use groundmail\craft\mail\CraftMessageMapper;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Mime\Email;

final class CraftMessageMapperTest extends TestCase
{
    public function testUsesTheConfiguredCategory(): void
    {
        self::assertSame('transactional', (new CraftMessageMapper())->toSendParams($this->email())['category']);
        self::assertSame('product', (new CraftMessageMapper('product'))->toSendParams($this->email())['category']);
    }

    public function testCategoryHeaderWinsAndIsNotForwarded(): void
    {
        $email = $this->email();
        $email->getHeaders()->addTextHeader('X-Groundmail-Category', 'Newsletter');

        $params = (new CraftMessageMapper('transactional'))->toSendParams($email);

        self::assertSame('newsletter', $params['category']);
        self::assertArrayNotHasKey('headers', $params);
    }

    public function testUnknownCategoryHeaderFallsBack(): void
    {
        $email = $this->email();
        $email->getHeaders()->addTextHeader('X-Groundmail-Category', 'bulk');

        self::assertSame('product', (new CraftMessageMapper('product'))->toSendParams($email)['category']);
    }

    public function testDropsHeadersTheApiRefuses(): void
    {
        $email = $this->email()->returnPath('bounce@example.com')->sender('craft@example.com');
        $email->getHeaders()->addTextHeader('X-Entry-Id', '42');

        $params = (new CraftMessageMapper())->toSendParams($email);

        self::assertSame(['X-Entry-Id' => '42'], $params['headers']);
        self::assertTrue($email->getHeaders()->has('Return-Path'), 'the original message is not changed');
    }

    private function email(): Email
    {
        return (new Email())->from('noreply@example.com')->to('user@example.com')->subject('Hi')->text('Hi');
    }
}
