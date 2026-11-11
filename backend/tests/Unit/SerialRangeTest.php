<?php

namespace Tests\Unit;

use App\Models\DocumentModel;
use PHPUnit\Framework\TestCase;

/** Which serial numbers a document's model link covers. */
class SerialRangeTest extends TestCase
{
    public function test_ends_are_inclusive_and_either_can_be_open(): void
    {
        $range = $this->link('AS4-24001', 'AS4-24100');
        $this->assertTrue($range->covers('AS4-24001'));
        $this->assertTrue($range->covers('AS4-24100'));
        $this->assertTrue($range->covers('AS4-24050'));
        $this->assertFalse($range->covers('AS4-24000'));
        $this->assertFalse($range->covers('AS4-24101'));

        $this->assertTrue($this->link('AS4-24001', null)->covers('AS4-99999'));
        $this->assertFalse($this->link('AS4-24001', null)->covers('AS4-23999'));
        $this->assertTrue($this->link(null, 'AS4-24001')->covers('AS4-1'));
        $this->assertTrue($this->link(null, null)->covers('anything'));
    }

    public function test_numbers_compare_as_numbers_not_text(): void
    {
        // As text, "SN-100" < "SN-99"; naturally it's the other way round.
        $this->assertTrue($this->link('SN-99', null)->covers('SN-100'));
        $this->assertFalse($this->link(null, 'SN-99')->covers('SN-100'));
    }

    public function test_case_doesnt_matter(): void
    {
        $this->assertTrue($this->link('as4-24001', 'AS4-24100')->covers('As4-24050'));
    }

    public function test_range_labels(): void
    {
        $this->assertSame('S/N A-1 to A-9', $this->link('A-1', 'A-9')->rangeLabel());
        $this->assertSame('S/N A-1 and up', $this->link('A-1', null)->rangeLabel());
        $this->assertSame('Up to S/N A-9', $this->link(null, 'A-9')->rangeLabel());
        $this->assertNull($this->link(null, null)->rangeLabel());
    }

    private function link(?string $from, ?string $to): DocumentModel
    {
        // Attributes only - no database, and no tenant needed.
        $link = new DocumentModel;
        $link->setRawAttributes(['serial_from' => $from, 'serial_to' => $to]);

        return $link;
    }
}
