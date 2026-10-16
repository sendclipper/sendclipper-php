<?php

declare(strict_types=1);

namespace SendClipper\Transport;

/**
 * Sends one request. Return the response for EVERY status; throw a
 * `TransportException` when no answer arrived.
 */
interface Transport
{
    public function send(TransportRequest $request): TransportResponse;
}
