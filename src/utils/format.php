<?php
// src/utils/format.php
function format_phone($raw) {
    $digits = preg_replace('/\D+/', '', (string)$raw);
    if (strlen($digits) === 11 && $digits[0] === '1') $digits = substr($digits, 1);
    if (strlen($digits) === 10) {
        return sprintf('(%s) %s-%s', substr($digits,0,3), substr($digits,3,3), substr($digits,6,4));
    }
    return $raw;
}
