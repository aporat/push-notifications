<?php

declare(strict_types=1);

namespace Aporat\PushNotifications\Fcm;

enum TargetType: string
{
    case Token = 'token';
    case Fid = 'fid';
    case Topic = 'topic';
    case Condition = 'condition';
}
