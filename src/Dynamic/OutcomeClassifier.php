<?php

namespace Dgtlss\Clearance\Dynamic;

use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;
use Illuminate\Http\Request;
use InvalidArgumentException;

final class OutcomeClassifier
{
    public function classify(int $status, ?string $location = null, bool $foreign = false, bool $validationBeforeAuthorization = false, ?string $requestUrl = null): string
    {
        if ($status >= 500) {
            return 'error';
        }

        if (in_array($status, [401, 403], true) || ($status === 404 && $foreign)) {
            return 'deny';
        }

        if ($status === 422) {
            return $validationBeforeAuthorization ? 'unverified' : 'allow';
        }

        if (in_array($status, [301, 302, 303, 307, 308], true) && $location !== null && $location !== '') {
            try {
                $base = new Uri($requestUrl ?? config('app.url', 'http://localhost'));
                $target = UriResolver::resolve($base, new Uri($location));
            } catch (InvalidArgumentException) {
                return 'unverified';
            }

            if (! in_array($base->getScheme(), ['http', 'https'], true) || $base->getHost() === ''
                || [$target->getScheme(), $target->getHost(), $target->getPort()] !== [$base->getScheme(), $base->getHost(), $base->getPort()]) {
                return 'unverified';
            }

            $login = app('router')->getRoutes()->getByName('login');

            if (($login !== null && $login->matches(Request::create((string) $target)))
                || preg_match('~^/(login|sign-in|signin)/?$~i', $target->getPath())) {
                return 'deny';
            }
        }

        return $status >= 200 && $status < 300 ? 'allow' : 'unverified';
    }
}
