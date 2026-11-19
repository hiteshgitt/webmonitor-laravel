<?php
/**
 * Web Monitor integration core for PHP sites (Laravel, CodeIgniter 4, plain PHP).
 *
 * Single source of truth: packages/php-core/WebMonitorCore.php
 * Copied into each PHP package by scripts/sync-php-core.py — edit this file, then run the script.
 *
 * Every value is measured on the server at request time. Things that can't be measured from PHP
 * (visitor analytics, malware scans) are reported as null / "not_available", never guessed.
 */

namespace Ruyalab\WebMonitor;

class WebMonitorCore
{
    public const VERSION = '1.5.0';
    public const ENDPOINTS = ['health', 'analytics', 'performance', 'seo', 'security', 'packages', 'logs', 'recommendations', 'errors', 'filescan'];

    /** @var array{apiKey: string, framework: string, frameworkVersion: string, basePath: string, debug: ?bool, siteUrl: ?string, logPaths: string[]} */
    private array $options;
    private ?array $homepage = null;

    /**
     * @param array $options apiKey, framework, frameworkVersion, basePath (project root),
     *                       debug (framework debug mode, null if unknown), siteUrl, logPaths,
     *                       components (callable returning framework checks, see component())
     */
    public function __construct(array $options)
    {
        $this->options = $options + [
            'apiKey' => '',
            'framework' => 'PHP',
            'frameworkVersion' => PHP_VERSION,
            'basePath' => getcwd(),
            'debug' => null,
            'siteUrl' => null,
            'logPaths' => [],
            'components' => null,
        ];
    }

    /**
     * One health component, the shared format of every integration: Web Monitor shows it, alerts when it
     * fails and says what to do. status: ok | warning | error | unknown.
     */
    public static function component(string $id, string $name, string $status, string $message, ?string $hint = null): array
    {
        return array_filter([
            'id' => $id, 'name' => $name, 'status' => $status,
            'message' => mb_substr($message, 0, 200), 'hint' => $hint === null ? null : mb_substr($hint, 0, 240),
        ], fn ($v) => $v !== null);
    }

    /** Runs a check; a check that throws reports an error instead of breaking the health endpoint. */
    public static function guard(string $id, string $name, callable $check, ?string $hint = null): array
    {
        try {
            return $check();
        } catch (\Throwable $e) {
            return self::component($id, $name, 'error', $e->getMessage(), $hint);
        }
    }

    /** Checks every PHP site has: memory, disk and recent fatal errors in the log. */
    private function baseComponents(): array
    {
        $out = [];
        $memory = $this->memoryStats()['usage_percent'];
        if ($memory !== null) {
            $out[] = self::component('php_memory', 'PHP memory', $memory >= 95 ? 'error' : ($memory >= 80 ? 'warning' : 'ok'),
                "Using {$memory}% of memory_limit (" . ini_get('memory_limit') . ').',
                $memory >= 80 ? 'Raise memory_limit or find the request that uses so much memory.' : null);
        }
        $disk = $this->diskStats();
        if ($disk['free_percent'] !== null) {
            $free = $disk['free_percent'];
            $out[] = self::component('disk', 'Disk space', $free < 5 ? 'error' : ($free < 15 ? 'warning' : 'ok'),
                "{$free}% free (" . round($disk['free_bytes'] / 1073741824, 1) . ' GB).',
                $free < 15 ? 'Free space: old backups, logs and cache folders are the usual culprits.' : null);
        }
        $inodes = $this->inodeStats();
        if ($inodes['used_percent'] !== null) {
            $used = $inodes['used_percent'];
            $out[] = self::component('inodes', 'Inodes (file count)', $used >= 95 ? 'error' : ($used >= 85 ? 'warning' : 'ok'),
                "{$used}% of inodes used (" . number_format($inodes['used']) . ' of ' . number_format($inodes['total']) . ').',
                $used >= 85 ? 'Too many small files: clear cache and session folders, old backups and mail queues.' : null);
        }
        $fatal = $this->recentFatalErrors();
        if ($fatal !== null) {
            $out[] = $fatal['count'] === 0
                ? self::component('php_errors', 'PHP errors', 'ok', 'No fatal errors in the log in the last hour.')
                : self::component('php_errors', 'PHP errors', 'warning',
                    "{$fatal['count']} fatal error" . ($fatal['count'] === 1 ? '' : 's') . " in the last hour. Latest: {$fatal['last']}",
                    'Open the application log for the full stack trace.');
        }
        return $out;
    }

    /** Fatal errors logged in the last hour (Laravel, CodeIgniter and PHP log formats), or null without a log. */
    private function recentFatalErrors(): ?array
    {
        foreach ($this->options['logPaths'] as $path) {
            if (!$path || !is_file($path) || !is_readable($path)) {
                continue;
            }
            $since = time() - 3600;
            $count = 0;
            $last = '';
            foreach ($this->tail($path, 400) as $line) {
                if (!preg_match('/(production|local|staging)\.(ERROR|CRITICAL|EMERGENCY|ALERT)|^(CRITICAL|ERROR|EMERGENCY) - |PHP Fatal error|Uncaught /', $line)) {
                    continue;
                }
                if (preg_match('/(\d{4}-\d{2}-\d{2})[ T](\d{2}:\d{2}:\d{2})/', substr($line, 0, 60), $m)) {
                    $at = strtotime($m[1] . ' ' . $m[2]);
                    if ($at !== false && $at < $since) {
                        continue;
                    }
                }
                $count++;
                $last = trim(preg_replace('/^\[[^\]]+\]\s*/', '', $line));
            }
            return ['count' => $count, 'last' => mb_substr($last, 0, 140)];
        }
        return null;
    }

    /** Base checks plus the framework's own (database, queue, scheduler...). */
    public function getComponents(): array
    {
        $components = $this->baseComponents();
        if (is_callable($this->options['components'])) {
            foreach ((array) ($this->options['components'])() as $c) {
                if (is_array($c) && isset($c['id'], $c['status'])) {
                    $components[] = $c;
                }
            }
        }
        return $components;
    }

    /**
     * Handle one request. Returns [httpStatus, data] so each framework can send it its own way.
     */
    public function handle(string $endpoint, ?string $authorizationHeader): array
    {
        $key = $this->options['apiKey'];
        if ($key === '') {
            return [503, ['error' => 'WEBMONITOR_API_KEY is not set on this site.']];
        }
        // A short or placeholder key would be guessable: refuse to run with it
        if (strlen($key) < 16 || stripos($key, 'paste-') === 0) {
            return [503, ['error' => 'WEBMONITOR_API_KEY is too short or still the placeholder. Use a long random key.']];
        }
        $token = preg_match('/^Bearer\s+(.+)$/i', (string) $authorizationHeader, $m) ? trim($m[1]) : '';
        if (!hash_equals($this->options['apiKey'], $token)) {
            return [401, ['error' => 'Invalid API key. Please check your authorization header.']];
        }
        if (!in_array($endpoint, self::ENDPOINTS, true)) {
            return [404, ['error' => 'Unknown endpoint. Available: ' . implode(', ', self::ENDPOINTS)]];
        }
        $method = 'get' . ucfirst($endpoint);
        try {
            return [200, $this->$method()];
        } catch (\Throwable $e) {
            return [500, ['error' => 'Web Monitor check failed: ' . $e->getMessage()]];
        }
    }

    /** One batch of the suspicious-file scan over the site's base path (read-only). */
    public function getFilescan(): array
    {
        @set_time_limit(30);
        return (new FileScanner((string) $this->options['basePath']))->step();
    }

    /** Grouped errors (fatal errors and uncaught exceptions) recorded by ErrorTracker. */
    public function getErrors(): array
    {
        $summary = ErrorTracker::summary();
        return ['tracked' => $summary['tracked'], 'summary' => $summary, 'groups' => ErrorTracker::groups()];
    }

    public function getHealth(): array
    {
        $start = microtime(true);
        $memory = $this->memoryStats();
        $components = $this->getComponents();
        $status = 'healthy';
        $message = 'Site is healthy';
        // The site answered, so a failing component makes it "needs attention" rather than down.
        foreach (['error', 'warning'] as $level) {
            foreach ($components as $c) {
                if ($c['status'] === $level) {
                    $status = 'warning';
                    $message = $c['name'] . ': ' . $c['message'];
                    break 2;
                }
            }
        }
        return [
            'status' => $status,
            'message' => $message,
            'timestamp' => date('c'),
            'response_time' => round((microtime(true) - $start) * 1000, 2),
            'php_version' => PHP_VERSION,
            'framework' => $this->options['framework'],
            'framework_version' => $this->options['frameworkVersion'],
            'integration_version' => self::VERSION,
            'security_config' => self::securityConfig(),
            'errors' => ErrorTracker::summary(),
            'uptime_seconds' => $this->systemUptimeSeconds(),
            'checks' => [
                'memory_usage' => $memory['usage_percent'] === null ? null : $memory['usage_percent'] . '%',
                'memory_limit' => ini_get('memory_limit'),
                'load_average' => $this->loadAverage(),
            ],
            'components' => $components,
        ];
    }

    public function getAnalytics(): array
    {
        // Visitors, page views, bounce rate and sessions need an analytics source (GA4, Matomo...).
        return [
            'totalVisitors' => null,
            'pageViews' => null,
            'bounceRate' => null,
            'avgSessionDuration' => null,
            'analytics_source' => 'unavailable',
            'analytics_note' => 'Connect an analytics provider for visitor data. Server-side PHP cannot measure visits on its own.',
            'top_pages' => [],
            'content_stats' => [],
        ];
    }

    public function getPerformance(): array
    {
        $start = microtime(true);
        $memory = $this->memoryStats();
        $cpu = $this->cpuUsagePercent();
        $disk = $this->diskStats();
        $inodes = $this->inodeStats();
        $traffic = $this->trafficStats();
        $responseTime = round((microtime(true) - $start) * 1000, 2);

        $score = 100;
        $recommendations = [];
        if ($memory['usage_percent'] !== null && $memory['usage_percent'] > 80) {
            $score -= 15;
            $recommendations[] = 'PHP memory usage is high — raise memory_limit or reduce memory use';
        }
        if ($cpu !== null && $cpu > 80) {
            $score -= 20;
            $recommendations[] = 'Server CPU load is high — optimize heavy work or add capacity';
        }
        if ($disk['free_percent'] !== null && $disk['free_percent'] < 10) {
            $score -= 15;
            $recommendations[] = 'Less than 10% disk space left on the server';
        }
        if (!$this->opcacheEnabled()) {
            $score -= 10;
            $recommendations[] = 'Enable OPcache for faster PHP execution';
        }

        return [
            'load_time' => $responseTime,
            'server_response_time' => $responseTime,
            'database_query_time' => null,
            'memory_usage' => $memory['usage_percent'],
            'memory_peak' => round(memory_get_peak_usage(true) / 1048576, 2),
            'cpu_usage' => $cpu,
            'disk_free_percent' => $disk['free_percent'],
            'disk_free_bytes' => $disk['free_bytes'],
            'disk_total_bytes' => $disk['total_bytes'],
            'inodes_used_percent' => $inodes['used_percent'],
            'inodes_total' => $inodes['total'],
            'bandwidth_tx_bytes' => $traffic['tx_bytes'],
            'bandwidth_rx_bytes' => $traffic['rx_bytes'],
            'opcache_enabled' => $this->opcacheEnabled(),
            'performance_score' => max(0, $score),
            'active_plugins' => null,
            'recommendations' => $recommendations,
        ];
    }

    public function getSeo(): array
    {
        $page = $this->fetchHomepage();
        if ($page['html'] === null) {
            return [
                'score' => null,
                'ssl_enabled' => $this->isHttps(),
                'seo_issues' => [],
                'recommendations' => [],
                'note' => 'Homepage could not be read for analysis: ' . $page['error'],
            ];
        }
        $html = $page['html'];
        $issues = [];
        $recommendations = [];
        $score = 100;

        $title = preg_match('/<title[^>]*>(.*?)<\/title>/is', $html, $m) ? trim(html_entity_decode(strip_tags($m[1]))) : '';
        $description = preg_match('/<meta[^>]+name=["\']description["\'][^>]*content=["\']([^"\']*)["\']/i', $html, $m)
            || preg_match('/<meta[^>]+content=["\']([^"\']*)["\'][^>]*name=["\']description["\']/i', $html, $m)
            ? trim(html_entity_decode($m[1])) : '';
        $h1Count = preg_match_all('/<h1[\s>]/i', $html);
        $images = preg_match_all('/<img\b[^>]*>/i', $html, $imgTags);
        $imagesWithAlt = 0;
        foreach ($imgTags[0] as $tag) {
            if (preg_match('/\balt=["\'][^"\']+["\']/i', $tag)) {
                $imagesWithAlt++;
            }
        }
        $hasLang = (bool) preg_match('/<html[^>]+\blang=["\'][^"\']+["\']/i', $html);
        $hasCanonical = (bool) preg_match('/<link[^>]+rel=["\']canonical["\']/i', $html);
        [$internal, $external] = $this->countLinks($html);

        if ($title === '') { $score -= 20; $issues[] = 'Homepage has no <title>'; $recommendations[] = 'Add a descriptive page title'; }
        elseif (mb_strlen($title) > 65) { $score -= 5; $issues[] = 'Title is longer than 65 characters'; }
        if ($description === '') { $score -= 15; $issues[] = 'Homepage has no meta description'; $recommendations[] = 'Add a meta description'; }
        if ($h1Count === 0) { $score -= 10; $issues[] = 'Homepage has no <h1> heading'; $recommendations[] = 'Add one <h1> heading'; }
        elseif ($h1Count > 1) { $score -= 5; $issues[] = "Homepage has {$h1Count} <h1> headings"; }
        if ($images > $imagesWithAlt) { $score -= 10; $issues[] = ($images - $imagesWithAlt) . " of {$images} images have no alt text"; $recommendations[] = 'Add alt text to images'; }
        if (!$hasLang) { $score -= 5; $issues[] = '<html> has no lang attribute'; }
        if (!$hasCanonical) { $score -= 5; $issues[] = 'No canonical link'; }
        if (!$this->isHttps()) { $score -= 10; $issues[] = 'Site is not served over HTTPS'; $recommendations[] = 'Enable SSL certificate'; }

        return [
            'score' => max(0, $score),
            'meta_tags' => ($title !== '' && $description !== '') ? 'complete' : 'incomplete',
            'alt_tags' => $images > 0 ? round($imagesWithAlt / $images * 100) : null,
            'heading_structure' => $h1Count === 1 ? 'good' : 'needs_improvement',
            'internal_links' => $internal,
            'external_links' => $external,
            'ssl_enabled' => $this->isHttps(),
            'site_title' => $title,
            'site_description' => $description,
            'total_content' => null,
            'seo_issues' => $issues,
            'recommendations' => $recommendations,
            'analyzed_url' => $page['url'],
        ];
    }

    public function getSecurity(): array
    {
        $checks = [];
        $recommendations = [];
        $add = function (string $name, ?bool $passed, string $advice) use (&$checks, &$recommendations) {
            $checks[$name] = $passed;
            if ($passed === false) {
                $recommendations[] = $advice;
            }
        };

        $add('https', $this->isHttps(), 'Enable SSL certificate (serve the site over HTTPS)');
        $add('supported_php', version_compare(PHP_VERSION, '8.2.0', '>='), 'Upgrade PHP to a supported version (8.2 or newer)');
        $add('debug_mode_off', $this->options['debug'] === null ? null : !$this->options['debug'], 'Turn off debug mode in production');
        $add('display_errors_off', !filter_var(ini_get('display_errors'), FILTER_VALIDATE_BOOLEAN), 'Set display_errors=Off in production');
        $add('expose_php_off', !filter_var(ini_get('expose_php'), FILTER_VALIDATE_BOOLEAN), 'Set expose_php=Off so the PHP version is not advertised');

        $headers = $this->fetchHomepage()['headers'];
        if ($headers !== null) {
            $add('hsts_header', isset($headers['strict-transport-security']), 'Send a Strict-Transport-Security header');
            $add('nosniff_header', isset($headers['x-content-type-options']), 'Send X-Content-Type-Options: nosniff');
            $add('frame_protection', isset($headers['x-frame-options']) || isset($headers['content-security-policy']), 'Protect against clickjacking (X-Frame-Options or CSP)');
        }

        $known = array_filter($checks, fn ($v) => $v !== null);
        $passed = count(array_filter($known));

        return [
            'ssl_status' => $this->isHttps() ? 'enabled' : 'disabled',
            'firewall' => 'unknown',
            'malware_scan' => 'not_available',
            'vulnerability_scan' => 'not_available',
            'security_score' => count($known) ? (int) round($passed / count($known) * 100) : null,
            'security_checks' => $checks,
            'security_plugins' => null,
            'total_plugins' => null,
            'outdated_plugins' => null,
            'php_version' => PHP_VERSION,
            'framework' => $this->options['framework'],
            'framework_version' => $this->options['frameworkVersion'],
            'recommendations' => $recommendations,
        ];
    }

    public function getPackages(): array
    {
        $base = rtrim($this->options['basePath'], '/');
        $composerJson = $this->readJson($base . '/composer.json');
        if ($composerJson === null) {
            return ['total_packages' => 0, 'all_packages' => [], 'message' => 'composer.json not found'];
        }
        // Installed versions come from composer.lock when present
        $lock = $this->readJson($base . '/composer.lock') ?? [];
        $installed = [];
        foreach (array_merge($lock['packages'] ?? [], $lock['packages-dev'] ?? []) as $pkg) {
            $installed[$pkg['name']] = ltrim($pkg['version'], 'v');
        }
        $format = function (array $deps, bool $dev) use ($installed) {
            $out = [];
            foreach ($deps as $name => $constraint) {
                $out[] = [
                    'name' => $name,
                    'version' => $installed[$name] ?? null,
                    'constraint' => $constraint,
                    'isDev' => $dev,
                    'type' => $dev ? 'devDependency' : 'dependency',
                    'outdated' => null,
                ];
            }
            return $out;
        };
        $deps = $format($composerJson['require'] ?? [], false);
        $devDeps = $format($composerJson['require-dev'] ?? [], true);
        return [
            'total_packages' => count($deps) + count($devDeps),
            'dependencies_count' => count($deps),
            'devDependencies_count' => count($devDeps),
            'dependencies' => $deps,
            'devDependencies' => $devDeps,
            'all_packages' => array_merge($deps, $devDeps),
            'outdated_count' => null,
            'php_version' => PHP_VERSION,
            'framework_version' => $this->options['frameworkVersion'],
        ];
    }

    public function getLogs(): array
    {
        foreach ($this->options['logPaths'] as $path) {
            if ($path && is_file($path) && is_readable($path)) {
                $lines = $this->tail($path, 100);
                return [
                    'error_log' => [
                        'enabled' => true,
                        'message' => '',
                        'lines' => array_map(fn ($raw) => [
                            'raw' => $raw,
                            // Laravel: "[2026-10-23 09:38:36] ...", CodeIgniter: "CRITICAL - 2026-10-23 09:38:36 --> ..."
                            'time' => preg_match('/\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}:\d{2}/', substr($raw, 0, 40), $m) ? $m[0] : '',
                        ], $lines),
                        'count' => count($lines),
                    ],
                    'access_summary' => new \stdClass(),
                ];
            }
        }
        return [
            'error_log' => ['enabled' => false, 'message' => 'No readable log file found.', 'lines' => [], 'count' => 0],
            'access_summary' => new \stdClass(),
        ];
    }

    public function getRecommendations(): array
    {
        $all = [];
        foreach (['performance' => $this->getPerformance(), 'seo' => $this->getSeo(), 'security' => $this->getSecurity()] as $source => $data) {
            foreach ($data['recommendations'] ?? [] as $text) {
                $all[] = ['text' => $text, 'source' => $source];
            }
        }
        return ['recommendations' => $all, 'count' => count($all)];
    }

    // ---- measurements -------------------------------------------------------------------------

    private function memoryStats(): array
    {
        $limit = $this->toBytes((string) ini_get('memory_limit'));
        $usage = memory_get_usage(true);
        return ['usage_percent' => $limit > 0 ? round($usage / $limit * 100, 2) : null];
    }

    private function loadAverage(): ?float
    {
        $load = function_exists('sys_getloadavg') ? sys_getloadavg() : false;
        return $load ? round($load[0], 2) : null;
    }

    // 1-minute load average divided by CPU cores, as a percentage
    private function cpuUsagePercent(): ?float
    {
        $load = $this->loadAverage();
        if ($load === null) {
            return null;
        }
        $cores = 1;
        if (is_readable('/proc/cpuinfo')) {
            $cores = max(1, substr_count((string) file_get_contents('/proc/cpuinfo'), "\nprocessor") + 1);
        }
        return round(min(100, $load / $cores * 100), 2);
    }

    /**
     * Inode use of the filesystem holding the site. PHP has no native call for it, so `df -Pi` is used where the host
     * allows shell commands; otherwise nothing is reported (never a guess).
     */
    private function inodeStats(): array
    {
        $none = ['used_percent' => null, 'used' => null, 'total' => null];
        if (!function_exists('shell_exec') || in_array('shell_exec', array_map('trim', explode(',', (string) ini_get('disable_functions'))), true)) {
            return $none;
        }
        $out = @shell_exec('df -Pi ' . escapeshellarg((string) $this->options['basePath']) . ' 2>/dev/null');
        if (!is_string($out) || !preg_match('/\n\S+\s+(\d+)\s+(\d+)\s+(\d+)\s+\d+%/', $out, $m) || (int) $m[1] === 0) {
            return $none;
        }
        $total = (int) $m[1];
        $used = (int) $m[2];
        return ['used_percent' => round($used / $total * 100, 1), 'used' => $used, 'total' => $total];
    }

    private function diskStats(): array
    {
        $base = $this->options['basePath'];
        $free = @disk_free_space($base);
        $total = @disk_total_space($base);
        if ($free === false || $total === false || $total <= 0) {
            return ['free_bytes' => null, 'total_bytes' => null, 'free_percent' => null];
        }
        return [
            'free_bytes' => (int) $free,
            'total_bytes' => (int) $total,
            'free_percent' => round($free / $total * 100, 1),
        ];
    }

    // Bytes sent and received on real interfaces since the server last booted.
    // This is not the hosting plan's monthly quota, which PHP cannot read.
    private function trafficStats(): array
    {
        $empty = ['tx_bytes' => null, 'rx_bytes' => null];
        if (!is_readable('/proc/net/dev')) {
            return $empty;
        }
        $lines = file('/proc/net/dev', FILE_IGNORE_NEW_LINES);
        if ($lines === false) {
            return $empty;
        }
        $tx = 0;
        $rx = 0;
        $found = false;
        foreach ($lines as $line) {
            if (!str_contains($line, ':')) {
                continue;
            }
            [$iface, $rest] = explode(':', $line, 2);
            if (trim($iface) === '' || trim($iface) === 'lo') {
                continue;
            }
            $cols = preg_split('/\s+/', trim($rest));
            if (!is_array($cols) || count($cols) < 9) {
                continue;
            }
            $rx += (int) $cols[0];
            $tx += (int) $cols[8];
            $found = true;
        }
        return $found ? ['tx_bytes' => $tx, 'rx_bytes' => $rx] : $empty;
    }

    /**
     * PHP settings that decide whether some vulnerabilities apply (WebMonitor's Release Radar uses them to say
     * "exposed" or "not exposed"). Values only, never paths or secrets.
     */
    public static function securityConfig(): array
    {
        $on = static function ($v): bool {
            return in_array(strtolower(trim((string) $v)), ['1', 'on', 'true', 'yes'], true);
        };
        return [
            'session.upload_progress.enabled' => $on(ini_get('session.upload_progress.enabled')),
            'session.serialize_handler' => substr((string) ini_get('session.serialize_handler'), 0, 40),
            'expose_php' => $on(ini_get('expose_php')),
            'display_errors' => $on(ini_get('display_errors')),
            'allow_url_include' => $on(ini_get('allow_url_include')),
        ];
    }

    private function systemUptimeSeconds(): ?int
    {
        if (is_readable('/proc/uptime')) {
            return (int) floatval((string) file_get_contents('/proc/uptime'));
        }
        return null;
    }

    private function opcacheEnabled(): bool
    {
        return function_exists('opcache_get_status') && (bool) (@opcache_get_status(false)['opcache_enabled'] ?? false);
    }

    private function isHttps(): bool
    {
        if (!empty($this->options['siteUrl'])) {
            return stripos($this->options['siteUrl'], 'https://') === 0;
        }
        return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || strtolower($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
    }

    private function siteUrl(): string
    {
        if (!empty($this->options['siteUrl'])) {
            return rtrim($this->options['siteUrl'], '/');
        }
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        return ($this->isHttps() ? 'https://' : 'http://') . $host;
    }

    // Fetch the homepage once per request (for SEO and security-header checks)
    private function fetchHomepage(): array
    {
        if ($this->homepage !== null) {
            return $this->homepage;
        }
        $url = $this->siteUrl() . '/';
        $context = stream_context_create([
            'http' => ['timeout' => 5, 'ignore_errors' => true, 'header' => "User-Agent: WebMonitor/1.0\r\n"],
            'ssl' => ['verify_peer' => true],
        ]);
        $html = @file_get_contents($url, false, $context);
        if ($html === false) {
            return $this->homepage = ['url' => $url, 'html' => null, 'headers' => null, 'error' => 'request failed'];
        }
        $headers = [];
        foreach ($http_response_header ?? [] as $line) {
            if (strpos($line, ':') !== false) {
                [$name, $value] = explode(':', $line, 2);
                $headers[strtolower(trim($name))] = trim($value);
            }
        }
        return $this->homepage = ['url' => $url, 'html' => $html, 'headers' => $headers, 'error' => null];
    }

    private function countLinks(string $html): array
    {
        $host = parse_url($this->siteUrl(), PHP_URL_HOST);
        $internal = 0;
        $external = 0;
        preg_match_all('/<a\b[^>]*href=["\']([^"\'#]+)["\']/i', $html, $m);
        foreach ($m[1] as $href) {
            $linkHost = parse_url($href, PHP_URL_HOST);
            if ($linkHost === null || $linkHost === $host) {
                $internal++;
            } elseif (preg_match('/^https?:/i', $href)) {
                $external++;
            }
        }
        return [$internal, $external];
    }

    private function readJson(string $path): ?array
    {
        if (!is_file($path)) {
            return null;
        }
        $data = json_decode((string) file_get_contents($path), true);
        return is_array($data) ? $data : null;
    }

    private function tail(string $path, int $lines): array
    {
        $file = new \SplFileObject($path, 'r');
        $file->seek(PHP_INT_MAX);
        $last = $file->key();
        $out = [];
        for ($i = max(0, $last - $lines); $i <= $last; $i++) {
            $file->seek($i);
            $line = rtrim((string) $file->current(), "\r\n");
            if ($line !== '') {
                $out[] = $line;
            }
        }
        return $out;
    }

    private function toBytes(string $value): int
    {
        $value = trim($value);
        if ($value === '' || $value === '-1') {
            return -1; // unlimited
        }
        $number = (int) $value;
        switch (strtolower(substr($value, -1))) {
            case 'g': return $number * 1073741824;
            case 'm': return $number * 1048576;
            case 'k': return $number * 1024;
            default: return $number;
        }
    }
}

/**
 * Error tracking for Web Monitor (1.3.0): fatal errors and uncaught exceptions, grouped by fingerprint
 * (type + file + line + message shape), with the top stack frames. Installed with ErrorTracker::install():
 * it only adds a shutdown function, so the site's own error handling and error pages are unchanged.
 * Frameworks that catch exceptions call ErrorTracker::recordThrowable() from their report hook.
 * Stored in one small JSON file (at most 100 groups); messages are cleaned of emails, tokens and passwords.
 */
final class ErrorTracker
{
    private const MAX_GROUPS = 100;
    private const FATAL = E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR | E_USER_ERROR | E_RECOVERABLE_ERROR;
    private static bool $installed = false;
    private static ?string $file = null;
    private static string $base = '';

    public static function install(?string $storeFile = null, ?string $basePath = null): void
    {
        self::configure($storeFile, $basePath);
        if (self::$installed) {
            return;
        }
        self::$installed = true;
        register_shutdown_function(static function (): void {
            $e = error_get_last();
            if ($e && ($e['type'] & self::FATAL)) {
                [$message, $trace] = self::splitUncaught((string) $e['message']);
                self::record(self::typeName((int) $e['type'], $message), $message, (string) $e['file'], (int) $e['line'], $trace);
            }
        });
    }

    public static function configure(?string $storeFile = null, ?string $basePath = null): void
    {
        self::$file = $storeFile ?: rtrim(sys_get_temp_dir(), '/\\') . '/webmonitor-errors-' . substr(sha1(__DIR__), 0, 12) . '.json';
        self::$base = rtrim((string) ($basePath ?: ($_SERVER['DOCUMENT_ROOT'] ?? getcwd() ?: '')), '/\\');
    }

    public static function recordThrowable(\Throwable $t): void
    {
        if (self::$file === null) {
            self::configure();
        }
        $frames = [];
        foreach (array_slice($t->getTrace(), 0, 10) as $f) {
            $frames[] = self::rel((string) ($f['file'] ?? '[internal]')) . ':' . ($f['line'] ?? 0) . ' ' . ($f['class'] ?? '') . ($f['type'] ?? '') . ($f['function'] ?? '');
        }
        self::record(get_class($t), $t->getMessage(), $t->getFile(), $t->getLine(), $frames);
    }

    public static function record(string $type, string $message, string $file, int $line, array $trace = []): void
    {
        try {
            $path = self::$file ?? '';
            if ($path === '') {
                return;
            }
            $fh = @fopen($path, 'c+');
            if (!$fh) {
                return;
            }
            if (!flock($fh, LOCK_EX)) {
                fclose($fh);
                return;
            }
            $raw = stream_get_contents($fh);
            $data = json_decode($raw ?: '{}', true) ?: [];
            $groups = $data['groups'] ?? [];
            $minutes = $data['minutes'] ?? [];
            $now = time();
            $clean = self::clean($message);
            $rel = self::rel($file);
            $shape = preg_replace(['/\d+/', "/'[^']*'|\"[^\"]*\"/"], ['N', 'S'], $clean);
            $fp = substr(sha1($type . '|' . $rel . '|' . $line . '|' . $shape), 0, 16);
            $where = substr(strtok((string) ($_SERVER['REQUEST_URI'] ?? (PHP_SAPI === 'cli' ? 'cli' : '')), '?') ?: '', 0, 120);
            $g = $groups[$fp] ?? ['fp' => $fp, 'type' => substr($type, 0, 80), 'message' => substr($clean, 0, 300), 'file' => $rel, 'line' => $line,
                'where' => $where, 'stack' => array_map([self::class, 'clean'], array_slice($trace, 0, 10)), 'count' => 0, 'first_seen' => $now];
            $g['count']++;
            $g['last_seen'] = $now;
            $g['where'] = $where ?: $g['where'];
            $groups[$fp] = $g;
            if (count($groups) > self::MAX_GROUPS) {
                uasort($groups, static fn($a, $b) => $b['last_seen'] <=> $a['last_seen']);
                $groups = array_slice($groups, 0, self::MAX_GROUPS, true);
            }
            $minute = (string) intdiv($now, 60);
            $minutes[$minute] = ($minutes[$minute] ?? 0) + 1;
            $minutes = array_filter($minutes, static fn($k) => (int) $k > intdiv($now, 60) - 60, ARRAY_FILTER_USE_KEY);
            ftruncate($fh, 0);
            rewind($fh);
            fwrite($fh, json_encode(['groups' => $groups, 'minutes' => $minutes], JSON_UNESCAPED_SLASHES));
            fflush($fh);
            flock($fh, LOCK_UN);
            fclose($fh);
        } catch (\Throwable $ignored) {
            // Error tracking must never break the site.
        }
    }

    /** The small summary that goes into every health response. */
    public static function summary(): array
    {
        $data = self::load();
        if ($data === null) {
            return ['tracked' => self::$installed, 'groups' => 0, 'last_5m' => 0, 'last_hour' => 0, 'latest' => null];
        }
        $now = intdiv(time(), 60);
        $last5 = 0;
        $hour = 0;
        foreach ($data['minutes'] ?? [] as $m => $n) {
            if ((int) $m > $now - 60) $hour += $n;
            if ((int) $m > $now - 5) $last5 += $n;
        }
        $latest = 0;
        foreach ($data['groups'] ?? [] as $g) $latest = max($latest, (int) ($g['last_seen'] ?? 0));
        return ['tracked' => true, 'groups' => count($data['groups'] ?? []), 'last_5m' => $last5, 'last_hour' => $hour, 'latest' => $latest ? gmdate('c', $latest) : null];
    }

    /** Every group, newest first, for the errors endpoint. */
    public static function groups(): array
    {
        $groups = array_values((self::load() ?? [])['groups'] ?? []);
        usort($groups, static fn($a, $b) => ($b['last_seen'] ?? 0) <=> ($a['last_seen'] ?? 0));
        return array_map(static function ($g) {
            $g['first_seen'] = gmdate('c', (int) $g['first_seen']);
            $g['last_seen'] = gmdate('c', (int) $g['last_seen']);
            return $g;
        }, $groups);
    }

    private static function load(): ?array
    {
        if (self::$file === null) {
            self::configure();
        }
        if (!is_file(self::$file)) {
            return null;
        }
        $data = json_decode((string) @file_get_contents(self::$file), true);
        return is_array($data) ? $data : null;
    }

    private static function rel(string $file): string
    {
        if (self::$base !== '' && strpos($file, self::$base) === 0) {
            $file = ltrim(substr($file, strlen(self::$base)), '/\\');
        }
        return substr($file, -200);
    }

    /** Emails, long tokens, passwords and query strings out of anything stored. */
    public static function clean(string $s): string
    {
        $s = preg_replace('/[\w.+-]+@[\w-]+\.[\w.-]+/', '[email]', $s);
        $s = preg_replace('/(pass(word)?|pwd|secret|token|api[_-]?key|authorization)(["\']?\s*[:=]\s*)[^\s,;&"\']+/i', '$1$3[redacted]', $s);
        $s = preg_replace('/\b[A-Za-z0-9_\-]{32,}\b/', '[token]', $s);
        return (string) $s;
    }

    /** "Uncaught X: msg in file:line\nStack trace:\n#0 ..." → message and frames. */
    private static function splitUncaught(string $message): array
    {
        $parts = preg_split('/\nStack trace:\n/', $message, 2);
        $frames = [];
        if (isset($parts[1])) {
            foreach (array_slice(explode("\n", $parts[1]), 0, 11) as $line) {
                if (strpos(trim($line), 'thrown') === 0) continue;
                $frames[] = self::rel(preg_replace('/^#\d+\s+/', '', trim($line)));
            }
        }
        $msg = preg_replace('/ in [^\n]+:\d+$/', '', trim($parts[0]));
        return [preg_replace('/^(?:PHP )?Uncaught [\w\\\\]+: /', '', $msg), $frames];
    }

    private static function typeName(int $type, string $message): string
    {
        if (preg_match('/^(?:PHP )?Uncaught ([\w\\\\]+)/', $message, $m)) {
            return $m[1];
        }
        return [E_ERROR => 'Fatal error', E_PARSE => 'Parse error', E_CORE_ERROR => 'Core error', E_COMPILE_ERROR => 'Compile error',
            E_USER_ERROR => 'User error', E_RECOVERABLE_ERROR => 'Recoverable error'][$type] ?? 'Error';
    }
}

/**
 * Suspicious-file scan for Web Monitor (1.4.0). Read-only: files are read, never executed, changed or deleted.
 * Finds PHP where uploads belong, known backdoor / web-shell code, request input reaching eval / shell functions,
 * heavy obfuscation, and injected JavaScript loaders, each with file, line, rule and a short snippet.
 * Works in small batches (time and file budget per call) and remembers where it stopped; a full pass then starts
 * again, skipping files whose size and modification time did not change. State lives in one JSON file.
 */
final class FileScanner
{
    private const MAX_FILE_BYTES = 2000000;
    private const MAX_FINDINGS = 200;
    private const SKIP_DIRS = ['.git', 'node_modules', 'vendor', 'cache', '.cache', 'storage/framework', 'wp-content/upgrade', 'wp-content/cache', 'wp-content/backups', 'wp-content/updraft'];
    private const PHP_EXT = ['php', 'phtml', 'php3', 'php4', 'php5', 'php7', 'php8', 'phar', 'inc', 'pht'];
    private const JS_EXT = ['js', 'html', 'htm'];
    // Upload folders, from the site root only (a plugin's own files/ or images/ folder is not an uploads folder).
    private const UPLOAD_DIRS = ['wp-content/uploads', 'uploads', 'storage/app/public', 'public/storage', 'public/uploads', 'writable/uploads'];

    /** [id, severity, title, PHP regex] — tested per line. */
    private const RULES = [
        ['eval-decode', 'critical', 'eval() of decoded or compressed data (typical injected backdoor)', '/\b(eval|assert)\s*\(\s*(@?\s*)?(base64_decode|gzinflate|gzuncompress|gzdecode|str_rot13|strrev|rawurldecode|hex2bin|convert_uudecode)\s*\(/i'],
        ['request-exec', 'critical', 'Request input passed to code or shell execution', '/\b(eval|assert|system|exec|shell_exec|passthru|popen|proc_open|pcntl_exec|create_function)\s*\(\s*[^;]{0,80}\$_(GET|POST|REQUEST|COOKIE|SERVER\[\s*[\'"]HTTP_)/i'],
        ['request-callable', 'critical', 'Function name taken from request input (remote code execution)', '/\$_(GET|POST|REQUEST|COOKIE)\s*\[[^\]]{1,40}\]\s*\(\s*\$_(GET|POST|REQUEST|COOKIE)/i'],
        ['preg-e', 'critical', 'preg_replace with the /e modifier (executes the replacement)', '/preg_replace\s*\(\s*[\'"](.).*\1[a-z]*e[a-z]*[\'"]\s*,/i'],
        ['webshell-sig', 'critical', 'Known web-shell signature', '/(FilesMan|WSO\s*[0-9.]+|c99sh(ell)?|r57shell|b374k|IndoXploit|Alfa\s*Shell|AnonymousFox|Mini\s*Shell|uname\s+-a.{0,40}passthru)/i'],
        ['write-php-from-request', 'high', 'Writes a .php file from request input (dropper)', '/(file_put_contents|fwrite|fputs)\s*\([^;]{0,120}\.php[\'"][^;]{0,120}\$_(GET|POST|REQUEST|FILES)/i'],
        ['hex-eval', 'high', 'Hex- or chr()-encoded eval / assert', '/(\\\\x65\\\\x76\\\\x61\\\\x6c|chr\s*\(\s*101\s*\)\s*\.\s*chr\s*\(\s*118\s*\)|"\\\\x61\\\\x73\\\\x73\\\\x65\\\\x72\\\\x74")/i'],
        ['remote-include', 'high', 'Includes code from a URL or request input', '/\b(include|require)(_once)?\s*\(?\s*([\'"]https?:\/\/|\$_(GET|POST|REQUEST|COOKIE))/i'],
    ];
    private const JS_RULES = [
        ['js-injected-loader', 'high', 'Injected JavaScript loader (decoded and executed at runtime)', '/(eval\s*\(\s*(String\.fromCharCode|atob|unescape)\s*\(|document\.write\s*\(\s*unescape\s*\(|new\s+Function\s*\(\s*atob\s*\()/i'],
    ];

    private string $base;
    private string $state;

    public function __construct(string $basePath, ?string $stateFile = null)
    {
        $this->base = rtrim($basePath, '/\\');
        $this->state = $stateFile ?: rtrim(sys_get_temp_dir(), '/\\') . '/webmonitor-filescan-' . substr(sha1($this->base), 0, 12) . '.json';
    }

    /** One batch: scans up to $maxFiles files or $seconds of work, then returns progress and every open finding. */
    public function step(int $maxFiles = 400, float $seconds = 3.0): array
    {
        $start = microtime(true);
        $s = $this->load();
        $cursor = (int) ($s['cursor'] ?? 0);
        if ($cursor === 0) {
            $s['cycle_started'] = time();
            $s['seen'] = [];
            $s['pending'] = [];
        }
        $seenThisCycle = $s['seen'] ?? [];
        $index = 0;
        $scanned = 0;
        $done = true;
        foreach ($this->walk() as $rel => $info) {
            if ($index++ < $cursor) {
                continue;
            }
            if ($scanned >= $maxFiles || (microtime(true) - $start) > $seconds) {
                $done = false;
                $index--;
                break;
            }
            $scanned++;
            $sig = $info['size'] . ':' . $info['mtime'];
            $seenThisCycle[$rel] = 1;
            if (($s['files'][$rel] ?? null) === $sig) {
                continue; // unchanged since the last full pass: its findings (if any) still stand
            }
            $s['files'][$rel] = $sig;
            foreach (array_keys($s['findings'] ?? []) as $id) {
                if (($s['findings'][$id]['path'] ?? '') === $rel) unset($s['findings'][$id]);
            }
            foreach ($this->scanFile($rel, $info) as $f) {
                if (count($s['findings'] ?? []) < self::MAX_FINDINGS) $s['findings'][$f['id']] = $f;
            }
        }
        $s['seen'] = $seenThisCycle;
        $s['cursor'] = $done ? 0 : $index;
        if ($done) {
            // Files that disappeared during the pass are forgotten, with their findings.
            foreach (array_keys($s['files'] ?? []) as $rel) {
                if (!isset($seenThisCycle[$rel])) {
                    unset($s['files'][$rel]);
                    foreach (array_keys($s['findings'] ?? []) as $id) if (($s['findings'][$id]['path'] ?? '') === $rel) unset($s['findings'][$id]);
                }
            }
            $s['cycle_completed'] = time();
            $s['files_total'] = count($seenThisCycle);
            $s['seen'] = [];
        }
        $this->save($s);
        return [
            'scanner' => 'webmonitor-filescan/1',
            'progress' => ['files_done' => $done ? count($seenThisCycle) : $index, 'files_total' => $s['files_total'] ?? null, 'cycle_complete' => $done,
                'cycle_started' => isset($s['cycle_started']) ? gmdate('c', (int) $s['cycle_started']) : null,
                'cycle_completed' => isset($s['cycle_completed']) ? gmdate('c', (int) $s['cycle_completed']) : null],
            'findings' => array_values($s['findings'] ?? []),
        ];
    }

    /** Relative path => size / mtime for every candidate file, in a stable order. */
    private function walk(): \Generator
    {
        $self = realpath(__FILE__);
        $stack = [''];
        while ($stack) {
            $dir = array_pop($stack);
            $abs = $this->base . ($dir === '' ? '' : '/' . $dir);
            $entries = @scandir($abs);
            if ($entries === false) continue;
            $subdirs = [];
            foreach ($entries as $e) {
                if ($e === '.' || $e === '..') continue;
                $rel = $dir === '' ? $e : $dir . '/' . $e;
                $path = $abs . '/' . $e;
                if (is_link($path)) continue;
                if (is_dir($path)) {
                    if (!$this->skipDir($rel)) $subdirs[] = $rel;
                    continue;
                }
                $ext = strtolower(pathinfo($e, PATHINFO_EXTENSION));
                if (!in_array($ext, self::PHP_EXT, true) && !in_array($ext, self::JS_EXT, true)) continue;
                // The scanner's own file contains the signatures it looks for: skip exactly that file (by real path).
                if ($self !== false && realpath($path) === $self) continue;
                $st = @stat($path);
                if (!$st || $st['size'] > self::MAX_FILE_BYTES) continue;
                yield $rel => ['size' => $st['size'], 'mtime' => $st['mtime'], 'ext' => $ext];
            }
            rsort($subdirs); // popped in ascending order
            foreach ($subdirs as $d) $stack[] = $d;
        }
    }

    private function skipDir(string $rel): bool
    {
        foreach (self::SKIP_DIRS as $skip) {
            if ($rel === $skip || substr($rel, -strlen('/' . $skip)) === '/' . $skip) return true;
        }
        return false;
    }

    private function inUploads(string $rel): bool
    {
        foreach (self::UPLOAD_DIRS as $d) {
            if (strpos($rel, $d . '/') === 0) return true;
        }
        return false;
    }

    /** Findings for one file. Pure apart from reading it. */
    public function scanFile(string $rel, array $info): array
    {
        $out = [];
        $isPhp = in_array($info['ext'], self::PHP_EXT, true);
        $add = function (string $rule, string $sev, string $title, int $line, string $text) use (&$out, $rel, $info) {
            $id = substr(sha1($rel . '|' . $rule . '|' . preg_replace('/\s+/', ' ', trim($text))), 0, 16);
            $out[$id] = ['id' => $id, 'path' => $rel, 'line' => $line, 'rule' => $rule, 'severity' => $sev, 'title' => $title,
                'snippet' => self::snippet($text), 'mtime' => gmdate('c', (int) $info['mtime']), 'size' => (int) $info['size']];
        };
        if ($isPhp && $this->inUploads($rel) && basename($rel) !== 'index.php') {
            $add('php-in-uploads', 'critical', 'PHP file in an uploads folder (uploads should never contain code)', 1, (string) @file_get_contents($this->base . '/' . $rel, false, null, 0, 200));
        }
        $fh = @fopen($this->base . '/' . $rel, 'rb');
        if (!$fh) return array_values($out);
        $n = 0;
        while (($line = fgets($fh, 1048576)) !== false) {
            $n++;
            $len = strlen($line);
            if ($isPhp && $len > 5000 && preg_match('/(eval|assert|base64_decode|gzinflate|str_rot13|\$\w+\s*\(\s*\$\w+)/i', $line) && preg_match('/[A-Za-z0-9+\/=]{2000,}/', $line)) {
                $add('obfuscated', 'high', 'Heavily obfuscated code (long encoded payload next to decode/exec calls)', $n, $line);
            }
            if ($len > 20000) continue; // minified / generated: only the obfuscation check above applies
            foreach (self::matchLine($line, $isPhp) as [$id, $sev, $title]) $add($id, $sev, $title, $n, $line);
            if (count($out) >= 20) break;
        }
        fclose($fh);
        return array_values($out);
    }

    /** Rules matching one line: [[id, severity, title], ...]. Pure (used by scanFile and by tests). */
    public static function matchLine(string $line, bool $php = true): array
    {
        $hits = [];
        foreach ($php ? self::RULES : self::JS_RULES as [$id, $sev, $title, $re]) {
            if (preg_match($re, $line)) $hits[] = [$id, $sev, $title];
        }
        return $hits;
    }

    /** At most 160 characters around the match, with keys, tokens and long payloads shortened. */
    private static function snippet(string $text): string
    {
        $t = preg_replace('/[A-Za-z0-9+\/=]{60,}/', '[encoded payload]', trim($text));
        $t = ErrorTracker::clean((string) $t);
        return substr(preg_replace('/\s+/', ' ', (string) $t), 0, 160);
    }

    private function load(): array
    {
        $d = is_file($this->state) ? json_decode((string) @file_get_contents($this->state), true) : null;
        return is_array($d) ? $d : [];
    }

    private function save(array $s): void
    {
        @file_put_contents($this->state, json_encode($s, JSON_UNESCAPED_SLASHES), LOCK_EX);
        @chmod($this->state, 0600);
    }
}
