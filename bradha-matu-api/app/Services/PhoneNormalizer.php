<?php

namespace App\Services;

class PhoneNormalizer
{
    /**
     * Normalize a Kenyan phone number to 2547XXXXXXXX format.
     * Accepts 07XXXXXXXX, +2547XXXXXXXX, 2547XXXXXXXX, 7XXXXXXXX.
     *
     * @throws \InvalidArgumentException
     */
    public static function normalize(string $phone): string
    {
        $phone = preg_replace('/[\s\-()]/', '', $phone);

        if (preg_match('/^\+254\d{9}$/', $phone)) {
            return substr($phone, 1);
        }
        if (preg_match('/^254\d{9}$/', $phone)) {
            return $phone;
        }
        if (preg_match('/^07\d{8}$/', $phone)) {
            return '254' . substr($phone, 1);
        }
        if (preg_match('/^01\d{8}$/', $phone)) {
            return '254' . substr($phone, 1);
        }
        if (preg_match('/^7\d{8}$/', $phone)) {
            return '254' . $phone;
        }
        if (preg_match('/^1\d{8}$/', $phone)) {
            return '254' . $phone;
        }

        throw new \InvalidArgumentException('Invalid Kenyan phone number format');
    }

    public static function toDarajaFormat(string $normalized): string
    {
        if (str_starts_with($normalized, '254')) {
            return $normalized;
        }
        return $normalized;
    }

    public static function toDisplayFormat(string $normalized): string
    {
        if (str_starts_with($normalized, '254')) {
            return '0' . substr($normalized, 3);
        }
        return $normalized;
    }
}
