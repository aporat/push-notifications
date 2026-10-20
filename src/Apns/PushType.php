<?php

declare(strict_types=1);

namespace Aporat\PushNotifications\Apns;

/**
 * The apns-push-type header. Each type fixes the apns-topic suffix Apple
 * requires and the priority it defaults to, so a message never has to
 * guess either from its payload.
 */
enum PushType: string
{
    /** A notification the user sees: an alert, badge or sound. */
    case Alert = 'alert';

    /** Content delivered in the background; always priority 5. */
    case Background = 'background';

    /** A request for the user's location, answered by a Location Push Service Extension. */
    case Location = 'location';

    /** An incoming Voice-over-IP call, through PushKit. */
    case Voip = 'voip';

    /** An update for a watchOS app's complications. */
    case Complication = 'complication';

    /** A change for a File Provider extension to pick up. */
    case FileProvider = 'fileprovider';

    /** Tells a managed device to contact its MDM server; the topic is the MDM certificate's UID. */
    case Mdm = 'mdm';

    /** Starts, updates or ends a Live Activity. */
    case LiveActivity = 'liveactivity';

    /** A Push to Talk channel update. */
    case PushToTalk = 'pushtotalk';

    /** Reloads a WidgetKit widget or watch complication. */
    case Widgets = 'widgets';

    /** Reloads a control. */
    case Controls = 'controls';

    /** A paired accessory's response forwarded over the internet. */
    case Accessory = 'accessory';

    /**
     * What Apple appends to the bundle ID to form the topic for this type.
     */
    public function topicSuffix(): string
    {
        return match ($this) {
            self::Alert, self::Background, self::Mdm => '',
            self::Location => '.location-query',
            self::Voip => '.voip',
            self::Complication => '.complication',
            self::FileProvider => '.pushkit.fileprovider',
            self::LiveActivity => '.push-type.liveactivity',
            self::PushToTalk => '.voip-ptt',
            self::Widgets => '.push-type.widgets',
            self::Controls => '.push-type.controls',
            self::Accessory => '.push-type.accessory',
        };
    }

    public function topic(string $bundleId): string
    {
        return $bundleId.$this->topicSuffix();
    }

    public function defaultPriority(): Priority
    {
        return $this === self::Background ? Priority::Conserve : Priority::Immediate;
    }

    /**
     * The payload limit in bytes: 4 KB, or 5 KB for VoIP.
     */
    public function maxPayloadBytes(): int
    {
        return $this === self::Voip ? 5120 : 4096;
    }
}
