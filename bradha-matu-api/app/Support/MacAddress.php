<?php

namespace App\Support;

class MacAddress
{
    /**
     * Device identities must be individual unicast addresses; locally administered unicast addresses remain valid.
     */
    public static function normalize(string $address): ?string
    {
        $compact = strtoupper(str_replace([':', '-', '.'], '', trim($address)));
        if (! preg_match('/^[0-9A-F]{12}$/', $compact)) {
            return null;
        }
        if ($compact === '000000000000'
            || $compact === 'FFFFFFFFFFFF'
            || (hexdec(substr($compact, 0, 2)) & 1) === 1) {
            return null;
        }

        return implode(':', str_split($compact, 2));
    }

    /**
     * True when the address is a validly formatted unicast MAC that nonetheless identifies a
     * MikroTik bridge switch-chip forwarding-database port rather than a client device. These
     * use the form 00:00:NN:00:00:00, where only the third octet carries the port index. They
     * must be dropped during reconciliation so repeated bridge reports never create phantom
     * inventory rows, but they remain structurally valid so a live report is never rejected.
     */
    public static function isBridgePortArtifact(string $address): bool
    {
        $compact = strtoupper(str_replace([':', '-', '.'], '', trim($address)));
        if (! preg_match('/^[0-9A-F]{12}$/', $compact)) {
            return false;
        }
        $octets = str_split($compact, 2);

        return ($octets[0] === '00' && $octets[1] === '00' && $octets[2] !== '00'
            && $octets[3] === '00' && $octets[4] === '00' && $octets[5] === '00');
    }

    public static function variants(string $normalized): array
    {
        $compact = str_replace(':', '', $normalized);
        $hyphenated = str_replace(':', '-', $normalized);
        $dotted = substr($compact, 0, 4).'.'.substr($compact, 4, 4).'.'.substr($compact, 8, 4);

        return array_values(array_unique([
            $normalized,
            strtolower($normalized),
            $hyphenated,
            strtolower($hyphenated),
            $dotted,
            strtolower($dotted),
            $compact,
            strtolower($compact),
        ]));
    }
}
