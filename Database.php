<?php

namespace FpDbTest;

use Exception;
use mysqli;
use FpDbTest\DatabaseQueryTemplater;
use FpDbTest\DatabaseSkipException;

class Database implements DatabaseInterface
{
    private mysqli $mysqli;

    public function __construct(mysqli $mysqli)
    {
        $this->mysqli = $mysqli;
    }

    public function buildQuery(string $query, array $args = []): string
    {
        $templater = new DatabaseQueryTemplater($this->mysqli);
        return $templater->render($query, $args);
    }

    public function skip(): DatabaseSkipException
    {
        return new DatabaseSkipException();
    }
}
