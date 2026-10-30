<?php

declare(strict_types=1);

namespace Sms\Providers;

use Sms\FakeHttpClient;
use Sms\HttpClient;
use Sms\HttpRequest;

final readonly class TemplateFakeHttpClient extends FakeHttpClient
{
    protected function expectedResponses(): array
    {
        return [
            'POST /' => 'OK',
        ];
    }
}

