<?php

namespace App\services;

use DateTime;

class DateValidator {
    // Returns a valid date or null
    public static function validateDate(?string $date): ?string {
        if (empty($date)) 
            return null;

        $formattedDate = DateTime::createFromFormat('Y-m-d H:i:s', $date);
        return ($formattedDate && $formattedDate->format('Y-m-d H:i:s') === $date) ? $formattedDate->format('Y-m-d H:i:s') : null;
    }

    public static function getNewDate() : string {
        return date('Y-m-d H:i:s');
    }
}