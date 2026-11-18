<?php

namespace Tests\Unit;

use App\Models\Agent;
use App\Support\LrAgentStatus;
use PHPUnit\Framework\TestCase;

/**
 * LR's agent `status` → what it means for an FH agent. Pure functions, no app.
 */
class LrAgentStatusTest extends TestCase
{
    public function test_normalize_trims_lowercases_and_turns_nothing_into_empty(): void
    {
        $this->assertSame('inactive', LrAgentStatus::normalize('  Inactive '));
        $this->assertSame('terminated', LrAgentStatus::normalize('TERMINATED'));
        $this->assertSame('', LrAgentStatus::normalize(null));
        $this->assertSame('', LrAgentStatus::normalize(''));
        $this->assertSame('', LrAgentStatus::normalize(['active']));
    }

    public function test_a_value_is_capped_to_the_column_width_without_splitting_a_character(): void
    {
        // agents.lr_employment_status is VARCHAR(32) and MySQL runs in strict
        // mode, so one oddly long value from LR would otherwise throw on every
        // run — and, being first in the queue, starve everyone behind it.
        $long = LrAgentStatus::normalize('Resigned - pending clearance 2026-12-01, see HR memo 14/B');
        $this->assertSame(LrAgentStatus::MAX_LENGTH, mb_strlen($long));
        $this->assertTrue(LrAgentStatus::isUnmapped($long), 'a truncated value must never look like a real status');

        $accented = LrAgentStatus::normalize(str_repeat('É', 80));
        $this->assertSame(LrAgentStatus::MAX_LENGTH, mb_strlen($accented));
        $this->assertTrue(mb_check_encoding($accented, 'UTF-8'));
        $this->assertSame(str_repeat('é', 32), $accented);

        $this->assertSame('terminated', LrAgentStatus::normalize('terminated'), 'real values are untouched');
    }

    public function test_inactive_resigned_and_terminated_demote_to_the_same_fh_status(): void
    {
        foreach (['inactive', 'resigned', 'terminated'] as $status) {
            $this->assertSame($status, LrAgentStatus::demotesTo($status));
        }
        $this->assertSame('terminated', LrAgentStatus::demotesTo(' Terminated '));
    }

    public function test_active_empty_deactivated_and_unknown_values_do_nothing(): void
    {
        // `deactivated` is FH's own dormancy status (45 days without activity,
        // with its own guard and its own repair command) — LR never sets it.
        foreach (['active', '', null, 'deactivated', 'on leave', 'terminated?'] as $status) {
            $this->assertNull(LrAgentStatus::demotesTo($status), var_export($status, true));
        }
    }

    public function test_every_demotion_target_is_a_real_blocked_fh_status(): void
    {
        foreach (LrAgentStatus::lrStatuses() as $lr) {
            $target = LrAgentStatus::demotesTo($lr);
            $this->assertContains($target, Agent::STATUSES);
            $this->assertTrue(Agent::isBlockedStatus($target), $lr);
        }
        $this->assertNotContains('deactivated', LrAgentStatus::lrStatuses());
    }

    public function test_unmapped_is_a_value_we_do_not_recognise(): void
    {
        foreach (['active', '', 'inactive', 'resigned', 'terminated'] as $known) {
            $this->assertFalse(LrAgentStatus::isUnmapped($known), $known);
        }
        foreach (['deactivated', 'on leave', 'probation'] as $unknown) {
            $this->assertTrue(LrAgentStatus::isUnmapped($unknown), $unknown);
        }
    }
}
