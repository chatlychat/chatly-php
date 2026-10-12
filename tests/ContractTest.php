<?php

declare(strict_types=1);

namespace Chatly\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The same cases as `tests/run.php`, executed by PHPUnit.
 *
 * It is a thin wrapper and that is deliberate: {@see Suite} is the single
 * source of truth for what gets tested, so the two runners cannot disagree
 * about what passed. Adding a case means adding it to a `Cases\*` class, not
 * here.
 *
 * `vendor/bin/phpunit` — or `php tests/run.php` with no Composer step at all.
 */
final class ContractTest extends TestCase
{
    /** @return array<string,array{0:string}> */
    public static function caseNames(): array
    {
        $names = [];
        foreach (array_keys(Suite::cases()) as $name) {
            $names[$name] = [$name];
        }

        return $names;
    }

    #[DataProvider('caseNames')]
    public function testContractCase(string $name): void
    {
        $cases = Suite::cases();
        self::assertArrayHasKey($name, $cases, 'the case must still be registered');

        $before = Assert::$count;
        try {
            $cases[$name]();
        } catch (Skipped $e) {
            self::markTestSkipped($e->getMessage());
        } catch (AssertionFailed $e) {
            self::fail($e->getMessage());
        }

        // PHPUnit counts assertions itself and would call a case that asserted
        // nothing "risky". Report what the case actually checked.
        self::assertGreaterThan(
            $before,
            Assert::$count,
            'a case that asserts nothing is not a passing case',
        );
    }

    /** Every item of CONTRACT.md §10 must still be covered by a named case. */
    public function testEveryContractItemIsCovered(): void
    {
        $names = array_keys(Suite::cases());
        foreach (Suite::contractCoverage() as $item => $marker) {
            $covered = false;
            foreach ($names as $name) {
                if (str_contains($name, $marker)) {
                    $covered = true;
                    break;
                }
            }
            self::assertTrue($covered, "CONTRACT.md §10 item not covered: {$item} ({$marker})");
        }
    }
}
