<?php

namespace App\Http\Controllers\FhOne;

use App\Http\Controllers\Controller;
use App\Http\Requests\FhOne\MetricRequest;
use App\Services\FhOne\BreakdownService;
use App\Services\FhOne\InvalidAsk;
use App\Services\FhOne\MetricCatalog;
use App\Services\FhOne\MetricService;
use App\Services\Reports\Executive\MetricRegistry;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

/**
 * FH One's numbers, /api/fh-one/v1/metrics. Read-only; every number is the
 * AI Report's own (see MetricService). Behind VerifyFhOneKey.
 */
class MetricController extends Controller
{
    public function __construct(private readonly MetricService $metrics) {}

    /** GET /metrics — every metric, with the filters and splits it takes. */
    public function catalog(MetricCatalog $catalog): JsonResponse
    {
        return response()->json($catalog->describe());
    }

    /** GET /metrics/{metric}?date_start=&date_end=&compare=previous&<filter>= */
    public function show(MetricRequest $request, string $metric): JsonResponse
    {
        $this->known($metric);

        return $this->answer(fn () => $this->metrics->value(
            $metric, $request->range(), $request->filters(), $request->input('compare', 'none') === 'previous',
        ));
    }

    /** GET /metrics/{metric}/breakdown?by=team&top=10&date_start=&date_end=&<filter>= */
    public function breakdown(MetricRequest $request, string $metric): JsonResponse
    {
        $this->known($metric);

        if (! $request->filled('by')) {
            throw ValidationException::withMessages(['by' => 'Say what to split by: '.implode(', ', array_keys(BreakdownService::DIMENSIONS)).'.']);
        }

        return $this->answer(fn () => $this->metrics->breakdown(
            $metric, (string) $request->input('by'), $request->range(), $request->filters(), (int) $request->input('top', 10),
        ));
    }

    /** GET /metrics/{metric}/records?page=&per_page=&sort=&dir=&date_start=&date_end=&<filter>= */
    public function records(MetricRequest $request, string $metric): JsonResponse
    {
        $this->known($metric);

        return $this->answer(fn () => $this->metrics->records(
            $metric, $request->range(), $request->filters(),
            (int) $request->input('page', 1), (int) $request->input('per_page', 25),
            $request->input('sort'), $request->input('dir'),
        ));
    }

    private function known(string $metric): void
    {
        if (! MetricRegistry::has($metric)) {
            throw new HttpResponseException(response()->json([
                'error' => 'unknown_metric',
                'message' => "There is no metric '{$metric}'. GET /api/fh-one/v1/metrics lists them.",
            ], 404));
        }
    }

    /** A filter or split the metric can't take is the caller's mistake: 422 on that field, not a crash. */
    private function answer(callable $build): JsonResponse
    {
        try {
            return response()->json($build());
        } catch (InvalidAsk $e) {
            throw ValidationException::withMessages([$e->field => $e->getMessage()]);
        }
    }
}
