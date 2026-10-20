<?php

declare(strict_types=1);

namespace Aporat\PushNotifications\Exceptions;

/**
 * A message that breaks the service's rules and would be rejected if sent.
 */
class InvalidMessageException extends PushException {}
