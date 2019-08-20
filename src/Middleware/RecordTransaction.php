<?php

namespace PhilKra\ElasticApmLaravel\Middleware;

use Closure;
use Illuminate\Support\Facades\Log;
use PhilKra\Agent;
use PhilKra\Exception\InvalidTraceContextHeaderException;
use PhilKra\Helper\Timer;
use Illuminate\Http\Request;
use PhilKra\TraceParent;

class RecordTransaction
{
    const SPAN_ID_SIZE = 64;
    const TRACE_ID_SIZE = 128;

    /**
     * @var \PhilKra\Agent
     */
    protected $agent;
    /**
     * @var Timer
     */
    private $timer;

    /**
     * RecordTransaction constructor.
     * @param Agent $agent
     */
    public function __construct(Agent $agent, Timer $timer)
    {
        $this->agent = $agent;
        $this->timer = $timer;
    }

    /**
     * [handle description]
     * @param  Request $request [description]
     * @param  Closure $next [description]
     * @return [type]           [description]
     */
    public function handle($request, Closure $next)
    {
        $transaction = $this->agent->startTransaction(
            $this->getTransactionName($request)
        );

        $traceparentHeader = $request->headers->get(TraceParent::HEADER_NAME, null, true);

        if ($traceparentHeader !== null) {
            try {
                $traceParent = TraceParent::createFromHeader($traceparentHeader);
                $transaction->setTraceId($traceParent->getTraceId());
                $transaction->setParentId($traceParent->getSpanId());
            } catch (InvalidTraceContextHeaderException $e) {
                $transaction->setId(self::generateRandomBitsInHex(self::SPAN_ID_SIZE));
                $transaction->setTraceId(self::generateRandomBitsInHex(self::TRACE_ID_SIZE));
            }
        } else {
            $transaction->setId(self::generateRandomBitsInHex(self::SPAN_ID_SIZE));
            $transaction->setTraceId(self::generateRandomBitsInHex(self::TRACE_ID_SIZE));
        }

        Request::macro('__apm__', function() use($transaction) {
            return $transaction;
        });

        // await the outcome
        $response = $next($request);

        $transaction->setResponse([
            'finished' => true,
            'headers_sent' => true,
            'status_code' => $response->getStatusCode(),
            'headers' => $this->formatHeaders($response->headers->all()),
        ]);

        // Need to fix !!
//        $transaction->setUserContext([
//            'id' => optional($request->user())->id,
//            'email' => optional($request->user())->email,
//        ]);

        $transaction->setMeta([
            'result' => $response->getStatusCode(),
            'type' => 'HTTP'
        ]);

        $transaction->setSpans(app('query-log')->toArray());

        if (config('elastic-apm.transactions.use_route_uri')) {
            $transaction->setTransactionName($this->getRouteUriTransactionName($request));
        }

        $transaction->stop($this->timer->getElapsedInMilliseconds());

        return $response;
    }

    /**
     * Perform any final actions for the request lifecycle.
     *
     * @param  \Illuminate\Http\Request $request
     * @param  \Symfony\Component\HttpFoundation\Response $response
     *
     * @return void
     */
    public function terminate($request, $response)
    {
        try {
            $this->agent->send();
        }
        catch(\Throwable $t) {
            Log::error($t);
        }
    }

    /**
     * @param  \Illuminate\Http\Request $request
     *
     * @return string
     */
    protected function getTransactionName(Request $request): string
    {
        // fix leading /
        $path = ($request->server->get('REQUEST_URI') == '') ? '/' : $request->server->get('REQUEST_URI');

        return sprintf(
            "%s %s",
            $request->server->get('REQUEST_METHOD'),
            $path
        );
    }

    /**
     * @param  \Illuminate\Http\Request $request
     *
     * @return string
     */
    protected function getRouteUriTransactionName(Request $request): string
    {
        $path = ($request->path() === '/') ? '' : $request->path();

        return sprintf(
            "%s /%s",
            $request->server->get('REQUEST_METHOD'),
            $path
        );
    }

    /**
     * @param array $headers
     *
     * @return array
     */
    protected function formatHeaders(array $headers): array
    {
        return collect($headers)->map(function ($values, $header) {
            return head($values);
        })->toArray();
    }

    public static function generateRandomBitsInHex(int $bits): string
    {
        return bin2hex(random_bytes($bits/8));
    }
}
