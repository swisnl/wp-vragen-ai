<?php

namespace VragenAI\Tests\Unit;

use VragenAI\Search\FilterBuilder;
use VragenAI\Tests\TestCase;

class FilterBuilderTest extends TestCase
{
    public function test_empty_builder_yields_no_rows(): void
    {
        $builder = new FilterBuilder;

        $this->assertTrue($builder->isEmpty());
        $this->assertSame([], $builder->toArray());
    }

    public function test_single_condition_hangs_off_the_root_group(): void
    {
        $rows = (new FilterBuilder)->where('languages', 'nl')->toArray();

        $this->assertSame([
            ['id' => '1', 'parent' => 'root', 'operator' => 'AND'],
            ['parent' => '1', 'path' => 'languages', 'operator' => '=', 'value' => 'nl'],
        ], $rows);
    }

    public function test_where_in_with_single_value_collapses_to_equality(): void
    {
        $rows = (new FilterBuilder)->whereIn('post_type', ['post'])->toArray();

        $this->assertSame([
            ['id' => '1', 'parent' => 'root', 'operator' => 'AND'],
            ['parent' => '1', 'path' => 'post_type', 'operator' => '=', 'value' => 'post'],
        ], $rows);
    }

    public function test_where_in_with_multiple_values_builds_an_or_subgroup(): void
    {
        $rows = (new FilterBuilder)->whereIn('post_type', ['post', 'page'])->toArray();

        $this->assertSame([
            ['id' => '1', 'parent' => 'root', 'operator' => 'AND'],
            ['id' => '2', 'parent' => '1', 'operator' => 'OR'],
            ['parent' => '2', 'path' => 'post_type', 'operator' => '=', 'value' => 'post'],
            ['parent' => '2', 'path' => 'post_type', 'operator' => '=', 'value' => 'page'],
        ], $rows);
    }

    public function test_where_in_ignores_empty_and_duplicate_values(): void
    {
        $builder = (new FilterBuilder)->whereIn('post_type', ['', '']);
        $this->assertTrue($builder->isEmpty());

        $rows = (new FilterBuilder)->whereIn('post_type', ['post', 'post'])->toArray();
        $this->assertSame([
            ['id' => '1', 'parent' => 'root', 'operator' => 'AND'],
            ['parent' => '1', 'path' => 'post_type', 'operator' => '=', 'value' => 'post'],
        ], $rows);
    }
}
