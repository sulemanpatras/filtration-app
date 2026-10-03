<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * Dev entry point used by shopify.web.toml: runs migrations, a queue worker and the
 * HTTP server on the port assigned by `shopify app dev` (works on Windows and Unix).
 * queue:listen (not queue:work) so code changes are picked up without a restart.
 */
class ShopifyServe extends Command
{
    protected $signature = 'shopify:serve {--port= : Port to listen on (defaults to $PORT from Shopify CLI)}';

    protected $description = 'Run the app for `shopify app dev` (server + queue worker)';

    public function handle(): int
    {
        $port = $this->option('port') ?: (getenv('PORT') ?: getenv('BACKEND_PORT') ?: 8000);

        $this->call('migrate', ['--force' => true]);

        $php = (new PhpExecutableFinder)->find(false) ?: 'php';
        $worker = new Process([$php, base_path('artisan'), 'queue:listen', '--tries=3', '--timeout=3600']);
        $worker->setTimeout(null);
        $worker->start(fn ($type, $output) => $this->output->write($output));
        register_shutdown_function(fn () => $worker->isRunning() && $worker->stop());

        $server = new Process([$php, base_path('artisan'), 'serve', '--host=127.0.0.1', "--port={$port}"]);
        $server->setTimeout(null);

        return $server->run(fn ($type, $output) => $this->output->write($output));
    }
}
