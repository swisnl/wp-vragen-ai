<?php

namespace VragenAI\Tests\Unit;

use Brain\Monkey\Functions;
use Mockery;
use VragenAI\ApiClient;
use VragenAI\DocumentSync;
use VragenAI\LanguageResolver;
use VragenAI\Tests\TestCase;

class DocumentSyncRemovalTest extends TestCase
{
    public function test_build_removal_payload_lists_only_visible_remaining_languages(): void
    {
        // Canonical nl is hidden (a draft); en and de are public. Removing de must
        // not re-advertise the hidden nl in the remaining languages.
        Functions\when('get_post')->alias(static fn ($id): \WP_Post => new \WP_Post(['ID' => (int) $id]));
        Functions\when('is_post_publicly_viewable')->alias(static fn (\WP_Post $post): bool => $post->ID !== 1);

        $languageResolver = Mockery::mock(LanguageResolver::class);
        $languageResolver->shouldReceive('getTranslations')->andReturn(['nl' => 1, 'en' => 2, 'de' => 3]);
        $languageResolver->shouldReceive('getPostLanguage')->andReturn('de');
        $languageResolver->shouldReceive('getDefaultLanguage')->andReturn('nl');
        $languageResolver->shouldReceive('getCanonicalPostId')->andReturn(1);

        $sync = new DocumentSync(Mockery::mock(ApiClient::class), $languageResolver);

        $method = new \ReflectionMethod(DocumentSync::class, 'buildRemovalPayload');
        $method->setAccessible(true);
        $payload = $method->invoke($sync, new \WP_Post(['ID' => 3, 'post_type' => 'post']));

        $this->assertSame('wp_post_1', $payload['external_reference']);
        $this->assertSame('de', $payload['language']);
        $this->assertSame(['en'], $payload['remaining_languages']);
    }

    public function test_removing_canonical_language_deletes_document(): void
    {
        Functions\when('is_wp_error')->justReturn(false);

        $client = Mockery::mock(ApiClient::class);
        $client->shouldReceive('findByExternalReference')
            ->with('wp_post_10')
            ->andReturn(['data' => [['id' => 'doc-1', 'attributes' => ['meta_data' => ['languages' => ['nl', 'en']]]]]]);
        $client->shouldReceive('deleteDocument')->with('doc-1')->once()->andReturn([]);
        $client->shouldNotReceive('updateDocument');

        (new DocumentSync($client))->removeTranslation([
            'external_reference' => 'wp_post_10',
            'language' => 'nl',
            'canonical_language' => 'nl',
            'remaining_languages' => ['en'],
        ]);
    }

    public function test_removing_secondary_language_drops_it_from_languages(): void
    {
        Functions\when('is_wp_error')->justReturn(false);

        $client = Mockery::mock(ApiClient::class);
        $client->shouldReceive('findByExternalReference')
            ->with('wp_post_10')
            ->andReturn(['data' => [['id' => 'doc-1', 'attributes' => ['meta_data' => ['languages' => ['nl', 'en'], 'author' => 'Jane']]]]]);
        $client->shouldReceive('updateDocument')
            ->with('doc-1', ['meta_data' => ['languages' => ['nl'], 'author' => 'Jane']])
            ->once()
            ->andReturn([]);
        $client->shouldNotReceive('deleteDocument');

        (new DocumentSync($client))->removeTranslation([
            'external_reference' => 'wp_post_10',
            'language' => 'en',
            'canonical_language' => 'nl',
            'remaining_languages' => ['nl'],
        ]);
    }
}
