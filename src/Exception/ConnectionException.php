<?php

declare(strict_types=1);

namespace SendClipper\Exception;

/** The request never got an answer: DNS, TLS, a reset socket or the timeout. */
class ConnectionException extends SendClipperException
{
}
