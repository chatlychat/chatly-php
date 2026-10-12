<?php

declare(strict_types=1);

/**
 * The zero-dependency test runner.
 *
 *     php tests/run.php            # everything
 *     php tests/run.php webhook    # only cases whose name contains "webhook"
 *
 * No Composer step, no PHPUnit, no network, no API key, no running API. The
 * same cases run under PHPUnit via `tests/ContractTest.php`; this exists so
 * the suite is verifiable by anybody with a PHP binary — a test you cannot
 * run is a test nobody runs.
 */

require __DIR__ . '/bootstrap.php';

use Chatly\Tests\Assert;
use Chatly\Tests\AssertionFailed;
use Chatly\Tests\Skipped;
use Chatly\Tests\Suite;

$filter = $argv[1] ?? null;
$cases = Suite::cases();

$passed = 0;
$failed = 0;
$skipped = 0;
$errors = 0;
$selected = 0;
/** @var array<int,string> $problems */
$problems = [];

$started = microtime(true);
Assert::reset();

echo "chatly-php — contract suite\n";
echo 'PHP ' . \PHP_VERSION . ', ' . \count($cases) . " cases registered\n";
if ($filter !== null) {
    echo "filter: {$filter}\n";
}
echo str_repeat('-', 72) . "\n";

foreach ($cases as $name => $case) {
    if ($filter !== null && stripos($name, $filter) === false) {
        continue;
    }
    ++$selected;
    try {
        $case();
        ++$passed;
        echo '  PASS  ' . $name . "\n";
    } catch (Skipped $e) {
        ++$skipped;
        echo '  SKIP  ' . $name . "\n        " . $e->getMessage() . "\n";
    } catch (AssertionFailed $e) {
        ++$failed;
        echo '  FAIL  ' . $name . "\n        " . str_replace("\n", "\n        ", $e->getMessage()) . "\n";
        $problems[] = 'FAIL ' . $name;
    } catch (\Throwable $e) {
        ++$errors;
        echo '  ERROR ' . $name . "\n        " . $e::class . ': ' . $e->getMessage()
            . "\n        at " . $e->getFile() . ':' . $e->getLine() . "\n";
        $problems[] = 'ERROR ' . $name;
    }
}

echo str_repeat('-', 72) . "\n";

/*
 * The contract-coverage check.
 *
 * Only meaningful on an unfiltered run — a filtered run is deliberately a
 * subset, and failing it for that would train everybody to ignore the check.
 */
$coverageProblems = [];
if ($filter === null) {
    foreach (Suite::contractCoverage() as $item => $marker) {
        $found = false;
        foreach (array_keys($cases) as $name) {
            if (str_contains($name, $marker)) {
                $found = true;
                break;
            }
        }
        if (!$found) {
            $coverageProblems[] = "CONTRACT.md §10 item not covered by any case: {$item} ({$marker})";
        }
    }
    foreach ($coverageProblems as $problem) {
        echo '  FAIL  ' . $problem . "\n";
    }
    if ($coverageProblems === []) {
        echo '  ok    all 9 items of CONTRACT.md §10 have at least one case' . "\n";
    }
}

$elapsed = number_format((microtime(true) - $started) * 1000, 1);
printf(
    "%d cases run (%d passed, %d failed, %d errors, %d skipped), %d assertions, %s ms\n",
    $selected,
    $passed,
    $failed,
    $errors,
    $skipped,
    Assert::$count,
    $elapsed,
);

if ($selected === 0) {
    // An empty run is not a pass. A typo'd filter must not look like success.
    echo "NO CASES RAN — refusing to report success\n";
    exit(1);
}

$exitProblems = array_merge($problems, $coverageProblems);
if ($exitProblems !== []) {
    echo "\nFAILED:\n";
    foreach ($exitProblems as $problem) {
        echo '  - ' . $problem . "\n";
    }
    exit(1);
}

echo "OK\n";
exit(0);
