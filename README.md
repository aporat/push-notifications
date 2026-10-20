# PHP Push Notifications

A PHP client for Apple Push Notification service (APNs) and Firebase Cloud Messaging (FCM). Token-based authentication, one persistent HTTP/2 connection per service, typed payload builders that never guess, and one normalized response for both services so your code can drop dead tokens and retry transient failures without caring who answered.

[![Latest Stable Version](https://img.shields.io/packagist/v/aporat/push-notifications.svg?style=flat-square&logo=composer)](https://packagist.org/packages/aporat/push-notifications)
[![Downloads](https://img.shields.io/packagist/dt/aporat/push-notifications.svg?style=flat-square&logo=composer)](https://packagist.org/packages/aporat/push-notifications)
[![codecov](https://codecov.io/github/aporat/push-notifications/graph/badge.svg)](https://codecov.io/github/aporat/push-notifications)
![GitHub Actions](https://img.shields.io/github/actions/workflow/status/aporat/push-notifications/ci.yml?style=flat-square)
[![License](https://img.shields.io/packagist/l/aporat/push-notifications.svg?style=flat-square)](https://github.com/aporat/push-notifications/blob/main/LICENSE)

Framework-free. The Laravel integration lives in [aporat/laravel-push-notifications](https://github.com/aporat/laravel-push-notifications).

---

## ✨ Features

- **APNs** with the `.p8` auth key: ES256 provider tokens signed through `ext-openssl`, reused within Apple's 20-to-60-minute window, optionally shared across workers through any PSR-6 cache
- **FCM HTTP v1** with a service account: the OAuth2 access token fetched from an RS256 assertion, cached until shortly before expiry, optionally shared through PSR-6
- **Persistent HTTP/2.** One connection per client, kept for the life of the process, with batches multiplexed over it. No connection per push
- **Push types that mean what they say.** `Message::alert()`, `::background()`, `::liveActivity()`, `::voip()` and the rest fix the `apns-push-type`, derive the `apns-topic` suffix and the default priority. Nothing is inferred from the payload, and a combination Apple would reject is refused before it is sent
- **Every documented field.** The full `aps` dictionary including Live Activity keys; the FCM `notification`, `data`, `android`, `apns`, `webpush` and `fcm_options` blocks
- **One answer shape.** Every push returns a `Response` with a normalized `Outcome`: delivered, unregistered, invalid target, invalid message, unauthorized, rate limited, unavailable. Apple's reason strings and Google's error codes are both kept on it. Connection failures are responses too; only configuration and message errors throw
- **Cross-platform `Notification`** for the common case: describe it once, render it for either service, and reach the full platform message through a callback when you need more
- **Testable.** A `FakeSender` that records messages, a frozen clock, and clients that accept any Guzzle client so a `MockHandler` stands in for Apple and Google

---

## 🛠 Installation

```bash
composer require aporat/push-notifications
```

Requires PHP 8.4+ with `ext-curl` built against nghttp2 (HTTP/2), `ext-openssl` and `ext-json`. The client checks for HTTP/2 support at construction and throws a `ConfigurationException` if curl lacks it:

```bash
php -r 'var_dump((bool) (curl_version()["features"] & CURL_VERSION_HTTP2));'
```

---

## 🍎 APNs

```php
use Aporat\PushNotifications\Apns\Alert;
use Aporat\PushNotifications\Apns\Client;
use Aporat\PushNotifications\Apns\Credentials;
use Aporat\PushNotifications\Apns\Environment;
use Aporat\PushNotifications\Apns\Message;
use Aporat\PushNotifications\Apns\Payload;

$client = new Client(
    new Credentials(
        teamId: 'ABCDE12345',
        keyId: 'XYZ9876543',
        privateKey: '/path/to/AuthKey_XYZ9876543.p8', // or the PEM text itself
    ),
    Environment::Production,
);

$message = Message::alert($deviceToken, Payload::create()
    ->alert(Alert::create('New message', 'Alice sent you a photo'))
    ->badge(1)
    ->sound('default')
    ->threadId('chat-42')
    ->custom('chat_id', 42))
    ->bundleId('com.example.app')
    ->collapseId('chat-42')
    ->expiresIn(3600);

$response = $client->send($message);

if ($response->tokenIsUnregistered()) {
    // 410 Unregistered or ExpiredToken: remove $deviceToken from your database.
}
```

One client serves every app of the team. The message carries the bundle ID; the client holds the key and the connection. Build the client once and keep it: Apple binds a connection to the team that first pushes on it, asks providers to reuse connections for hours to days, and treats connection churn as abuse.

### Push types

Each named constructor sets `apns-push-type`, derives the `apns-topic` from the bundle ID, and picks the priority Apple expects:

| Constructor | Topic | Default priority |
|---|---|---|
| `Message::alert()` | `<bundle>` | 10 |
| `Message::background()` | `<bundle>` | 5 (10 is refused) |
| `Message::liveActivity()` | `<bundle>.push-type.liveactivity` | 10 |
| `Message::voip()` | `<bundle>.voip` | 10 |
| `Message::complication()` | `<bundle>.complication` | 10 |
| `Message::fileProvider()` | `<bundle>.pushkit.fileprovider` | 10 |
| `Message::location()` | `<bundle>.location-query` | 10 |
| `Message::pushToTalk()` | `<bundle>.voip-ptt` | 10 |
| `Message::widgets()` | `<bundle>.push-type.widgets` | 10 |
| `Message::controls()` | `<bundle>.push-type.controls` | 10 |
| `Message::accessory()` | `<bundle>.push-type.accessory` | 10 |
| `Message::mdm($token, $topic)` | the MDM certificate's UID | 10 |

Override any of it with `->topic()`, `->priority()`, `->expiresAt()`, `->neverStore()`, `->collapseId()` and `->id()`.

An alert with `contentAvailable()` stays an alert at priority 10. That hybrid push is a common need and a common thing for libraries to silently turn into a background push; here the type is yours.

### Live Activities

```php
$message = Message::liveActivity($activityToken, Payload::create()
    ->event('update')
    ->timestamp(time())
    ->contentState(['status' => 'out_for_delivery', 'eta' => 12])
    ->staleDate(time() + 600)
    ->alert(Alert::create('Your order is on its way')))
    ->bundleId('com.example.app');
```

`event()` and `timestamp()` are required and checked; starts take `attributes()` as well. Broadcast channels are not covered yet (see below).

### Provider tokens

The client mints an ES256 token on first use and reuses it for 50 minutes, inside Apple's rule of refreshing no more than every 20 and no less than every 60 minutes. An `ExpiredProviderToken` answer mints a new token and retries once.

To share one token across every worker that uses the key, pass a PSR-6 pool:

```php
$client = new Client($credentials, Environment::Production, cache: $psr6Pool);
```

`options` also takes `port` (443 or 2197), `timeout`, `connect_timeout`, `proxy`, `concurrency` for `sendMany()`, and `token_refresh_after` in seconds.

### Sandbox

Tokens from Xcode builds only exist on the sandbox gateway. `$client->withEnvironment(Environment::Sandbox)` gives you a second client on its own connection sharing the same provider token.

---

## 🔥 FCM

```php
use Aporat\PushNotifications\Fcm\AndroidPriority;
use Aporat\PushNotifications\Fcm\Client;
use Aporat\PushNotifications\Fcm\Message;
use Aporat\PushNotifications\Fcm\ServiceAccount;
use Aporat\PushNotifications\Fcm\Target;

$client = new Client(ServiceAccount::fromFile('/path/to/service-account.json'));

$message = Message::to($registrationToken)
    ->notification('New message', 'Alice sent you a photo', image: 'https://cdn.example.com/photo.jpg')
    ->data(['chat_id' => 42])
    ->priority(AndroidPriority::High)
    ->channelId('chat')
    ->badge(1)
    ->collapseKey('chat-42')
    ->ttl(3600);

$response = $client->send($message);
```

`ServiceAccount::fromArray()` and `::fromJson()` take the key file's contents, for a secrets manager; the constructor takes the pieces. Targets are a registration token, `Target::fid()` for a Firebase Installation ID, `Target::topic()` or `Target::condition()`.

A data-only message is just `->data()` without `->notification()`. It wakes the app's messaging service in every app state and leaves the display to the app, which is the closest FCM comes to an APNs alert with content-available.

`->android()`, `->apns()` and `->webpush()` merge raw fields into those blocks for anything without a method. `->badge()` and `->sound()` set both the Android notification fields and the `aps` override, so an iOS device reached through FCM gets them too.

The access token is fetched from Google on first use and reused until five minutes before it expires. A 401 fetches a new one and retries once. Share it across workers with a PSR-6 pool through the `cache` argument, exactly as for APNs.

---

## 📨 One notification, both services

```php
use Aporat\PushNotifications\Notification;

$notification = Notification::create('New collab request', 'Alice wants to work with you')
    ->badge(3)
    ->sound('default')
    ->data(['type' => 'collab', 'id' => 42])
    ->collapseId('collab-42')
    ->ttl(3600);

$apns->send($notification->toApns($device->apns_token, 'com.example.app'));
$fcm->send($notification->toFcm($device->fcm_token));
```

`toApns()` renders an alert push; `toFcm()` a notification message, or a data-only one with `->dataOnly()`. Only what both services share lives on `Notification`. For anything else, the callbacks hand you the rendered platform message with its whole API:

```php
$notification
    ->apns(fn (Apns\Message $m) => $m->payload()->interruptionLevel(InterruptionLevel::TimeSensitive))
    ->fcm(fn (Fcm\Message $m) => $m->channelId('collabs')->analyticsLabel('collab'));
```

---

## 📬 Responses

Every send returns a `Response`. Nothing about the service's answer throws.

| Property | Meaning |
|---|---|
| `outcome` | The normalized `Outcome` (below) |
| `statusCode` | The HTTP status, `0` when the service was never reached |
| `reason` | The service's own code: `Unregistered`, `BadDeviceToken`, `UNREGISTERED`, `QUOTA_EXCEEDED`, … |
| `id` | The `apns-id`, or the FCM message name |
| `message` | A human-readable failure description |
| `timestamp` | APNs 410 only: when the token died, in milliseconds |
| `retryAfter` | Seconds to wait, when the service sent `Retry-After` |
| `body`, `headers` | The decoded body and the headers worth keeping |

| `Outcome` | APNs | FCM | What to do |
|---|---|---|---|
| `Delivered` | 200 | 200 | Nothing |
| `Unregistered` | 410 `Unregistered`, `ExpiredToken` | `UNREGISTERED`, 404 | Delete the token |
| `InvalidTarget` | `BadDeviceToken`, `DeviceTokenNotForTopic` | `SENDER_ID_MISMATCH`, a `BadRequest` on the token field | Don't retry; the token is wrong for this app or environment |
| `InvalidMessage` | `PayloadTooLarge`, `BadTopic`, `BadPriority`, … | `INVALID_ARGUMENT` | Fix the message |
| `Unauthorized` | 403 provider-token and certificate errors, `TopicDisallowed` | `THIRD_PARTY_AUTH_ERROR`, 401/403 | Fix the credentials |
| `RateLimited` | 429 | `QUOTA_EXCEEDED`, 429 | Retry after `retryAfter` or a backoff |
| `Unavailable` | 5xx, `IdleTimeout`, `Shutdown`, connection failures | `UNAVAILABLE`, `INTERNAL`, 5xx, connection failures | Retry with backoff |
| `Failed` | anything else | anything else | Look at `reason` |

`$response->succeeded()`, `->tokenIsUnregistered()` and `->isRetryable()` answer the three questions most code asks. Apple's do-not-retry list (`BadDeviceToken`, `DeviceTokenNotForTopic`, `Forbidden`, `ExpiredToken`, `Unregistered`, `PayloadTooLarge`) is never retryable.

---

## 📦 Batches

```php
$responses = $client->sendMany([
    'alice' => $notification->toApns($aliceToken, 'com.example.app'),
    'bob' => $notification->toApns($bobToken, 'com.example.app'),
]);

foreach ($responses as $name => $response) {
    if ($response->tokenIsUnregistered()) { /* ... */ }
}
```

Sends run concurrently over the one connection, up to the `concurrency` option (50 by default). Results have the same keys as the input. Every message is validated before the first one is sent, so an invalid message throws before anything leaves.

---

## 🧪 Testing

Hand your code a `FakeSender` wherever it expects a `Contracts\Sender`:

```php
use Aporat\PushNotifications\Outcome;
use Aporat\PushNotifications\Testing\FakeSender;

$sender = new FakeSender(Outcome::Delivered);
$sender->respondWith(fn (Message $m) => $m->target() === $deadToken ? Outcome::Unregistered : Outcome::Delivered);

// ... run the code under test ...

$sender->sent();          // every message, in order
$sender->sentTo($token);  // the messages for one target
```

The fake still validates each message, so a bad payload fails in tests the way it would in production. To test the clients themselves, pass a Guzzle client built on a `MockHandler` as `$http`, and a `Clock\FrozenClock` to control token lifetimes.

---

## 📝 Logging

Both clients take a PSR-3 logger. Every accepted push is logged at `debug`, every refused one at `warning`, with the target, status, reason and message ID. Device tokens are logged as-is; redact in your logger if that matters to you.

---

## 🚧 Not included (yet)

- APNs certificate-based authentication. Token auth covers every push type.
- APNs broadcast channel management for Live Activities (`api-manage-broadcast.push.apple.com`).
- The legacy FCM HTTP API and the removed batch endpoint. FCM v1 only.

---

## 📄 License

MIT. See [LICENSE](LICENSE).
