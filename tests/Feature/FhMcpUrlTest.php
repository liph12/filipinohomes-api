<?php

namespace Tests\Feature;

use App\Mcp\Support\McpUrl;
use Tests\TestCase;

/**
 * The address Claude is given for the MCP server (McpUrl): one fixed https
 * address from config on production, the request's own address otherwise, and
 * an exact way to say two addresses are the same place.
 */
class FhMcpUrlTest extends TestCase
{
    public function test_the_configured_public_address_is_used_whatever_host_the_request_came_to(): void
    {
        config(['fhmcp.public_url' => 'https://api.example.test']);

        $this->assertSame('https://api.example.test', McpUrl::root());
        $this->assertSame('https://api.example.test/mcp/filipinohomes', McpUrl::server());

        // A trailing slash or stray spaces in the setting do not change it.
        config(['fhmcp.public_url' => '  https://api.example.test/  ']);
        $this->assertSame('https://api.example.test/mcp/filipinohomes', McpUrl::server());
    }

    public function test_without_a_configured_address_the_request_is_where_it_came_to(): void
    {
        config(['fhmcp.public_url' => '']);

        $this->assertSame(rtrim(url('/'), '/').'/mcp/filipinohomes', McpUrl::server());
    }

    public function test_two_addresses_are_the_same_place_by_their_canonical_form(): void
    {
        foreach ([
            ['https://api.example.test/mcp/filipinohomes', 'https://API.example.test/mcp/filipinohomes'],
            ['https://api.example.test/mcp/filipinohomes', 'https://api.example.test/mcp/filipinohomes/'],
            ['https://api.example.test/mcp/filipinohomes', 'https://api.example.test:443/mcp/filipinohomes'],
            ['http://localhost/mcp/filipinohomes', 'http://localhost:80/mcp/filipinohomes'],
        ] as [$one, $other]) {
            $this->assertTrue(McpUrl::same($one, $other), "{$one} = {$other}");
        }

        foreach ([
            ['https://api.example.test/mcp/filipinohomes', 'http://api.example.test/mcp/filipinohomes'],
            ['https://api.example.test/mcp/filipinohomes', 'https://api.example.test/mcp'],
            ['https://api.example.test/mcp/filipinohomes', 'https://api.example.test:8443/mcp/filipinohomes'],
            ['https://api.example.test/mcp/filipinohomes', 'https://other.example.test/mcp/filipinohomes'],
        ] as [$one, $other]) {
            $this->assertFalse(McpUrl::same($one, $other), "{$one} != {$other}");
        }
    }

    public function test_an_address_with_a_login_part_or_no_scheme_is_never_canonical(): void
    {
        foreach (['https://user:pass@api.example.test/mcp', 'api.example.test/mcp', 'ftp://api.example.test/mcp', '', 'javascript:alert(1)'] as $bad) {
            $this->assertNull(McpUrl::canonical($bad), $bad);
            $this->assertFalse(McpUrl::same($bad, $bad), $bad);
        }
    }
}
