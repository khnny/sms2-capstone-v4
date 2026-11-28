<?php
/**
 * SMS 2 - Minimal JSON Web Token (JWT) helper, HS256 only.
 *
 * Used by the integration API (api/v1) so separately deployed apps such as
 * the Student Portal can authenticate against SMS 2. Only HS256 is accepted;
 * the "alg" header is never trusted to pick another algorithm.
 */
declare(strict_types=1);

function smsJwtBase64UrlEncode(string $data): string
{
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

function smsJwtBase64UrlDecode(string $data): ?string
{
    if ($data === '' || preg_match('/[^A-Za-z0-9_-]/', $data)) {
        return null;
    }
    $padded = strtr($data, '-_', '+/') . str_repeat('=', (4 - strlen($data) % 4) % 4);
    $decoded = base64_decode($padded, true);
    return $decoded === false ? null : $decoded;
}

/**
 * @param array<string, mixed> $claims
 */
function smsJwtEncode(array $claims, string $secret): string
{
    if (strlen($secret) < 32) {
        throw new RuntimeException('JWT secret must be at least 32 bytes.');
    }
    $header = ['typ' => 'JWT', 'alg' => 'HS256'];
    $segments = [
        smsJwtBase64UrlEncode((string) json_encode($header, JSON_UNESCAPED_SLASHES)),
        smsJwtBase64UrlEncode((string) json_encode($claims, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)),
    ];
    $signature = hash_hmac('sha256', implode('.', $segments), $secret, true);
    $segments[] = smsJwtBase64UrlEncode($signature);
    return implode('.', $segments);
}

/**
 * Verify signature, algorithm, time claims, issuer and audience.
 *
 * @return array{ok: bool, claims?: array<string, mixed>, error?: string}
 */
function smsJwtDecode(string $token, string $secret, string $expectedIssuer, string $expectedAudience, int $leewaySeconds = 30): array
{
    $parts = explode('.', $token);
    if (count($parts) !== 3) {
        return ['ok' => false, 'error' => 'malformed'];
    }
    [$h64, $p64, $s64] = $parts;
    $headerJson = smsJwtBase64UrlDecode($h64);
    $payloadJson = smsJwtBase64UrlDecode($p64);
    $signature = smsJwtBase64UrlDecode($s64);
    if ($headerJson === null || $payloadJson === null || $signature === null) {
        return ['ok' => false, 'error' => 'malformed'];
    }
    $header = json_decode($headerJson, true);
    $claims = json_decode($payloadJson, true);
    if (!is_array($header) || !is_array($claims)) {
        return ['ok' => false, 'error' => 'malformed'];
    }
    if (($header['alg'] ?? '') !== 'HS256') {
        return ['ok' => false, 'error' => 'unsupported_alg'];
    }
    $expected = hash_hmac('sha256', $h64 . '.' . $p64, $secret, true);
    if (!hash_equals($expected, $signature)) {
        return ['ok' => false, 'error' => 'bad_signature'];
    }
    $now = time();
    if (!isset($claims['exp']) || !is_int($claims['exp']) || $claims['exp'] + $leewaySeconds < $now) {
        return ['ok' => false, 'error' => 'expired'];
    }
    if (isset($claims['nbf']) && is_int($claims['nbf']) && $claims['nbf'] - $leewaySeconds > $now) {
        return ['ok' => false, 'error' => 'not_yet_valid'];
    }
    if (($claims['iss'] ?? null) !== $expectedIssuer) {
        return ['ok' => false, 'error' => 'bad_issuer'];
    }
    $aud = $claims['aud'] ?? null;
    $audOk = is_array($aud) ? in_array($expectedAudience, $aud, true) : $aud === $expectedAudience;
    if (!$audOk) {
        return ['ok' => false, 'error' => 'bad_audience'];
    }
    return ['ok' => true, 'claims' => $claims];
}
