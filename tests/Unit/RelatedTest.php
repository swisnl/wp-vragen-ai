<?php

namespace VragenAI\Tests\Unit;

use Brain\Monkey\Functions;
use Mockery;
use VragenAI\ApiClient;
use VragenAI\Search\Related;
use VragenAI\Search\SearchService;
use VragenAI\Tests\TestCase;

class RelatedTest extends TestCase
{
    private function stubSite(): void
    {
        $settings = ['customer' => 'acme', 'token' => 'tok', 'search_language_fallback' => true];

        Functions\when('get_option')->alias(static fn (string $key, mixed $default = false) => $key === 'vragenai_settings' ? $settings : $default);
        Functions\when('get_locale')->justReturn('nl_NL');
        Functions\when('get_transient')->justReturn(false);
        Functions\when('set_transient')->justReturn(true);
        Functions\when('is_wp_error')->alias(static fn ($thing): bool => $thing instanceof \WP_Error);
        Functions\when('wp_json_encode')->alias(static fn ($data): string => (string) json_encode($data));
        Functions\when('get_post')->alias(static fn ($id): \WP_Post => new \WP_Post(['ID' => (int) $id]));
        Functions\when('get_the_ID')->justReturn(100);
        Functions\when('get_permalink')->alias(static fn ($id): string => 'https://example.test/?p='.$id);
        Functions\when('get_the_title')->alias(static fn ($id): string => 'Post '.$id);
        Functions\when('esc_url')->returnArg(1);
        Functions\when('esc_html')->returnArg(1);
        Functions\when('esc_attr')->returnArg(1);
        Functions\when('esc_html__')->returnArg(1);
        Functions\when('get_block_wrapper_attributes')->justReturn('class="wp-block-vragenai-related"');
        Functions\when('wp_enqueue_style')->justReturn(true);
    }

    /** @return array<string, mixed> */
    private function similarResponse(): array
    {
        // The similar endpoint excludes the seed (wp_post_100) from its results.
        return [
            'data' => [
                ['attributes' => ['external_reference' => 'wp_post_55']],
                ['attributes' => ['external_reference' => 'wp_post_88']],
            ],
            'meta' => ['total' => 2],
        ];
    }

    public function test_renders_related_posts_as_a_link_list(): void
    {
        $this->stubSite();

        $client = Mockery::mock(ApiClient::class);
        $client->shouldReceive('similarDocuments')->once()->andReturn($this->similarResponse());

        $html = (new Related(new SearchService($client)))->renderBlock(['title' => 'Lees verder']);

        $this->assertStringContainsString('<h2 class="vragenai-related__heading">Lees verder</h2>', $html);
        $this->assertStringContainsString('<ul class="vragenai-related__list">', $html);
        $this->assertStringContainsString('href="https://example.test/?p=55"', $html);
        $this->assertStringContainsString('Post 88', $html);
    }

    public function test_renders_cards_with_image_and_excerpt(): void
    {
        $this->stubSite();
        Functions\when('get_the_post_thumbnail_url')->alias(static fn ($id): string => 'https://example.test/img/'.$id.'.jpg');
        Functions\when('get_the_excerpt')->alias(static fn ($id): string => 'Excerpt '.$id);

        $client = Mockery::mock(ApiClient::class);
        $client->shouldReceive('similarDocuments')->once()->andReturn($this->similarResponse());

        $html = (new Related(new SearchService($client)))->renderBlock(['displayStyle' => 'cards']);

        $this->assertStringContainsString('vragenai-related__list--cards', $html);
        $this->assertStringContainsString('src="https://example.test/img/55.jpg"', $html);
        $this->assertStringContainsString('Excerpt 88', $html);
    }

    public function test_shortcode_limits_the_number_of_items(): void
    {
        $this->stubSite();
        Functions\when('shortcode_atts')->alias(static fn (array $defaults, $atts): array => array_merge($defaults, (array) $atts));

        $client = Mockery::mock(ApiClient::class);
        $client->shouldReceive('similarDocuments')->once()->andReturn($this->similarResponse());

        $html = (new Related(new SearchService($client)))->renderShortcode(['items' => 1]);

        $this->assertStringContainsString('?p=55', $html);
        $this->assertStringNotContainsString('?p=88', $html);
    }

    public function test_renders_nothing_for_visitors_when_no_results(): void
    {
        $this->stubSite();
        Functions\when('current_user_can')->justReturn(false);

        $client = Mockery::mock(ApiClient::class);
        $client->shouldReceive('similarDocuments')->once()->andReturn(new \WP_Error('boom'));

        $html = (new Related(new SearchService($client)))->renderBlock([]);

        $this->assertSame('', $html);
    }

    public function test_hints_editors_when_no_results(): void
    {
        $this->stubSite();
        Functions\when('current_user_can')->justReturn(true);

        $client = Mockery::mock(ApiClient::class);
        $client->shouldReceive('similarDocuments')->once()->andReturn(new \WP_Error('boom'));

        $html = (new Related(new SearchService($client)))->renderBlock([]);

        $this->assertStringContainsString('nog geen gerelateerde content', $html);
    }
}
