<?php

namespace VragenAI\Tests\Unit;

use Brain\Monkey\Functions;
use Mockery;
use VragenAI\ApiClient;
use VragenAI\DocumentSync;
use VragenAI\Tests\TestCase;

class DocumentSyncContentTest extends TestCase
{
    private function buildContent(\WP_Post $post): string
    {
        $sync = new DocumentSync(Mockery::mock(ApiClient::class));

        $method = new \ReflectionMethod(DocumentSync::class, 'buildContent');
        $method->setAccessible(true);

        return $method->invoke($sync, $post);
    }

    public function testWrapsBareTextInParagraph(): void
    {
        Functions\when('apply_filters')->returnArg(2);
        Functions\when('esc_html')->returnArg(1);

        $html = $this->buildContent(new \WP_Post([
            'post_title'   => 'My Title',
            'post_content' => 'Hello world',
        ]));

        $this->assertSame(
            '<!doctype html><html><head><title>My Title</title></head><body><p>Hello world</p></body></html>',
            $html
        );
    }

    public function testDoesNotDoubleWrapBlockLevelContent(): void
    {
        Functions\when('apply_filters')->returnArg(2);
        Functions\when('esc_html')->returnArg(1);

        $html = $this->buildContent(new \WP_Post([
            'post_title'   => 'Title',
            'post_content' => '<p>Already wrapped</p>',
        ]));

        $this->assertStringContainsString('<body><p>Already wrapped</p></body>', $html);
    }
}
