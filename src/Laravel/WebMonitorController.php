<?php

namespace Ruyalab\WebMonitor\Laravel;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Ruyalab\WebMonitor\WebMonitorCore;

class WebMonitorController extends Controller
{
    public function handle(Request $request, string $endpoint): JsonResponse
    {
        $core = new WebMonitorCore([
            'apiKey' => (string) config('webmonitor.api_key', ''),
            'framework' => 'Laravel',
            'frameworkVersion' => app()->version(),
            'basePath' => base_path(),
            'debug' => (bool) config('app.debug'),
            'siteUrl' => config('webmonitor.site_url') ?: $request->getSchemeAndHttpHost(),
            'logPaths' => [storage_path('logs/laravel.log'), storage_path('logs/laravel-' . date('Y-m-d') . '.log')],
        ]);

        // Laravel calls the "packages" list for PHP frameworks; keep both names working
        [$status, $data] = $core->handle($endpoint === 'plugins' ? 'packages' : $endpoint, $request->header('Authorization'));

        return response()->json($data, $status, ['Cache-Control' => 'no-store']);
    }
}
