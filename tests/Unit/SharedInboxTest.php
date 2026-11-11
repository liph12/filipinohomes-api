<?php

namespace Tests\Unit;

use App\Support\SharedInbox;
use Tests\TestCase;

/**
 * Fan-out emails go To: info@ with the real recipients in BCC. If a BCC
 * address IS the shared mailbox (info@, or the SMTP account currently in use),
 * the mailbox gets a duplicate copy — one Contact Us submission showed up in
 * info@ 2–6 times. bccWithout() must drop those addresses.
 */
class SharedInboxTest extends TestCase
{
    public function test_drops_info_and_keeps_real_admins(): void
    {
        config(['mail.default' => 'smtp', 'mail.from.address' => 'info@filipinohomes.com', 'mail.mailers.smtp.username' => 'info@filipinohomes.com']);

        $this->assertSame(
            ['admin1@gmail.com', 'agent@yahoo.com'],
            SharedInbox::bccWithout(['admin1@gmail.com', 'INFO@filipinohomes.com', ' info@filipinohomes.com ', 'agent@yahoo.com'], 'info@filipinohomes.com'),
        );
    }

    public function test_drops_the_current_smtp_account_when_it_is_switched(): void
    {
        // SMTP switched to the IT Gmail: an admin using that address is the sending mailbox too.
        config(['mail.default' => 'smtp', 'mail.from.address' => 'info@fhiglobal.ae', 'mail.mailers.smtp.username' => 'it.dept.leuteriorealty@gmail.com']);

        $this->assertSame(
            ['boss@filipinohomes.ph'],
            SharedInbox::bccWithout(['it.dept.leuteriorealty@gmail.com', 'boss@filipinohomes.ph', 'info@filipinohomes.com', 'info@fhiglobal.ae'], 'info@fhiglobal.ae'),
        );
    }

    public function test_skips_addresses_that_forward_into_info(): void
    {
        // itadmin@ is a Hostinger forwarder into info@: BCCing it drops a 2nd copy in info@.
        config([
            'mail.default' => 'smtp',
            'mail.from.address' => 'info@filipinohomes.com',
            'mail.mailers.smtp.username' => 'info@filipinohomes.com',
            'mail.shared_inbox_aliases' => ['itadmin@filipinohomes.com'],
        ]);

        $this->assertSame(
            ['boss@gmail.com'],
            SharedInbox::bccWithout(['ITadmin@filipinohomes.com', 'boss@gmail.com'], 'info@filipinohomes.com'),
        );
        $this->assertTrue(SharedInbox::is('itadmin@filipinohomes.com'));
        $this->assertFalse(SharedInbox::is('boss@gmail.com'));
    }

    public function test_dedupes_case_insensitively_and_skips_blanks(): void
    {
        $this->assertSame(
            ['A@x.com'],
            SharedInbox::bccWithout(['A@x.com', 'a@X.com', '', null], 'info@filipinohomes.com'),
        );
    }
}
