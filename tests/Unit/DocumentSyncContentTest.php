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

    public function test_wraps_bare_text_in_paragraph(): void
    {
        Functions\when('apply_filters')->returnArg(2);
        Functions\when('esc_html')->returnArg(1);

        $html = $this->buildContent(new \WP_Post([
            'post_title' => 'My Title',
            'post_content' => 'Hello world',
        ]));

        $this->assertSame(
            '<!doctype html><html><head><title>My Title</title></head><body><p>Hello world</p></body></html>',
            $html
        );
    }

    public function test_does_not_double_wrap_block_level_content(): void
    {
        Functions\when('apply_filters')->returnArg(2);
        Functions\when('esc_html')->returnArg(1);

        $html = $this->buildContent(new \WP_Post([
            'post_title' => 'Title',
            'post_content' => '<p>Already wrapped</p>',
        ]));

        $this->assertStringContainsString('<body><p>Already wrapped</p></body>', $html);
    }

    public function test_appends_custom_content_fragments(): void
    {
        Functions\when('apply_filters')->alias(static function (string $hook, mixed $value): mixed {
            if ($hook === 'vragenai_document_content_fragments') {
                return ['<div>ACF intro</div>', '<div>ACF body</div>'];
            }

            return $value;
        });
        Functions\when('esc_html')->returnArg(1);

        $html = $this->buildContent(new \WP_Post([
            'post_title' => 'Custom fields',
            'post_content' => '',
        ]));

        $this->assertStringContainsString('<body><div>ACF intro</div>'."\n\n".'<div>ACF body</div></body>', $html);
    }

    public function test_ignores_non_scalar_custom_content_fragments(): void
    {
        Functions\when('apply_filters')->alias(static function (string $hook, mixed $value): mixed {
            if ($hook === 'vragenai_document_content_fragments') {
                return ['Visible', ['ignored'], (object) ['ignored' => true]];
            }

            return $value;
        });
        Functions\when('esc_html')->returnArg(1);

        $html = $this->buildContent(new \WP_Post([
            'post_title' => 'Mixed fragments',
            'post_content' => '',
        ]));

        $this->assertStringContainsString('<body><p>Visible</p></body>', $html);
        $this->assertStringNotContainsString('ignored', $html);
    }
}
