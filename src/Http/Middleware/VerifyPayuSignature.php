<?php

declare(strict_types=1);

namespace WizcodePl\LunarPayu\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use WizcodePl\LunarPayu\PayuSignatureVerifier;

/**
 * Rejects any incoming webhook request that doesn't carry a valid
 * `OpenPayU-Signature` matching the body. Controller downstream can assume
 * authenticity.
 */
class VerifyPayuSignature
{
    public function __construct(
        private readonly PayuSignatureVerifier $verifier,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $header = (string) $request->header('OpenPayU-Signature', '');

        if ($header === '' || ! $this->verifier->verify($header, $request->getContent())) {
            return response('SIGNATURE_INVALID', 403);
        }

        return $next($request);
    }
}
