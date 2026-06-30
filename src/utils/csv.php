<?php

/**
 * @param resource $stream
 * @return int|false
 */
function csv_write_row($stream, array $fields)
{
    return fputcsv($stream, $fields, ',', '"', '');
}
