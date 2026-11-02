<?php

namespace VragenAI\Tests\Unit;

use Brain\Monkey\Functions;
use VragenAI\PostVisibility;
use VragenAI\Tests\TestCase;

class PostVisibilityTest extends TestCase
{
    public function test_public_post_is_visible(): void
    {
        Functions\when('is_post_publicly_viewable')->justReturn(true);

        $this->assertTrue(PostVisibility::isPublic(new \WP_Post(['ID' => 5, 'post_status' => 'publish'])));
    }

    public function test_password_protected_post_is_not_visible(): void
    {
        Functions\when('is_post_publicly_viewable')->justReturn(true);

        $this->assertFalse(PostVisibility::isPublic(new \WP_Post(['ID' => 5, 'post_password' => 'hunter2'])));
    }

    public function test_non_viewable_post_is_not_visible(): void
    {
        Functions\when('is_post_publicly_viewable')->justReturn(false);

        $this->assertFalse(PostVisibility::isPublic(new \WP_Post(['ID' => 5])));
    }

    public function test_filter_can_veto_a_public_post(): void
    {
        Functions\when('is_post_publicly_viewable')->justReturn(true);
        Functions\when('apply_filters')->alias(
            static fn (string $hook, mixed $value = null, mixed $post = null): mixed => $hook === 'vragenai_post_is_visible' ? false : $value,
        );

        $this->assertFalse(PostVisibility::isPublic(new \WP_Post(['ID' => 5, 'post_status' => 'publish'])));
    }

    public function test_filter_can_allow_an_otherwise_hidden_post(): void
    {
        Functions\when('is_post_publicly_viewable')->justReturn(false);
        Functions\when('apply_filters')->alias(
            static fn (string $hook, mixed $value = null, mixed $post = null): mixed => $hook === 'vragenai_post_is_visible' ? true : $value,
        );

        $this->assertTrue(PostVisibility::isPublic(new \WP_Post(['ID' => 5, 'post_status' => 'publish'])));
    }
}
