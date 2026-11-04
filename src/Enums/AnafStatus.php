<?php

declare(strict_types=1);

namespace WeBRTeu\Factura\Enums;

/**
 * Statusul facturii în SPV, așa cum îl raportează serverul de facturare.
 */
enum AnafStatus: string
{
    case NotSent = 'Netransmis';
    case Sent = 'Transmis';
    case Processing = 'in prelucrare';
    case Ok = 'ok';
    case Rejected = 'nok';
    case SendError = 'Eroare Transmitere';
    case Unknown = 'unknown';

    public static function fromServer(?string $value): self
    {
        return self::tryFrom(trim((string) $value)) ?? self::Unknown;
    }

    /**
     * ok / nok nu se mai schimbă; restul se recitesc la sincronizare.
     */
    public function isFinal(): bool
    {
        return $this === self::Ok || $this === self::Rejected;
    }

    public function label(): string
    {
        return match ($this) {
            self::NotSent => 'Netransmisă la ANAF',
            self::Sent => 'Transmisă la ANAF',
            self::Processing => 'În prelucrare la ANAF',
            self::Ok => 'Validată de ANAF',
            self::Rejected => 'Respinsă de ANAF',
            self::SendError => 'Eroare la transmitere',
            self::Unknown => 'Status necunoscut',
        };
    }
}
