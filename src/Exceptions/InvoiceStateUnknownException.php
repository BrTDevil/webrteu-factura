<?php

declare(strict_types=1);

namespace WeBRTeu\Factura\Exceptions;

/**
 * Cererea de emitere nu a primit răspuns (timeout / conexiune căzută): factura poate exista deja pe server.
 * Nu o retrimite automat; verifică întâi în aplicația de facturare.
 */
class InvoiceStateUnknownException extends FacturaException {}
