<?php

declare(strict_types=1);

namespace App\Utils;

final class Money
{
    private const MAX_CENTS = 999999999999;

    public static function toCents(string $amount): int
    {
        $amount = trim($amount);

        if (!preg_match(
            '/^[0-9]{1,10}(\.[0-9]{1,2})?$/D',
            $amount
        )) {
            throw new ApiException(
                400,
                'MONTO_INVALIDO',
                'El monto debe ser un decimal positivo valido'
            );
        }

        $parts = explode('.', $amount, 2);

        $whole = (int) $parts[0];

        $decimal = str_pad(
            $parts[1] ?? '',
            2,
            '0',
            STR_PAD_RIGHT
        );

        $cents = ($whole * 100) + (int) $decimal;

        self::validateCents($cents);

        return $cents;
    }

    public static function fromCents(int $cents): string
    {
        self::validateCents($cents);

        $whole = intdiv($cents, 100);

        $decimal = $cents % 100;

        return sprintf(
            '%d.%02d',
            $whole,
            $decimal
        );
    }

    public static function multiply(
        string $amount,
        int $quantity
    ): string {
        if ($quantity <= 0) {
            throw new ApiException(
                400,
                'CANTIDAD_INVALIDA',
                'La cantidad debe ser mayor que cero'
            );
        }

        $cents = self::toCents($amount);

        if (
            $cents > 0
            && $quantity > intdiv(
                self::MAX_CENTS,
                $cents
            )
        ) {
            throw new ApiException(
                400,
                'MONTO_EXCEDIDO',
                'El importe supera el limite permitido'
            );
        }

        return self::fromCents(
            $cents * $quantity
        );
    }

    public static function add(string ...$amounts): string
    {
        $total = 0;

        foreach ($amounts as $amount) {
            $cents = self::toCents($amount);

            if ($cents > self::MAX_CENTS - $total) {
                throw new ApiException(
                    400,
                    'MONTO_EXCEDIDO',
                    'El importe supera el limite permitido'
                );
            }

            $total += $cents;
        }

        return self::fromCents($total);
    }

    public static function equals(
        string $first,
        string $second
    ): bool {
        return self::toCents($first)
            === self::toCents($second);
    }

    private static function validateCents(
        int $cents
    ): void {
        if (
            $cents < 0
            || $cents > self::MAX_CENTS
        ) {
            throw new ApiException(
                400,
                'MONTO_INVALIDO',
                'El importe esta fuera del rango permitido'
            );
        }
    }
}