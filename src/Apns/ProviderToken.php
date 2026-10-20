<?php

declare(strict_types=1);

namespace Aporat\PushNotifications\Apns;

use Aporat\PushNotifications\Exceptions\ConfigurationException;
use Aporat\PushNotifications\Support\Jwt;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Clock\ClockInterface;

/**
 * Mints and caches the ES256 provider token APNs expects in the
 * authorization header.
 *
 * Apple's rules: refresh the token no more than once every 20 minutes and
 * no less than once every 60 minutes; a token whose "iat" is more than an
 * hour old is rejected with ExpiredProviderToken, and a new token more
 * often than every 20 minutes on one connection with
 * TooManyProviderTokenUpdates. This class keeps one token per key for
 * {@see $refreshAfter} seconds (50 minutes by default), in this process
 * and, when a PSR-6 pool is given, shared with every other process that
 * uses the same key.
 */
final class ProviderToken
{
    public const int DEFAULT_REFRESH_AFTER = 3000;

    public const int MIN_REFRESH_AFTER = 1200;

    public const int MAX_REFRESH_AFTER = 3540;

    private ?string $token = null;

    private int $issuedAt = 0;

    /**
     * @param  int  $refreshAfter  Seconds a token is reused before a new one is minted.
     *
     * @throws ConfigurationException
     */
    public function __construct(
        private readonly Credentials $credentials,
        private readonly ClockInterface $clock,
        private readonly ?CacheItemPoolInterface $cache = null,
        private readonly int $refreshAfter = self::DEFAULT_REFRESH_AFTER,
    ) {
        if ($refreshAfter < self::MIN_REFRESH_AFTER || $refreshAfter > self::MAX_REFRESH_AFTER) {
            throw new ConfigurationException(sprintf(
                'The provider token refresh interval must be between %d and %d seconds (Apple allows one refresh per 20 to 60 minutes); %d given.',
                self::MIN_REFRESH_AFTER,
                self::MAX_REFRESH_AFTER,
                $refreshAfter,
            ));
        }
    }

    /**
     * The current token, minted on first use and after it ages out.
     *
     * @throws ConfigurationException
     */
    public function get(): string
    {
        $now = $this->clock->now()->getTimestamp();

        if ($this->token !== null && $now - $this->issuedAt < $this->refreshAfter) {
            return $this->token;
        }

        $cached = $this->fromCache($now);

        if ($cached !== null) {
            return $cached;
        }

        return $this->refresh();
    }

    /**
     * Mint a new token now, regardless of the current one's age. Use after
     * APNs answers ExpiredProviderToken.
     *
     * @throws ConfigurationException
     */
    public function refresh(): string
    {
        $now = $this->clock->now()->getTimestamp();

        $token = Jwt::es256(
            ['kid' => $this->credentials->keyId],
            ['iss' => $this->credentials->teamId, 'iat' => $now],
            $this->credentials->privateKey,
        );

        $this->token = $token;
        $this->issuedAt = $now;
        $this->toCache($token, $now);

        return $token;
    }

    /**
     * When the token was issued, in seconds since the epoch; 0 before first use.
     */
    public function issuedAt(): int
    {
        return $this->issuedAt;
    }

    private function cacheKey(): string
    {
        return 'apns_provider_token_'.$this->credentials->fingerprint();
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

        if (! is_array($value) || ! is_string($value['token'] ?? null) || ! is_int($value['issued_at'] ?? null)) {
            return null;
        }

        if ($now - $value['issued_at'] >= $this->refreshAfter) {
            return null;
        }

        $this->token = $value['token'];
        $this->issuedAt = $value['issued_at'];

        return $this->token;
    }

    private function toCache(string $token, int $issuedAt): void
    {
        if ($this->cache === null) {
            return;
        }

        $item = $this->cache->getItem($this->cacheKey());
        $item->set(['token' => $token, 'issued_at' => $issuedAt]);
        $item->expiresAfter($this->refreshAfter);
        $this->cache->save($item);
    }
}
