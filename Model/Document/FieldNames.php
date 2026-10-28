<?php
declare(strict_types=1);

namespace WarpSearch\Search\Model\Document;

/**
 * Maps attribute codes to WarpSearch field names, following the convention of the Umbraco provider: WarpSearch has no
 * dynamic mapping, the type of a field is chosen from the suffix after the last underscore ("_kw_ss" multi keyword,
 * "_r1_un" text, ...). Names hold ASCII letters, digits and single underscores, start with a letter, max 127 chars.
 */
class FieldNames
{
    public const TEXT = 'tx';
    public const TEXT_R1 = 'r1';
    public const TEXT_R2 = 'r2';
    public const TEXT_R3 = 'r3';
    public const KEYWORDS = 'kw_ss';
    public const INTEGERS = 'in_is';
    public const DECIMALS = 'dc_ds';
    public const DECIMAL_KEYWORDS = 'dk_ss';
    public const DATES = 'dt_dts';
    public const TEXT_SORT = 'so_s';

    // document level fields (single value keyword suffix "_s", as the Umbraco provider's umb_*_s fields)
    public const INDEX = 'mg_index_s';
    public const PRODUCT_ID = 'mg_id_s';
    public const STORE = 'mg_store_s';
    public const TYPE = 'mg_type_s';

    private const MAX_LENGTH = 127;

    private string $textSuffix;

    public function __construct(string $textAnalysis = 'standard')
    {
        $this->textSuffix = $textAnalysis === 'english' ? 't' : 'un';
    }

    public function text(string $field, string $relevance): string
    {
        return $this->limit(sprintf('f_%s_%s_%s', self::sanitize($field), $relevance, $this->textSuffix));
    }

    public function allTexts(string $relevance): string
    {
        return $this->limit(sprintf('all_%s_%s', $relevance, $this->textSuffix));
    }

    public static function field(string $field, string $kind): string
    {
        return self::limit(sprintf('f_%s_%s', self::sanitize($field), $kind));
    }

    public static function sanitize(string $value): string
    {
        $sanitized = trim((string) preg_replace('/[^A-Za-z0-9]+/', '_', $value), '_');
        return $sanitized === '' ? 'x' : $sanitized;
    }

    private static function limit(string $name): string
    {
        if (strlen($name) <= self::MAX_LENGTH) {
            return $name;
        }
        $hash = substr(hash('sha256', $name), 0, 12);
        $suffixStart = strrpos(substr($name, 0, (int) strrpos($name, '_')), '_');
        $suffix = substr($name, (int) $suffixStart);
        $head = rtrim(substr($name, 0, self::MAX_LENGTH - strlen($suffix) - strlen($hash) - 1), '_');
        return $head . '_' . $hash . $suffix;
    }
}
