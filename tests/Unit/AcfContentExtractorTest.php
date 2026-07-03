<?php

namespace VragenAI\Tests\Unit;

use Brain\Monkey\Functions;
use VragenAI\AcfContentExtractor;
use VragenAI\Tests\TestCase;

class AcfContentExtractorTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Functions\when('esc_attr')->returnArg(1);
        Functions\when('esc_html')->returnArg(1);
        Functions\when('wp_kses_post')->returnArg(1);
        Functions\when('wp_strip_all_tags')->alias(static fn (string $value): string => strip_tags($value));
    }

    public function test_extracts_supported_regular_acf_fields(): void
    {
        Functions\when('get_field_objects')->justReturn([
            'intro' => [
                'name' => 'intro',
                'label' => 'Intro',
                'type' => 'text',
                'value' => 'Meta backed intro',
            ],
            'ignored_select' => [
                'name' => 'ignored_select',
                'label' => 'Ignored select',
                'type' => 'select',
                'value' => 'Do not index',
            ],
        ]);
        Functions\when('parse_blocks')->justReturn([]);
        Functions\when('apply_filters')->alias(static function (string $hook, mixed $value): mixed {
            return $value;
        });

        $html = (new AcfContentExtractor)->extract(new \WP_Post([
            'ID' => 123,
            'post_content' => '',
        ]));

        $this->assertStringContainsString('Meta backed intro', $html);
        $this->assertStringContainsString('data-acf-field="intro"', $html);
        $this->assertStringNotContainsString('Do not index', $html);
    }

    public function test_extracts_stored_acf_blocks_and_respects_denylist(): void
    {
        Functions\when('get_field_objects')->justReturn([]);
        Functions\when('get_field_object')->alias(static function (string $fieldKey): array {
            return match ($fieldKey) {
                'field_vragenai_body' => [
                    'name' => 'body',
                    'label' => 'Body',
                    'type' => 'textarea',
                ],
                'field_vragenai_cta' => [
                    'name' => 'cta',
                    'label' => 'CTA',
                    'type' => 'text',
                ],
                default => [],
            };
        });
        Functions\when('parse_blocks')->justReturn([
            [
                'blockName' => 'acf/vragenai-test-content',
                'attrs' => [
                    'data' => [
                        'body' => 'Stored block answer text',
                        '_body' => 'field_vragenai_body',
                    ],
                ],
                'innerBlocks' => [],
            ],
            [
                'blockName' => 'acf/vragenai-global-cta',
                'attrs' => [
                    'data' => [
                        'cta' => 'Repeated boilerplate CTA',
                        '_cta' => 'field_vragenai_cta',
                    ],
                ],
                'innerBlocks' => [],
            ],
        ]);
        Functions\when('apply_filters')->alias(static function (string $hook, mixed $value): mixed {
            if ($hook === 'vragenai_acf_block_denylist') {
                return ['acf/vragenai-global-cta'];
            }

            return $value;
        });

        $html = (new AcfContentExtractor)->extract(new \WP_Post([
            'ID' => 123,
            'post_content' => '<!-- wp:acf/vragenai-test-content /-->',
        ]));

        $this->assertStringContainsString('Stored block answer text', $html);
        $this->assertStringContainsString('data-acf-block="acf/vragenai-test-content"', $html);
        $this->assertStringNotContainsString('Repeated boilerplate CTA', $html);
    }

    public function test_strips_acf_blocks_from_render_source(): void
    {
        Functions\when('parse_blocks')->justReturn([
            [
                'blockName' => 'core/paragraph',
                'attrs' => [],
                'innerBlocks' => [],
                'innerHTML' => '<p>Keep rendered paragraph</p>',
                'innerContent' => ['<p>Keep rendered paragraph</p>'],
            ],
            [
                'blockName' => 'acf/vragenai-global-cta',
                'attrs' => [
                    'data' => [
                        'cta_text' => 'Rendered CTA should not enter base content',
                    ],
                ],
                'innerBlocks' => [],
                'innerHTML' => '',
                'innerContent' => [],
            ],
        ]);
        Functions\when('serialize_blocks')->alias(static function (array $blocks): string {
            return implode('', array_map(
                static fn (array $block): string => (string) ($block['innerHTML'] ?? ''),
                $blocks
            ));
        });

        $content = (new AcfContentExtractor)->stripAcfBlocksFromRenderSource(
            'stored content',
            new \WP_Post(['ID' => 123])
        );

        $this->assertSame('<p>Keep rendered paragraph</p>', $content);
    }

    public function test_appends_acf_content_to_existing_fragments(): void
    {
        Functions\when('get_field_objects')->justReturn([
            'intro' => [
                'name' => 'intro',
                'label' => 'Intro',
                'type' => 'text',
                'value' => 'ACF fragment',
            ],
        ]);
        Functions\when('parse_blocks')->justReturn([]);
        Functions\when('apply_filters')->alias(static function (string $hook, mixed $value): mixed {
            return $value;
        });

        $fragments = (new AcfContentExtractor)->contentFragments(['Existing fragment'], new \WP_Post([
            'ID' => 123,
        ]));

        $this->assertSame('Existing fragment', $fragments[0]);
        $this->assertStringContainsString('ACF fragment', $fragments[1]);
    }
}
