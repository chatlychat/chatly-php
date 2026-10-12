<?php

declare(strict_types=1);

namespace Chatly\Tests;

/** A failed assertion. Distinct from an unexpected error, which is a bug. */
final class AssertionFailed extends \RuntimeException
{
}
