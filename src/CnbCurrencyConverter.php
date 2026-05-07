<?php

namespace CnbCurrencyConverter;

use CnbCurrencyConverter\Exception\FetchException;
use CnbCurrencyConverter\Exception\InvalidCurrencyException;
use CnbCurrencyConverter\Exception\InvalidRateDateException;
use CnbCurrencyConverter\Exception\ParseException;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;

/**
 * Currency converter based on the official Czech National Bank daily exchange-rate table.
 *
 * The CNB table expresses all exchange rates against CZK. This class converts any
 * supported currency to any other supported currency by normalizing the source amount
 * to CZK first and then converting CZK to the target currency.
 *
 * Cache is handled through PSR-6. By default the class uses Symfony's FilesystemAdapter,
 * but any PSR-6 cache pool can be injected, including framework-managed cache services.
 */
class CnbCurrencyConverter
{
    /** First year accepted by this package for historical CNB daily exchange rates. */
    const MIN_YEAR = 1991;

    /** Default cache lifetime in seconds. */
    const DEFAULT_CACHE_TTL = 86400;

    /** @var string */
    private $latestUrl = 'https://www.cnb.cz/cs/financni-trhy/devizovy-trh/kurzy-devizoveho-trhu/kurzy-devizoveho-trhu/denni_kurz.txt';

    /** @var string */
    private $datedUrl = 'https://www.cnb.cz/cs/financni-trhy/devizovy-trh/kurzy-devizoveho-trhu/kurzy-devizoveho-trhu/denni_kurz.txt?date=%s';

    /** @var \DateTimeImmutable|null */
    private $requestedDate;

    /** @var CacheItemPoolInterface */
    private $cache;

    /** @var int */
    private $cacheTtl;

    /** @var ExchangeRate[]|null */
    private $rates;

    /** @var \DateTimeImmutable|null */
    private $publishedDate;

    /** @var int|null */
    private $sequenceNumber;

    /**
     * @param null|string|\DateTimeInterface $date Optional date. Use null for latest available CNB rates.
     *                                             Strings are parsed by DateTimeImmutable, so "2026-05-06" works.
     * @param CacheItemPoolInterface|null $cache Optional PSR-6 cache pool. Defaults to Symfony FilesystemAdapter.
     * @param int|null $cacheTtl Cache lifetime in seconds. Defaults to one day.
     *
     * @throws InvalidRateDateException
     */
    public function __construct($date = null, CacheItemPoolInterface $cache = null, $cacheTtl = null)
    {
        $this->requestedDate = $this->normalizeDate($date);
        $this->cache = $cache ?: new FilesystemAdapter('cnb_currency_converter', self::DEFAULT_CACHE_TTL);
        $this->cacheTtl = $cacheTtl === null ? self::DEFAULT_CACHE_TTL : (int) $cacheTtl;
    }

    /**
     * Returns the ExchangeRate object for the requested currency.
     *
     * CZK is treated as a built-in synthetic currency with a fixed value of
     * 1 CZK = 1 CZK, even though it is not listed as a normal row in the CNB table.
     *
     * @param string $currencyCode
     * @return ExchangeRate
     *
     * @throws FetchException
     * @throws InvalidCurrencyException
     * @throws ParseException
     */
    public function rate($currencyCode)
    {
        $currencyCode = $this->normalizeCurrencyCode($currencyCode);

        if ($currencyCode === 'CZK') {
            return new ExchangeRate('Czech Republic', 'koruna', 1, 'CZK', 1);
        }

        $rates = $this->rates();

        if (!isset($rates[$currencyCode])) {
            throw new InvalidCurrencyException('Currency "' . $currencyCode . '" is not available in the loaded CNB exchange-rate table.');
        }

        return $rates[$currencyCode];
    }

    /**
     * Returns all loaded rates indexed by currency code.
     *
     * CZK is included as a synthetic rate for convenient conversion logic.
     *
     * @return ExchangeRate[]
     *
     * @throws FetchException
     * @throws ParseException
     */
    public function rates()
    {
        if ($this->rates !== null) {
            return $this->rates;
        }

        $body = $this->loadBody();
        $this->rates = $this->parseBody($body);
        $this->rates['CZK'] = new ExchangeRate('Czech Republic', 'koruna', 1, 'CZK', 1);

        return $this->rates;
    }

    /**
     * Returns CZK value for exactly one unit of the requested currency.
     *
     * This method is useful when you need a normalized rate and do not want to deal
     * with CNB's quoted amounts such as 100 JPY or 1000 IDR.
     *
     * @param string $currencyCode
     * @return float
     */
    public function czkPerUnit($currencyCode)
    {
        return $this->rate($currencyCode)->getCzkPerUnit();
    }

    /**
     * Converts an amount from one supported currency to another supported currency.
     *
     * The calculation is always: source currency -> CZK -> target currency. This is
     * how the CNB table should be used because it does not publish direct cross rates
     * between non-CZK currencies.
     *
     * @param float|int $amount Amount in the source currency.
     * @param string $fromCurrency Source currency code, for example "EUR".
     * @param string $toCurrency Target currency code, for example "USD".
     * @param int|null $precision Optional number of decimal places for rounding.
     * @return float
     */
    public function convert($amount, $fromCurrency, $toCurrency, $precision = null)
    {
        $amount = (float) $amount;
        $fromCzk = $this->czkPerUnit($fromCurrency);
        $toCzk = $this->czkPerUnit($toCurrency);

        $result = ($amount * $fromCzk) / $toCzk;

        if ($precision !== null) {
            return round($result, (int) $precision);
        }

        return $result;
    }

    /**
     * Returns the date published inside the CNB file after the rates have been loaded.
     *
     * When the latest URL is used, this may be different from today's date because CNB
     * publishes rates only on applicable business days.
     *
     * @return \DateTimeImmutable|null
     */
    public function getPublishedDate()
    {
        if ($this->publishedDate === null) {
            $this->rates();
        }

        return $this->publishedDate;
    }

    /**
     * Returns the sequence number from the first line of the CNB file, for example #86.
     *
     * @return int|null
     */
    public function getSequenceNumber()
    {
        if ($this->sequenceNumber === null) {
            $this->rates();
        }

        return $this->sequenceNumber;
    }

    /**
     * @param null|string|\DateTimeInterface $date
     * @return \DateTimeImmutable|null
     *
     * @throws InvalidRateDateException
     */
    private function normalizeDate($date)
    {
        if ($date === null || $date === '') {
            return null;
        }

        if ($date instanceof \DateTimeInterface) {
            $normalized = \DateTimeImmutable::createFromFormat('!Y-m-d', $date->format('Y-m-d'));
        } else {
            try {
                $normalized = new \DateTimeImmutable((string) $date);
            } catch (\Exception $e) {
                throw new InvalidRateDateException('Invalid exchange-rate date. Use a DateTimeInterface object or a parseable date string.');
            }
        }

        if (!$normalized instanceof \DateTimeImmutable) {
            throw new InvalidRateDateException('Invalid exchange-rate date.');
        }

        $normalized = $normalized->setTime(0, 0, 0);
        $year = (int) $normalized->format('Y');

        if ($year < self::MIN_YEAR) {
            throw new InvalidRateDateException('CNB historical exchange-rate dates before ' . self::MIN_YEAR . ' are not supported.');
        }

        $today = new \DateTimeImmutable('today');
        if ($normalized > $today) {
            throw new InvalidRateDateException('Future exchange-rate dates are not supported.');
        }

        return $normalized;
    }

    /**
     * @return string
     */
    private function buildUrl()
    {
        if ($this->requestedDate === null) {
            return $this->latestUrl;
        }

        return sprintf($this->datedUrl, $this->requestedDate->format('d.m.Y'));
    }

    /**
     * @return string
     */
    private function cacheKey()
    {
        $date = $this->requestedDate === null ? 'latest' : $this->requestedDate->format('Y-m-d');

        // PSR-6 cache keys may contain A-Z, a-z, 0-9, _, and .. Keep the key simple
        // and deterministic across all supported cache adapters.
        return 'cnb_currency_converter_' . sha1($date . '|' . $this->buildUrl());
    }

    /**
     * @return string
     *
     * @throws FetchException
     */
    private function loadBody()
    {
        $key = $this->cacheKey();
        $item = $this->cache->getItem($key);

        if ($item->isHit()) {
            return (string) $item->get();
        }

        $body = $this->download($this->buildUrl());

        $item->set($body);
        $item->expiresAfter($this->cacheTtl);
        $this->cache->save($item);

        return $body;
    }

    /**
     * @param string $url
     * @return string
     *
     * @throws FetchException
     */
    protected function download($url)
    {
        $context = stream_context_create(array(
            'http' => array(
                'timeout' => 10,
                'ignore_errors' => true,
                'header' => "User-Agent: jdrda/cnb-currency-converter\r\n",
            ),
        ));

        $body = @file_get_contents($url, false, $context);

        if ($body === false || trim($body) === '') {
            throw new FetchException('Unable to download CNB exchange-rate table from ' . $url . '.');
        }

        return $body;
    }

    /**
     * @param string $body
     * @return ExchangeRate[]
     *
     * @throws ParseException
     */
    private function parseBody($body)
    {
        $body = str_replace(array("\r\n", "\r"), "\n", (string) $body);
        $lines = explode("\n", trim($body));

        if (count($lines) < 3) {
            throw new ParseException('CNB exchange-rate table is too short.');
        }

        $firstLine = trim($lines[0]);
        if (!preg_match('/^(\d{2}\.\d{2}\.\d{4})\s+#(\d+)$/', $firstLine, $matches)) {
            throw new ParseException('The first CNB line does not contain the expected date and sequence number.');
        }

        $publishedDate = \DateTimeImmutable::createFromFormat('!d.m.Y', $matches[1]);
        if (!$publishedDate instanceof \DateTimeImmutable) {
            throw new ParseException('The CNB published date cannot be parsed.');
        }

        $this->publishedDate = $publishedDate;
        $this->sequenceNumber = (int) $matches[2];

        $header = trim($lines[1]);
        if ($header !== 'země|měna|množství|kód|kurz') {
            throw new ParseException('The CNB exchange-rate table header is not recognized.');
        }

        $rates = array();

        for ($i = 2; $i < count($lines); $i++) {
            $line = trim($lines[$i]);

            if ($line === '') {
                // Historical CNB files from 1999-2001 may contain another section after
                // an empty line. That section contains conversion ratios for legacy
                // denominations, not the daily exchange-rate table, so it is ignored.
                break;
            }

            $columns = explode('|', $line);
            if (count($columns) !== 5) {
                throw new ParseException('Invalid CNB exchange-rate row: ' . $line);
            }

            $country = $columns[0];
            $currencyName = $columns[1];
            $amount = $this->parseNumber($columns[2]);
            $code = $this->normalizeCurrencyCode($columns[3]);
            $quote = $this->parseNumber($columns[4]);

            if ($amount <= 0 || $quote <= 0) {
                throw new ParseException('Invalid non-positive CNB amount or quote for currency ' . $code . '.');
            }

            $rates[$code] = new ExchangeRate($country, $currencyName, $amount, $code, $quote);
        }

        if (count($rates) === 0) {
            throw new ParseException('No exchange rates were found in the CNB table.');
        }

        return $rates;
    }

    /**
     * @param string $value
     * @return float
     */
    private function parseNumber($value)
    {
        return (float) str_replace(',', '.', trim((string) $value));
    }

    /**
     * @param string $currencyCode
     * @return string
     */
    private function normalizeCurrencyCode($currencyCode)
    {
        return strtoupper(trim((string) $currencyCode));
    }
}
