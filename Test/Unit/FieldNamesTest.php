<?php
declare(strict_types=1);

namespace WarpSearch\Search\Test\Unit;

use PHPUnit\Framework\TestCase;
use WarpSearch\Search\Model\Document\FieldNames;

class FieldNamesTest extends TestCase
{
    public function testTextSuffixFollowsAnalysis(): void
    {
        $this->assertSame('f_name_r1_un', (new FieldNames('standard'))->text('name', 'r1'));
        $this->assertSame('f_name_r1_t', (new FieldNames('english'))->text('name', 'r1'));
        $this->assertSame('all_r2_un', (new FieldNames())->allTexts('r2'));
    }

    public function testSanitizesCodes(): void
    {
        $this->assertSame('f_color_code_kw_ss', FieldNames::field('color--code', FieldNames::KEYWORDS));
        $this->assertSame('f_x_kw_ss', FieldNames::field('___', FieldNames::KEYWORDS));
    }

    public function testLongNamesAreCappedKeepTypeSuffixAndStayUnique(): void
    {
        $a = FieldNames::field(str_repeat('a', 200) . '1', FieldNames::KEYWORDS);
        $b = FieldNames::field(str_repeat('a', 200) . '2', FieldNames::KEYWORDS);
        $this->assertLessThanOrEqual(127, strlen($a));
        $this->assertStringEndsWith('_kw_ss', $a);
        $this->assertNotSame($a, $b);
    }
}
