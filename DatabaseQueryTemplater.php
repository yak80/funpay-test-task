<?php

namespace FpDbTest;

use Exception;
use mysqli;
use FpDbTest\DatabaseSkipException;

class DatabaseQueryTemplater
{
    const NULL = 'NULL';

    private mysqli $mysqli;

    public function __construct(mysqli $mysqli)
    {
        $this->mysqli = $mysqli;
    }

    protected function castAsId(mixed $array): string {
        if (!is_array($array)) {
            $array = [$array];
        }
        return join(', ', array_map(function($value) {
            if (gettype($value) !== 'string') {
                throw new Exception('Unsupported type');
            }
            return '`'.mysqli_real_escape_string($this->mysqli, $value).'`';
        }, $array));
    }

    protected function castAsArray(array $array): string {
        if (array_is_list($array)) {
            return join(', ', array_map(function($value) {
                return $this->castAsDefault($value);
            }, $array));
        }

        // Associative array
        $parts = [];
        foreach ($array as $key => $value) {
            $parts[] = '`'.mysqli_real_escape_string($this->mysqli, $key).'` = '.$this->castAsDefault($value);
        }
        return join(', ', $parts);
    }

    protected function castAsDefault(mixed $value): string {
        if ($value === null) {
            return self::NULL;
        }
        return match (gettype($value)) {
            'boolean' => (int) $value,
            'integer', 'double' => $value,
            'string' => '\''.mysqli_real_escape_string($this->mysqli, $value).'\'',
            default => throw new Exception('Unsupported type'),
        };
    }

    protected function castToValidType(string $wantedType, mixed $value): float|int|string
    {
        return match ($wantedType) {
            '?a' => $this->castAsArray($value),
            '?d' => $value === null ? self::NULL : (int)$value,
            '?f' => $value === null ? self::NULL : (float)$value,
            '?#' => $this->castAsId($value),
            default => $this->castAsDefault($value),
        };
    }

    protected function renderPart(string $query, array $args, int $offset): array {
        try {
            $result = preg_replace_callback(
                '/(\?[adf#]?)/',
                function($match) use ($args, &$offset) {
                    if (count($args) <= $offset) {
                        throw new Exception('Not enough values to fill query');
                    }
                    $replacement = $args[$offset];
                    $offset++;
                    if ($replacement instanceof DatabaseSkipException) {
                        throw new DatabaseSkipException();
                    }
                    return $this->castToValidType($match[1], $replacement);
                },
                $query
            );
            return [$result, $offset];

        } catch (Exception|DatabaseSkipException $e) {
            if ($e instanceof DatabaseSkipException) {
                return ['', $offset];
            }
            throw $e;
        }
    }

    public function render(string $query, array $args): string
    {
        $parts = [];
        $lastPos = 0;
        $offset = 0;

        preg_match_all('/\{([^}]+)\}/', $query, $matches, PREG_OFFSET_CAPTURE);
        foreach ($matches[0] as $index => $match) {
            $outerContent = $match[0];
            $startedAtPosition = $match[1];
            $insideContent = $matches[1][$index][0];

            $beforeContent = substr($query, $lastPos, $startedAtPosition - $lastPos);
            list($replaced, $offset) = $this->renderPart($beforeContent, $args, $offset);
            $parts[] = $replaced;

            list($replaced, $offset) = $this->renderPart($insideContent, $args, $offset);
            $parts[] = $replaced;

            $lastPos = $startedAtPosition + strlen($outerContent);
        }

        $endContent = substr($query, $lastPos);
        list($replaced) = $this->renderPart($endContent, $args, $offset);
        $parts[] = $replaced;

        return join('', $parts);
    }

}
