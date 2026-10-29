<?php declare(strict_types=1);

namespace ShopwareMeilisearch\Search;

/** Language-neutral physical quantities, derived from Shopware's unit ID and purchase unit. */
final class QuantityUnits
{
    // Configure unit IDs per installation.
    private const DEFAULTS = [];
    private const SYMBOLS = ['ml', 'l', 'g', 'kg'];

    public static function parse(string $json): array
    {
        if (trim($json) === '') return self::DEFAULTS;
        $decoded = json_decode($json, false, 32, JSON_THROW_ON_ERROR);
        if (!$decoded instanceof \stdClass) throw new \InvalidArgumentException('Quantity units must be a JSON object.');
        $units = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($units) || count($units) > 32) {
            throw new \InvalidArgumentException('Quantity units must be an object with up to 32 unit IDs.');
        }
        foreach ($units as $id => $unit) {
            $factor = is_array($unit) ? ($unit['factor'] ?? null) : null;
            if (!is_string($id) || !preg_match('/^[a-f0-9]{32}$/D', $id) || !is_array($unit)
                || count($unit) !== 2 || !in_array($unit['symbol'] ?? null, self::SYMBOLS, true)
                || (!is_int($factor) && !is_float($factor)) || !is_finite((float) $factor)
                || $factor <= 0 || $factor > 1000000) {
                throw new \InvalidArgumentException('Each quantity unit needs a unit UUID, a supported SI symbol and a positive conversion factor.');
            }
        }
        return $units;
    }

    /** @return list<string> */
    public static function tokens(?string $unitId, ?float $purchaseUnit, array $units): array
    {
        if ($unitId === null || $purchaseUnit === null || !is_finite($purchaseUnit) || $purchaseUnit <= 0 || !isset($units[$unitId])) return [];
        $amount = $purchaseUnit * $units[$unitId]['factor'];
        if (!is_finite($amount) || $amount <= 0 || $amount > 1000000000) return [];
        $number = rtrim(rtrim(number_format($amount, 8, '.', ''), '0'), '.');
        if ($number === '' || (float) $number <= 0) return [];
        // Product values and factors are floats, so allow their ordinary
        // representation error but never index a materially rounded amount.
        // Incorrect quantities can otherwise promote the wrong product size.
        if (abs($amount - (float) $number) > max(1.0, abs($amount)) * 2e-15) return [];
        $symbol = $units[$unitId]['symbol'];
        $tokens = [$number . ' ' . $symbol, $number . $symbol];
        $other = match ($symbol) { 'ml' => 'l', 'l' => 'ml', 'g' => 'kg', 'kg' => 'g' };
        $converted = self::shift($number, in_array($symbol, ['l', 'kg'], true) ? 3 : -3);
        $fraction = explode('.', $converted, 2)[1] ?? '';
        // Queries accept at most eight decimal places; never round a tiny
        // quantity into an incorrect equivalent or a zero quantity.
        if (strlen($fraction) <= 8) {
            $tokens[] = $converted . ' ' . $other;
            $tokens[] = $converted . $other;
        }
        return $tokens;
    }
    /** Interpret one explicit, unambiguous SI quantity in the customer's query. */
    public static function queryKey(string $query): ?string
    {
        // A sign or range can otherwise hide a second quantity from the
        // unsigned matcher below, making a multi-quantity query look unique.
        // It can also be separated from its number by whitespace.
        if (preg_match('/[+\-−]\s*[0-9]{1,9}(?:[.,][0-9]{1,8})?\s*(?:ml|kg|l|g)(?![\p{L}\p{N}])/iu', $query)) return null;
        $pattern = '/(?<![\p{L}\p{N}.,+\-])([0-9]{1,9}(?:[.,][0-9]{1,8})?)\s*(ml|kg|l|g)(?![\p{L}\p{N}])/iu';
        if (preg_match_all($pattern, $query, $matches, PREG_SET_ORDER) !== 1) return null;
        $parts = explode('.', str_replace(',', '.', $matches[0][1]), 2);
        // A three-digit group after a nonzero integer is ambiguous across
        // locales: 1,000 / 1.000 may mean one or one thousand.
        if (isset($parts[1]) && strlen($parts[1]) === 3 && (int) $parts[0] > 0) return null;
        $number = self::normalizeNumber($parts);
        if ($number === '0') return null;
        return self::canonicalKey($number, strtolower($matches[0][2]));
    }

    public static function isStandalone(string $query): bool
    {
        return self::queryKey($query) !== null
            && (bool) preg_match('/^\s*[0-9]{1,9}(?:[.,][0-9]{1,8})?\s*(?:ml|kg|l|g)\s*$/iuD', $query);
    }

    private static function normalizeNumber(array $parts): string
    {
        $whole = ltrim($parts[0], '0');
        if ($whole === '') $whole = '0';
        $fraction = isset($parts[1]) ? rtrim($parts[1], '0') : '';
        return $whole . ($fraction !== '' ? '.' . $fraction : '');
    }

    /** Move the decimal point using strings so equivalence never rounds. */
    private static function shift(string $number, int $places): string
    {
        $parts = explode('.', $number, 2);
        $digits = $parts[0] . ($parts[1] ?? '');
        $point = strlen($parts[0]) + $places;
        if ($point <= 0) return self::normalizeNumber(['0', str_repeat('0', -$point) . $digits]);
        if ($point >= strlen($digits)) return self::normalizeNumber([$digits . str_repeat('0', $point - strlen($digits))]);
        return self::normalizeNumber([substr($digits, 0, $point), substr($digits, $point)]);
    }

    private static function canonicalKey(string $number, string $symbol): string
    {
        if ($symbol === 'l') return 'ml:' . self::shift($number, 3);
        if ($symbol === 'kg') return 'g:' . self::shift($number, 3);
        return $symbol . ':' . self::normalizeNumber(explode('.', $number, 2));
    }

    public static function key(?string $unitId, ?float $purchaseUnit, array $units): ?string
    {
        $tokens = self::tokens($unitId, $purchaseUnit, $units);
        if ($tokens === []) return null;
        [$number, $symbol] = explode(' ', $tokens[0], 2);
        return self::canonicalKey($number, $symbol);
    }

}
