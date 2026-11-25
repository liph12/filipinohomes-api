<?php

/*
|--------------------------------------------------------------------------
| laravel/mcp: what this app changes
|--------------------------------------------------------------------------
|
| Only the settings of the package that FH sets itself; everything else keeps
| the package's default. FH's own MCP settings are in config/fhmcp.php, and
| the server is described in docs/mcp.md.
|
*/

return [

    // Origins a browser may call the MCP server from. Claude's own servers send no Origin header
    // at all and are not affected; this is for the times a request does come from a Claude or ChatGPT page.
    'allowed_origins' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('MCP_ALLOWED_ORIGINS', 'https://claude.ai,https://claude.com,https://chatgpt.com,https://chat.openai.com')),
    ))),

];
