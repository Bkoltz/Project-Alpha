<?php

namespace App\services;

use DateTime;

class DateValidator {
    // Returns a valid date or null
    public static function validateDate(?string $date, string $format = 'Y-m-d H:i:s'): ?string {
        if (empty($date)) 
            return null;

        $formattedDate = DateTime::createFromFormat($format, $date);
        return ($formattedDate && $formattedDate->format($format) === $date) ? $formattedDate->format($format) : null;
    }

    public static function getNewDate() : string {
        return date('Y-m-d H:i:s');
    }
}