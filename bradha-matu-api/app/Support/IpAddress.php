<?php

namespace App\Support;

class IpAddress
{
    public static function normalize(mixed $address): ?string
    {
        if (! is_string($address)) {
            return null;
        }

        $address = trim($address);
        if ($address === '' || filter_var($address, FILTER_VALIDATE_IP) === false) {
            return null;
        }
        $packed = inet_pton($address);
        if ($packed === false
            || trim($packed, "\0") === ''
            || in_array(strtolower($address), ['255.255.255.255'], true)) {
            return null;
        }

        return $address;
    }
}
