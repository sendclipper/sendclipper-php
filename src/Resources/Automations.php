<?php

declare(strict_types=1);

namespace SendClipper\Resources;

use SendClipper\Result;

final class Automations extends Resource
{
    /**
     * Start a Live automation with a `manual` trigger for one contact.
     *
     * @param array<string, mixed> $options
     * @return Result<array<string, mixed>>
     */
    public function enroll(string $automationId, string $email, array $options = []): Result
    {
        return $this->http->call('POST', '/automations/' . self::seg($automationId) . '/enrollments', ['body' => ['email' => $email]], $options);
    }
}
