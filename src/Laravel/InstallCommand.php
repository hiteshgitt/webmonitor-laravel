<?php

namespace Ruyalab\WebMonitor\Laravel;

use Illuminate\Console\Command;

/**
 * php artisan webmonitor:install
 * Generates WEBMONITOR_API_KEY in .env (keeps an existing key unless --new) and prints it for the dashboard.
 */
class InstallCommand extends Command
{
    protected $signature = 'webmonitor:install {--new : Replace the existing key with a new one}';

    protected $description = 'Set up Web Monitor: create the API key and show the connection details';

    public function handle(): int
    {
        $envPath = $this->laravel->environmentFilePath();
        if (!is_file($envPath)) {
            $this->error("No .env file found at {$envPath}");
            return self::FAILURE;
        }

        $env = (string) file_get_contents($envPath);
        $existing = preg_match('/^WEBMONITOR_API_KEY=(.*)$/m', $env, $m) ? trim($m[1], " \"'") : '';

        if ($existing !== '' && !$this->option('new')) {
            $key = $existing;
            $this->info('Web Monitor is already set up. Existing API key kept (use --new to replace it).');
        } else {
            $key = bin2hex(random_bytes(32));
            $line = "WEBMONITOR_API_KEY={$key}";
            $env = $existing !== '' || preg_match('/^WEBMONITOR_API_KEY=/m', $env)
                ? preg_replace('/^WEBMONITOR_API_KEY=.*$/m', $line, $env)
                : rtrim($env, "\n") . "\n\n{$line}\n";
            file_put_contents($envPath, $env);
            $this->call('config:clear');
            $this->info('API key saved to .env as WEBMONITOR_API_KEY.');
        }

        $url = rtrim((string) (config('webmonitor.site_url') ?: config('app.url')), '/');
        $this->newLine();
        $this->line('  Add this site in your Web Monitor dashboard:');
        $this->line("  <comment>URL</comment>       {$url}");
        $this->line('  <comment>Platform</comment>  Laravel');
        $this->line("  <comment>API key</comment>   {$key}");
        $this->newLine();
        $this->line("  Test: curl -H \"Authorization: Bearer {$key}\" {$url}/api/webmonitor/health");
        return self::SUCCESS;
    }
}
