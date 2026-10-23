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
    public const VERSION = '1.0.0';
    public const ENDPOINTS = ['health', 'analytics', 'performance', 'seo', 'security', 'packages', 'logs', 'recommendations'];

    /** @var array{apiKey: string, framework: string, frameworkVersion: string, basePath: string, debug: ?bool, siteUrl: ?string, logPaths: string[]} */
    private array $options;
    private ?array $homepage = null;

    /**
     * @param array $options apiKey, framework, frameworkVersion, basePath (project root),
     *                       debug (framework debug mode, null if unknown), siteUrl, logPaths
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
        ];
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

    public function getHealth(): array
    {
        $start = microtime(true);
        $memory = $this->memoryStats();
        $status = 'healthy';
        $message = 'Site is healthy';
        if ($memory['usage_percent'] !== null && $memory['usage_percent'] >= 90) {
            $status = 'warning';
            $message = 'PHP memory usage is above 90% of memory_limit';
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
            'uptime_seconds' => $this->systemUptimeSeconds(),
            'checks' => [
                'memory_usage' => $memory['usage_percent'] === null ? null : $memory['usage_percent'] . '%',
                'memory_limit' => ini_get('memory_limit'),
                'load_average' => $this->loadAverage(),
            ],
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

    private function diskStats(): array
    {
        $base = $this->options['basePath'];
        $free = @disk_free_space($base);
        $total = @disk_total_space($base);
        return ['free_percent' => ($free && $total) ? round($free / $total * 100, 1) : null];
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
