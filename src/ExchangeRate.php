<?php

namespace CnbCurrencyConverter;

/**
 * Immutable value object representing one row from the CNB daily exchange-rate table.
 *
 * CNB does not always quote a currency for exactly one unit. For example, JPY is
 * commonly published as CZK per 100 JPY and IDR as CZK per 1000 IDR. This object
 * therefore keeps both the original official quote and the normalized CZK-per-one-unit
 * value used for conversion.
 */
class ExchangeRate
{
    /** @var string */
    private $country;

    /** @var string */
    private $currencyName;

    /** @var float */
    private $amount;

    /** @var string */
    private $code;

    /** @var float */
    private $quote;

    /**
     * @param string $country Human-readable country or area name from CNB, for example "EMU".
     * @param string $currencyName Human-readable currency name from CNB, for example "euro".
     * @param float|int $amount Quoted amount from CNB, for example 100 for JPY.
     * @param string $code Currency code from CNB, for example "EUR".
     * @param float|int $quote CZK value for the quoted amount, for example 24.340 for 1 EUR.
     */
    public function __construct($country, $currencyName, $amount, $code, $quote)
    {
        $this->country = (string) $country;
        $this->currencyName = (string) $currencyName;
        $this->amount = (float) $amount;
        $this->code = strtoupper((string) $code);
        $this->quote = (float) $quote;
    }

    /** @return string */
    public function getCountry()
    {
        return $this->country;
    }

    /** @return string */
    public function getCurrencyName()
    {
        return $this->currencyName;
    }

    /** @return float */
    public function getAmount()
    {
        return $this->amount;
    }

    /** @return string */
    public function getCode()
    {
        return $this->code;
    }

    /**
     * Returns the raw CNB quote, meaning CZK for the published quoted amount.
     *
     * Example: if CNB publishes "Japonsko|jen|100|JPY|13,249", this method returns
     * 13.249 because the official table says that 100 JPY equals 13.249 CZK.
     *
     * @return float
     */
    public function getQuote()
    {
        return $this->quote;
    }

    /**
     * Returns the normalized CZK value for exactly one unit of this currency.
     *
     * For the JPY example above, this method returns 0.13249 because
     * 13.249 / 100 = 0.13249.
     *
     * @return float
     */
    public function getCzkPerUnit()
    {
        return $this->quote / $this->amount;
    }

    /**
     * Returns a readable version of the official CNB quote.
     *
     * @return string
     */
    public function formatQuote()
    {
        return $this->formatNumber($this->amount) . ' ' . $this->code . ' = ' . $this->formatNumber($this->quote) . ' CZK';
    }

    /**
     * @param float|int $number
     * @return string
     */
    private function formatNumber($number)
    {
        $formatted = number_format((float) $number, 6, '.', '');
        $formatted = rtrim($formatted, '0');
        $formatted = rtrim($formatted, '.');

        return $formatted === '' ? '0' : $formatted;
    }
}
