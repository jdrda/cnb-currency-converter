<?php

namespace CnbCurrencyConverter\Tests;

use CnbCurrencyConverter\CnbCurrencyConverter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

class CnbCurrencyConverterTest extends TestCase
{
    public function testItReturnsOfficialQuoteAndNormalizedRate()
    {
        $converter = new FixtureConverter('2026-05-06', new ArrayAdapter());

        $this->assertSame('1 EUR = 24.34 CZK', $converter->rate('EUR')->formatQuote());
        $this->assertEquals(0.13249, $converter->czkPerUnit('JPY'), '', 0.0000001);
    }

    public function testItConvertsThroughCzkAndHandlesQuotedAmounts()
    {
        $converter = new FixtureConverter('2026-05-06', new ArrayAdapter());

        $this->assertEquals(117.58, $converter->convert(100, 'EUR', 'USD', 2));
        $this->assertEquals(54.43, $converter->convert(10000, 'JPY', 'EUR', 2));
    }

    public function testCzkIsSupportedAsSyntheticCurrency()
    {
        $converter = new FixtureConverter('2026-05-06', new ArrayAdapter());

        $this->assertSame(100.0, $converter->convert(100, 'CZK', 'CZK'));
        $this->assertEquals(4.11, $converter->convert(100, 'CZK', 'EUR', 2));
    }
}

class FixtureConverter extends CnbCurrencyConverter
{
    protected function download($url)
    {
        return <<<TXT
06.05.2026 #86
země|měna|množství|kód|kurz
EMU|euro|1|EUR|24,340
Japonsko|jen|100|JPY|13,249
USA|dolar|1|USD|20,700
TXT;
    }
}
