# CNB Currency Converter 💱🇨🇿

[![License: MIT](https://img.shields.io/badge/license-MIT-green.svg)](LICENSE)
[![PHP](https://img.shields.io/badge/PHP-%3E%3D%207.1.3-777bb4.svg)](composer.json)
[![Symfony Cache](https://img.shields.io/badge/cache-Symfony%20Cache-black.svg)](https://symfony.com/doc/current/components/cache.html)
[![PSR-6](https://img.shields.io/badge/cache-PSR--6-blue.svg)](https://www.php-fig.org/psr/psr-6/)
[![Composer](https://img.shields.io/badge/install-composer-blue.svg)](https://getcomposer.org/)
[![Packagist](https://img.shields.io/badge/packagist-jdrda%2Fcnb--currency--converter-orange.svg)](composer.json)
[![CNB](https://img.shields.io/badge/data-Czech%20National%20Bank-red.svg)](https://www.cnb.cz/)
[![CZK](https://img.shields.io/badge/base-CZK-success.svg)](#converting-through-czk)
[![Status](https://img.shields.io/badge/status-published-success.svg)](#installation)
[![Packagist Version](https://img.shields.io/packagist/v/jdrda/cnb-currency-converter.svg)](https://packagist.org/packages/jdrda/cnb-currency-converter)
[![Packagist Downloads](https://img.shields.io/packagist/dt/jdrda/cnb-currency-converter.svg)](https://packagist.org/packages/jdrda/cnb-currency-converter)

A small PHP **currency converter** using the official daily exchange-rate table published by the Czech National Bank (CNB).

It can:

- ✅ show the official CZK quote for a given currency,
- ✅ normalize CNB rates quoted for 1, 100 or 1000 units,
- ✅ convert any supported currency to any other supported currency through CZK,
- ✅ load the latest available CNB exchange-rate table,
- ✅ load a historical CNB table by date,
- ✅ cache downloaded tables for one day,
- ✅ use Symfony Cache out of the box,
- ✅ accept any PSR-6 cache pool,
- ✅ work in older PHP 7.1.3 systems and modern PHP 8.x projects.

## Installation

```bash
composer require jdrda/cnb-currency-converter
```

## Requirements

```text
PHP >= 7.1.3
symfony/cache ^4.4 || ^5.4 || ^6.4 || ^7.0
psr/cache ^1.0 || ^2.0 || ^3.0
```

The package allows multiple Symfony Cache major versions, so Composer can choose a version that fits the PHP runtime:

- old PHP 7.1 projects can use Symfony Cache 4.4,
- newer PHP 7.2+ projects can use Symfony Cache 5.4,
- PHP 8.1+ projects can use Symfony Cache 6.4,
- modern PHP 8.2+ / 8.3+ / 8.4 projects can use Symfony Cache 7.x.

## Basic usage

```php
<?php

require __DIR__ . '/vendor/autoload.php';

use CnbCurrencyConverter\CnbCurrencyConverter;

$converter = new CnbCurrencyConverter();

// Official CNB quote: 1 EUR = 24.340 CZK, 100 JPY = 13.249 CZK, etc.
echo $converter->rate('EUR')->formatQuote();

// Normalized CZK value for exactly one unit of a currency.
echo $converter->czkPerUnit('JPY'); // 0.13249 when CNB quotes 100 JPY = 13.249 CZK

// Convert EUR to USD through CZK.
echo $converter->convert(100, 'EUR', 'USD');

// Convert JPY to EUR through CZK. Quoted amounts such as 100 JPY are handled correctly.
echo $converter->convert(10000, 'JPY', 'EUR');
```

## Historical rates

Pass a date to the constructor. The package accepts a parseable date string or a `DateTimeInterface` object.

```php
<?php

use CnbCurrencyConverter\CnbCurrencyConverter;

$converter = new CnbCurrencyConverter('2026-05-06');

// Uses the CNB URL with ?date=06.05.2026
echo $converter->rate('USD')->formatQuote();
```

Dates before `1991` are rejected. Future dates are rejected as well.

## Converting through CZK

The CNB table is a CZK-based table. It does not publish direct EUR/USD, GBP/JPY or CHF/PLN cross rates. This package therefore calculates every conversion like this:

```text
source currency -> CZK -> target currency
```

Example:

```php
<?php

$usd = $converter->convert(100, 'EUR', 'USD');
```

Internally this is equivalent to:

```text
100 EUR * CZK per 1 EUR / CZK per 1 USD
```

This matters because some CNB rows are not quoted for one unit:

```text
Japonsko|jen|100|JPY|13,249
Indonesie|rupie|1000|IDR|1,191
```

For JPY, the normalized rate is therefore:

```text
13.249 / 100 = 0.13249 CZK per 1 JPY
```

## Rounding

By default, `convert()` returns a raw floating-point result.

```php
$result = $converter->convert(100, 'EUR', 'USD');
```

Pass the fourth argument to round the result:

```php
$result = $converter->convert(100, 'EUR', 'USD', 2);
```

## CZK support

`CZK` is supported as a built-in synthetic currency even though it is not listed as a normal row in the CNB table.

```php
$eur = $converter->convert(1000, 'CZK', 'EUR');
$czk = $converter->convert(100, 'EUR', 'CZK');
```

## Cache

Downloaded CNB text files are cached for one day by default.

The default constructor uses Symfony's `FilesystemAdapter`:

```php
$converter = new CnbCurrencyConverter();
```

You can inject any PSR-6 cache pool. This is usually the best option inside Symfony, Laravel or another framework, because your application can own the cache configuration.

```php
<?php

use CnbCurrencyConverter\CnbCurrencyConverter;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;

$cache = new FilesystemAdapter('cnb_currency_converter');
$converter = new CnbCurrencyConverter(null, $cache);
```

You can also change the cache TTL:

```php
$converter = new CnbCurrencyConverter(null, $cache, 3600); // one hour
```

## Laravel example

Laravel does not expose a PSR-6 cache pool directly through its normal cache facade. The simplest option is to let this package use its default Symfony `FilesystemAdapter`, or register your own PSR-6 adapter in the container.

```php
<?php

use CnbCurrencyConverter\CnbCurrencyConverter;

$converter = new CnbCurrencyConverter();
$amount = $converter->convert(100, 'EUR', 'USD', 2);
```

## Symfony example

```yaml
# config/services.yaml
services:
    CnbCurrencyConverter\CnbCurrencyConverter:
        arguments:
            $date: null
            $cache: '@cache.app'
            $cacheTtl: 86400
```

Then inject it into your service or controller:

```php
<?php

use CnbCurrencyConverter\CnbCurrencyConverter;

final class PriceController
{
    private $converter;

    public function __construct(CnbCurrencyConverter $converter)
    {
        $this->converter = $converter;
    }
}
```

## API

### `__construct($date = null, CacheItemPoolInterface $cache = null, $cacheTtl = null)`

Creates a converter.

- `$date` may be `null`, a date string or a `DateTimeInterface` object.
- `$cache` may be `null` or any PSR-6 `CacheItemPoolInterface`.
- `$cacheTtl` is the cache lifetime in seconds.

### `rate($currencyCode)`

Returns an `ExchangeRate` object.

```php
$rate = $converter->rate('EUR');

echo $rate->getCountry();      // EMU
echo $rate->getCurrencyName(); // euro
echo $rate->getAmount();       // 1
echo $rate->getCode();         // EUR
echo $rate->getQuote();        // 24.340
echo $rate->getCzkPerUnit();   // 24.340
echo $rate->formatQuote();     // 1 EUR = 24.34 CZK
```

### `rates()`

Returns all loaded rates indexed by currency code.

```php
$rates = $converter->rates();
echo $rates['USD']->formatQuote();
```

### `czkPerUnit($currencyCode)`

Returns the normalized CZK value for one unit of the requested currency.

```php
echo $converter->czkPerUnit('JPY');
```

### `convert($amount, $fromCurrency, $toCurrency, $precision = null)`

Converts through CZK.

```php
echo $converter->convert(100, 'EUR', 'USD');
echo $converter->convert(100, 'EUR', 'USD', 2);
```

### `getPublishedDate()`

Returns the date published in the CNB file after the table has been loaded.

```php
$date = $converter->getPublishedDate();
echo $date->format('Y-m-d');
```

### `getSequenceNumber()`

Returns the CNB sequence number from the first line of the source file.

```php
echo $converter->getSequenceNumber();
```

## Exceptions

The package throws exceptions from the `CnbCurrencyConverter\Exception` namespace:

- `InvalidRateDateException`
- `InvalidCurrencyException`
- `FetchException`
- `ParseException`

All package exceptions extend `CurrencyConverterException`.

```php
<?php

use CnbCurrencyConverter\CnbCurrencyConverter;
use CnbCurrencyConverter\Exception\CurrencyConverterException;

try {
    $converter = new CnbCurrencyConverter('2026-05-06');
    echo $converter->convert(100, 'EUR', 'USD', 2);
} catch (CurrencyConverterException $e) {
    // Handle package-level failure.
}
```

## Data source

The package uses the official CNB daily exchange-rate TXT endpoint:

```text
https://www.cnb.cz/cs/financni-trhy/devizovy-trh/kurzy-devizoveho-trhu/kurzy-devizoveho-trhu/denni_kurz.txt
```

For historical rates it uses:

```text
https://www.cnb.cz/cs/financni-trhy/devizovy-trh/kurzy-devizoveho-trhu/kurzy-devizoveho-trhu/denni_kurz.txt?date=DD.MM.YYYY
```

## Development

```bash
composer install
vendor/bin/phpunit
```

Run syntax checks manually if needed:

```bash
find src tests -name '*.php' -print0 | xargs -0 -n1 php -l
```

## License

MIT. See [LICENSE](LICENSE).
