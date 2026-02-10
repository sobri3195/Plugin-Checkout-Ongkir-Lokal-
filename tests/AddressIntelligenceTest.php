<?php

use PHPUnit\Framework\TestCase;

final class AddressIntelligenceTest extends TestCase
{
    public function test_preprocess_normalizes_indonesian_abbreviations(): void
    {
        $intelligence = new COL_Address_Intelligence();

        $result = $intelligence->preprocess('  Jl. Dago No. 10, Kec Coblong  ');

        $this->assertSame('jalan dago nomor 10 kecamatan coblong', $result['normalized']);
    }

    public function test_suggest_returns_confidence_and_suggestions(): void
    {
        $intelligence = new COL_Address_Intelligence();

        $result = $intelligence->suggest('Jl dago coblong bandung 40132');

        $this->assertArrayHasKey('confidence', $result);
        $this->assertArrayHasKey('suggestions', $result);
        $this->assertNotEmpty($result['suggestions']);
        $this->assertGreaterThan(60, $result['confidence']);
    }
}
