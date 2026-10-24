<?php

namespace App\Services\OpenAI;

use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The SSE shape of ASA-GPT's report-style replies (price guide, area snapshot, deal verdict).
 *
 * The page draws these as PANELS — stat tiles, bars, tables — not as prose, so the stream sends the
 * structured data first (`{"type":"meta","kind":"area","data":{…}}`), then a one-line text intro the
 * way the other replies stream, then [DONE]. The thread caches the same two halves (`content` + the
 * panel under `metaData[kind]`), so reopening the chat redraws the panel exactly.
 */
trait InsightStream
{
    /**
     * @param  array<int, array{label:string, prompt:string}>  $followUps
     */
    protected function streamInsight(string $mode, string $kind, array $data, string $text, array $followUps, $req): StreamedResponse
    {
        return new StreamedResponse(function () use ($mode, $kind, $data, $text, $followUps, $req) {
            echo 'data: '.json_encode(['type' => 'mode', 'mode' => $mode])."\n\n";
            ob_flush();
            flush();

            $panel = $data + ['follow_ups' => $followUps];
            echo 'data: '.json_encode(['type' => 'meta', 'kind' => $kind, 'data' => $panel])."\n\n";
            ob_flush();
            flush();

            foreach (preg_split('/(\n)/', $text, -1, PREG_SPLIT_DELIM_CAPTURE) as $piece) {
                if ($piece === '') {
                    continue;
                }
                echo 'data: '.json_encode(['type' => 'text', 'text' => $piece])."\n\n";
                ob_flush();
                flush();
                usleep(12000);
            }

            $this->appendMessages($req, [[
                'id' => Str::uuid(),
                'role' => 'assistant',
                'content' => $text,
                'metaData' => [$kind => $panel],
            ]]);

            echo "data: [DONE]\n\n";
            ob_flush();
            flush();
        }, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache',
            'Connection' => 'keep-alive',
            'X-Accel-Buffering' => 'no',
        ]);
    }
}
