<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class Voucher extends Model
{
    public const STATUS_UNUSED = 'unused';
    public const STATUS_USED = 'used';
    public const STATUS_EXPIRED = 'expired';
    public const STATUS_DISABLED = 'disabled';

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'code', 'package_id', 'status', 'created_by',
        'customer_id', 'used_at', 'batch_id',
    ];

    protected $casts = [
        'used_at' => 'datetime',
    ];

    public function package(): BelongsTo
    {
        return $this->belongsTo(Package::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public static function generateCode(int $length = 10): string
    {
        $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        do {
            $code = '';
            for ($i = 0; $i < $length; $i++) {
                $code .= $chars[random_int(0, strlen($chars) - 1)];
            }
        } while (self::where('code', $code)->exists());

        return $code;
    }

    protected static function booted(): void
    {
        static::creating(function (self $voucher) {
            if (empty($voucher->id)) {
                $voucher->id = Str::uuid()->toString();
            }
            if (empty($voucher->code)) {
                $voucher->code = self::generateCode();
            }
        });
    }
}
