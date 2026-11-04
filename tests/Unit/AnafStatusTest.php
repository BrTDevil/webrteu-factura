<?php

declare(strict_types=1);

use WeBRTeu\Factura\Enums\AnafStatus;

it('reads the server statuses and labels them', function (?string $value, AnafStatus $expected, bool $final, string $label): void {
    $status = AnafStatus::fromServer($value);

    expect($status)->toBe($expected)->and($status->isFinal())->toBe($final)->and($status->label())->toBe($label);
})->with([
    ['Netransmis', AnafStatus::NotSent, false, 'Netransmisă la ANAF'],
    ['Transmis', AnafStatus::Sent, false, 'Transmisă la ANAF'],
    [' in prelucrare ', AnafStatus::Processing, false, 'În prelucrare la ANAF'],
    ['ok', AnafStatus::Ok, true, 'Validată de ANAF'],
    ['nok', AnafStatus::Rejected, true, 'Respinsă de ANAF'],
    ['Eroare Transmitere', AnafStatus::SendError, false, 'Eroare la transmitere'],
    [null, AnafStatus::Unknown, false, 'Status necunoscut'],
]);
