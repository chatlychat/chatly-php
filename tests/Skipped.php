<?php

declare(strict_types=1);

namespace Chatly\Tests;

/**
 * A case that could not run.
 *
 * Reported as SKIP and counted separately — never as a pass. A suite that
 * prints a pass for a test that never executed is worse than one that prints
 * nothing: it is a green light with no evidence behind it.
 */
final class Skipped extends \RuntimeException
{
}
