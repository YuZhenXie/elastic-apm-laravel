<?php

namespace PhilKra\ElasticApmLaravel\Providers;

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Collection;
use Illuminate\Support\ServiceProvider;
use PhilKra\Agent;
use PhilKra\ElasticApmLaravel\Apm\SpanCollection;
use PhilKra\ElasticApmLaravel\Apm\Transaction;
use PhilKra\ElasticApmLaravel\Contracts\VersionResolver;
use PhilKra\ElasticApmLaravel\Facades\ElasticApm;
use PhilKra\Helper\Timer;

class ElasticApmServiceProvider extends ServiceProvider
{
    /** @var float */
    private $startTime;
    /** @var string  */
    private $sourceConfigPath = __DIR__ . '/../../config/elastic-apm.php';

    /**
     * Bootstrap the application services.
     *
     * @return void
     */
    public function boot()
    {
        if (class_exists('Illuminate\Foundation\Application', false)) {
            $this->publishes([
                realpath($this->sourceConfigPath) => config_path('elastic-apm.php'),
            ], 'config');
        }

        if (config('elastic-apm.active') === true && config('elastic-apm.spans.querylog.enabled') !== false) {
            $this->listenForQueries();
        }
    }

    /**
     * Register the application services.
     *
     * @return void
     */
    public function register()
    {

        $this->mergeConfigFrom(
            realpath($this->sourceConfigPath),
            'elastic-apm'
        );

        $this->app->singleton(Agent::class, function ($app) {
            return new Agent(
                array_merge(
                    [
                        'framework' => 'Laravel',
                        'frameworkVersion' => app()->version(),
                    ],
                    [
                        'active' => config('elastic-apm.active'),
                        'httpClient' => config('elastic-apm.httpClient'),
                    ],
                    $this->getAppConfig(),
                    config('elastic-apm.env'),
                    config('elastic-apm.server')
                )
            );
        });

        $this->startTime = $this->app['request']->server('REQUEST_TIME_FLOAT') ?? microtime(true);
        $timer = new Timer($this->startTime);

        $collection = new SpanCollection();

        $this->app->instance(Transaction::class, new Transaction($collection, $timer));

        $this->app->instance(Timer::class, $timer);

        $this->app->alias(Agent::class, 'elastic-apm');
        $this->app->instance('query-log', $collection);

    }

    /**
     * @return array
     */
    protected function getAppConfig(): array
    {
        $config = config('elastic-apm.app');

        if ($this->app->bound(VersionResolver::class)) {
            $config['appVersion'] = $this->app->make(VersionResolver::class)->getVersion();
        }

        return $config;
    }

    /**
     * @param Collection $stackTrace
     * @return Collection
     */
    protected function stripVendorTraces(Collection $stackTrace): Collection
    {
        return collect($stackTrace)->filter(function ($trace) {
            return !starts_with(array_get($trace, 'file'), [
                base_path() . '/vendor',
            ]);
        });
    }

    /**
     * @param array $stackTrace
     * @return Collection
     */
    protected function getSourceCode(array $stackTrace): Collection
    {
        if (config('elastic-apm.spans.renderSource', false) === false) {
            return collect([]);
        }

        if (empty(array_get($stackTrace, 'file'))) {
            return collect([]);
        }

        $fileLines = file(array_get($stackTrace, 'file'));
        return collect($fileLines)->filter(function ($code, $line) use ($stackTrace) {
            //file starts counting from 0, debug_stacktrace from 1
            $stackTraceLine = array_get($stackTrace, 'line') - 1;

            $lineStart = $stackTraceLine - 5;
            $lineStop = $stackTraceLine + 5;

            return $line >= $lineStart && $line <= $lineStop;
        })->groupBy(function ($code, $line) use ($stackTrace) {
            if ($line < array_get($stackTrace, 'line')) {
                return 'pre_context';
            }

            if ($line == array_get($stackTrace, 'line')) {
                return 'context_line';
            }

            if ($line > array_get($stackTrace, 'line')) {
                return 'post_context';
            }

            return 'trash';
        });
    }

    protected function listenForQueries()
    {
        $this->app->events->listen(QueryExecuted::class, function (QueryExecuted $query) {
            if (config('elastic-apm.spans.querylog.enabled') === 'auto') {
                if ($query->time < config('elastic-apm.spans.querylog.threshold')) {
                    return;
                }
            }

            $stackTrace = $this->stripVendorTraces(
                collect(
                    debug_backtrace(DEBUG_BACKTRACE_PROVIDE_OBJECT, config('elastic-apm.spans.backtraceDepth', 50))
                )
            );

            $stackTrace = $stackTrace->map(function ($trace) {
                $sourceCode = $this->getSourceCode($trace);

                return [
                    'function' => array_get($trace, 'function') . array_get($trace, 'type') . array_get($trace,
                            'function'),
                    'abs_path' => array_get($trace, 'file'),
                    'filename' => basename(array_get($trace, 'file')),
                    'lineno' => array_get($trace, 'line', 0),
                    'library_frame' => false,
                    'vars' => $vars ?? null,
                    'pre_context' => optional($sourceCode->get('pre_context'))->toArray(),
                    'context_line' => optional($sourceCode->get('context_line'))->first(),
                    'post_context' => optional($sourceCode->get('post_context'))->toArray(),
                ];
            })->values();

            $parentTransaction = ElasticApm::getTransaction(env('ELASTIC_APM_REQUEST_TRANSACTION_NAME'));
            $span = ElasticApm::factory()->newSpan(
                $query->connection->getDatabaseName(),
                $parentTransaction
            );
            $span->setType('db.mysql.query');
            $span->setAction('query');
            $span->setStackTrace($stackTrace->all());
            $span->start();
            $span->stop(round($query->time), 3);
            $span->setContext(['db' => [
                'instance' => $query->connection->getDatabaseName(),
                'statement' => (string) $query->sql,
                'type' => 'sql',
                'user' => $query->connection->getConfig('username'),
            ]]);

            ElasticApm::putEvent($span);
        });
    }
}
