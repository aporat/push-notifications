<?php

declare(strict_types=1);

namespace Aporat\PushNotifications\Fcm;

use Aporat\PushNotifications\Exceptions\AuthenticationException;
use Aporat\PushNotifications\Exceptions\ConfigurationException;
use Aporat\PushNotifications\Support\Http;
use Aporat\PushNotifications\Support\Jwt;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\RequestOptions;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Clock\ClockInterface;
use Psr\Http\Message\ResponseInterface;
use Throwable;

/**
 * Exchanges the service account's RS256 assertion for a Google OAuth2
 * access token with the Firebase Messaging scope, and reuses it until
 * shortly before Google's expiry (one hour).
 *
 * With a PSR-6 pool the token is shared across processes, so a fleet of
 * workers mints one token per hour per service account instead of one per
 * process.
 */
final class AccessTokenProvider
{
    public const string SCOPE = 'https://www.googleapis.com/auth/firebase.messaging';

    public const string GRANT_TYPE = 'urn:ietf:params:oauth:grant-type:jwt-bearer';

    public const int DEFAULT_REFRESH_MARGIN = 300;

    private ?string $token = null;

    private int $expiresAt = 0;

    /**
     * @param  int  $refreshMargin  Seconds before expiry at which a new token is fetched.
     */
    public function __construct(
        private readonly ServiceAccount $account,
        private readonly ClientInterface $http,
        private readonly ClockInterface $clock,
        private readonly ?CacheItemPoolInterface $cache = null,
        private readonly int $refreshMargin = self::DEFAULT_REFRESH_MARGIN,
    ) {}

    /**
     * @throws AuthenticationException
     * @throws ConfigurationException
     */
    public function get(): string
    {
        $now = $this->clock->now()->getTimestamp();

        if ($this->token !== null && $this->fresh($this->expiresAt, $now)) {
            return $this->token;
        }

        $cached = $this->fromCache($now);

        if ($cached !== null) {
            return $cached;
        }

        return $this->refresh();
    }

    /**
     * Fetch a new token now. Use after Google answers 401.
     *
     * @throws AuthenticationException
     * @throws ConfigurationException
     */
    public function refresh(): string
    {
        $now = $this->clock->now()->getTimestamp();

        $assertion = Jwt::rs256(
            $this->account->privateKeyId === null ? [] : ['kid' => $this->account->privateKeyId],
            [
                'iss' => $this->account->clientEmail,
                'scope' => self::SCOPE,
                'aud' => $this->account->tokenUri,
                'iat' => $now,
                'exp' => $now + 3600,
            ],
            $this->account->privateKey,
        );

        try {
            $response = $this->http->request('POST', $this->account->tokenUri, [
                RequestOptions::FORM_PARAMS => ['grant_type' => self::GRANT_TYPE, 'assertion' => $assertion],
                RequestOptions::HTTP_ERRORS => false,
            ]);
        } catch (Throwable $e) {
            throw new AuthenticationException('The Google token endpoint could not be reached: '.$e->getMessage(), 0, $e);
        }

        [$token, $expiresIn] = $this->parse($response);

        $this->token = $token;
        $this->expiresAt = $now + $expiresIn;
        $this->toCache($token, $this->expiresAt);

        return $token;
    }

    public function expiresAt(): int
    {
        return $this->expiresAt;
    }

    /**
     * @return array{0: string, 1: int}
     *
     * @throws AuthenticationException
     */
    private function parse(ResponseInterface $response): array
    {
        $body = Http::decode((string) $response->getBody());

        if ($response->getStatusCode() !== 200) {
            $error = is_string($body['error'] ?? null) ? $body['error'] : 'HTTP '.$response->getStatusCode();
            $description = is_string($body['error_description'] ?? null) ? ': '.$body['error_description'] : '';

            throw new AuthenticationException(sprintf('Google refused the service account assertion for %s (%s%s).', $this->account->clientEmail, $error, $description));
        }

        $token = $body['access_token'] ?? null;
        $expiresIn = $body['expires_in'] ?? 3600;

        if (! is_string($token) || $token === '') {
            throw new AuthenticationException('The Google token response carried no access_token.');
        }

        return [$token, is_numeric($expiresIn) ? (int) $expiresIn : 3600];
    }

    private function fresh(int $expiresAt, int $now): bool
    {
        return $expiresAt - $this->refreshMargin > $now;
    }

    private function cacheKey(): string
    {
        return 'fcm_access_token_'.$this->account->fingerprint();
    }

    private function fromCache(int $now): ?string
    {
        if ($this->cache === null) {
            return null;
        }

        $item = $this->cache->getItem($this->cacheKey());

        if (! $item->isHit()) {
            return null;
        }

        $value = $item->get();

        if (! is_array($value) || ! is_string($value['token'] ?? null) || ! is_int($value['expires_at'] ?? null)) {
            return null;
        }

        if (! $this->fresh($value['expires_at'], $now)) {
            return null;
        }

        $this->token = $value['token'];
        $this->expiresAt = $value['expires_at'];

        return $this->token;
    }

    private function toCache(string $token, int $expiresAt): void
    {
        if ($this->cache === null) {
            return;
        }

        $item = $this->cache->getItem($this->cacheKey());
        $item->set(['token' => $token, 'expires_at' => $expiresAt]);
        $item->expiresAfter(max(1, $expiresAt - $this->refreshMargin - $this->clock->now()->getTimestamp()));
        $this->cache->save($item);
    }
}
