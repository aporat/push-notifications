# Changelog

All notable changes to this project are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the project uses
[Semantic Versioning](https://semver.org/).

## [Unreleased]

### Added
- `Apns\Client`: token-based pushes to Apple Push Notification service over one persistent
  HTTP/2 connection, with the provider token minted by `Apns\ProviderToken` (ES256 through
  `ext-openssl`, no JWT library), reused for 50 minutes by default and optionally shared across
  processes through any PSR-6 pool. An `ExpiredProviderToken` answer mints a new token and
  retries once.
- `Apns\Message` with a named constructor per `apns-push-type` (`alert`, `background`,
  `liveActivity`, `voip`, `complication`, `fileProvider`, `location`, `pushToTalk`, `widgets`,
  `controls`, `accessory`, `mdm`). Each type derives Apple's topic suffix and default priority;
  nothing is inferred from the payload, and a background push at priority 10, a background push
  carrying an alert, or a Live Activity push without `event` and `timestamp` is refused before it
  leaves. Payload size is checked against 4 KB, or 5 KB for VoIP.
- `Apns\Payload`, `Apns\Alert` and `Apns\Sound` covering every documented `aps` key, including
  `interruption-level`, `relevance-score`, `filter-criteria` and the Live Activity keys.
- `Apns\Reason`: every response reason string Apple documents, including
  `UnrelatedKeyIdInToken` and `BadEnvironmentKeyIdInToken`, mapped onto an `Outcome`.
- `Fcm\Client`: Firebase Cloud Messaging HTTP v1 over a persistent HTTP/2 connection, with the
  OAuth2 access token obtained by `Fcm\AccessTokenProvider` from a service-account assertion
  (RS256 through `ext-openssl`), refreshed five minutes before expiry and optionally shared
  through a PSR-6 pool. A 401 fetches a new token and retries once.
- `Fcm\Message` for notification, data-only and mixed messages to a token, a Firebase
  Installation ID, a topic or a condition, with the Android, APNs and WebPush overrides exposed in
  full. Data values are cast to the strings FCM requires; reserved data keys and the 4 KB limit
  are checked before sending.
- `Fcm\ErrorCode`: the FCM v1 error codes mapped onto an `Outcome`, with a `google.rpc.Status`
  fallback. A `BadRequest` naming the token field turns `INVALID_ARGUMENT` into
  `Outcome::InvalidTarget`.
- `Notification`: one cross-platform description (title, body, subtitle, badge, sound, image,
  data, collapse ID, priority, TTL) rendered as an APNs alert or an FCM message, with `apns()`
  and `fcm()` callbacks that hand you the rendered platform message for anything specific.
  `dataOnly()` renders the FCM side as a data message that wakes the app in every state.
- `Response` and `Outcome`: one normalized answer per push for both services (`Delivered`,
  `Unregistered`, `InvalidTarget`, `InvalidMessage`, `Unauthorized`, `RateLimited`,
  `Unavailable`, `Failed`), with the raw reason, the service's message ID, Apple's 410
  timestamp and any `Retry-After`. Connection failures come back as responses too; only
  configuration and message errors throw.
- `sendMany()` on both clients: concurrent sends multiplexed over the connection, results keyed
  like the input, every message validated before the first one is sent.
- `Testing\FakeSender`, which records messages and answers with a fixed or computed outcome, and
  `Clock\FrozenClock` for token-lifetime tests.
