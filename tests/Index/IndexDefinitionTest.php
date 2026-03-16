<?php

namespace Kura\Tests\Index;

use Kura\Index\IndexDefinition;
use PHPUnit\Framework\TestCase;

class IndexDefinitionTest extends TestCase
{
    // -------------------------------------------------------------------------
    // Constructor
    // -------------------------------------------------------------------------

    public function test_constructor_sets_columns_and_unique(): void
    {
        // Arrange & Act
        $def = new IndexDefinition(columns: ['country'], unique: true);

        // Assert
        $this->assertSame(['country'], $def->columns, 'columns should be set from constructor');
        $this->assertTrue($def->unique, 'unique should be set from constructor');
    }

    public function test_constructor_defaults_to_non_unique(): void
    {
        // Arrange & Act
        $def = new IndexDefinition(columns: ['name']);

        // Assert
        $this->assertFalse($def->unique, 'unique should default to false');
    }

    // -------------------------------------------------------------------------
    // Static factory: unique()
    // -------------------------------------------------------------------------

    public function test_unique_factory_creates_unique_definition(): void
    {
        // Arrange & Act
        $def = IndexDefinition::unique('email');

        // Assert
        $this->assertSame(['email'], $def->columns, 'unique() should set columns');
        $this->assertTrue($def->unique, 'unique() should set unique to true');
    }

    public function test_unique_factory_with_multiple_columns(): void
    {
        // Arrange & Act
        $def = IndexDefinition::unique('country', 'category');

        // Assert
        $this->assertSame(['country', 'category'], $def->columns, 'unique() should accept multiple columns for composite');
        $this->assertTrue($def->unique, 'unique() composite should still be unique');
    }

    // -------------------------------------------------------------------------
    // Static factory: nonUnique()
    // -------------------------------------------------------------------------

    public function test_non_unique_factory_creates_non_unique_definition(): void
    {
        // Arrange & Act
        $def = IndexDefinition::nonUnique('country');

        // Assert
        $this->assertSame(['country'], $def->columns, 'nonUnique() should set columns');
        $this->assertFalse($def->unique, 'nonUnique() should set unique to false');
    }

    public function test_non_unique_factory_with_multiple_columns(): void
    {
        // Arrange & Act
        $def = IndexDefinition::nonUnique('country', 'category');

        // Assert
        $this->assertSame(['country', 'category'], $def->columns, 'nonUnique() should accept multiple columns for composite');
        $this->assertFalse($def->unique, 'nonUnique() composite should still be non-unique');
    }
}
