<?php

namespace Plugin\ExternalNodeBridge\Services;

use App\Http\Controllers\V1\Client\ClientController;
use App\Services\Plugin\HookManager;
use Illuminate\Http\Request;

final class SubscriptionBridge
{
    public static function handle(array $servers, mixed $user, Request $request): array
    {
        // This hook is after Client middleware and UserService::isAvailable.
        // Request-local guard only; do not retain users in an Octane singleton.
        if ($request->attributes->get('external_node_bridge.running', false)) {
            return $servers;
        }
        $response = null;
        $log = null;
        $ctx = ['trace' => bin2hex(random_bytes(8)), 'user_ref' => Diagnostics::userRef($user->id)];
        try {
            $c = Settings::load();
            $log = new Diagnostics($c);
            $flag = strtolower($request->input('flag') ?? $request->header('User-Agent', ''));
            $class = app('protocols.manager')->matchProtocolClassName($flag) ?? '';
            $target = Merger::target($class);
            if (!$target) {
                $log->record('TARGET_PASSTHROUGH', $ctx);
                return $servers;
            }
            $ctx['target'] = $target;
            $sources = Settings::authorized($c, $user->group_id, $target);
            $log->record('AUTHORIZED_SOURCES', $ctx + ['count' => count($sources)]);
            $cache = new NodeCache($c, $log);
            $batches = [];
            foreach ($sources as $source) {
                $e = $cache->read($source, $target);
                $usable = $cache->usable($e);
                $log->record('CACHE_LOOKUP', $ctx + ['source_id' => $source['id'], 'state' => $usable ? 'hit' : 'miss', 'count' => $usable ? count($e['nodes']) : 0]);
                if ($usable) {
                    $batches[] = ['source_id' => $source['id'], 'prefix' => $source['prefix'], 'nodes' => $e['nodes']];
                }
            }
            if (!$batches) {
                return $servers;
            }
            $request->attributes->set('external_node_bridge.running', true);
            try {
                $response = app(ClientController::class)->doSubscribe($request, $user, $servers);
                if ($response->getStatusCode() !== 200) {
                    return $servers;
                }
                $result = Merger::merge($response->getContent(), $target, $batches, $c, $request->only(['types', 'filter']));
                $response->setContent($result['body']);
                // JsonResponse retains the original structured data, but setContent
                // supplies the final wire representation used by Symfony send().
                $response->headers->remove('Content-Length');
                $response->headers->remove('ETag');
                $response->headers->set('Cache-Control', 'private, no-store');
                if ($log->active()) {
                    $response->headers->set('X-External-Bridge-Trace', $ctx['trace']);
                }
                $log->record('MERGE_OK', $ctx + ['added' => $result['added'], 'skipped' => $result['skipped']]);
            } finally {
                $request->attributes->remove('external_node_bridge.running');
            }
        } catch (\App\Services\Plugin\InterceptResponseException $e) {
            throw $e; // Preserve other plugins' explicit response interception.
        } catch (\Throwable $e) {
            ($log ?? new Diagnostics(['debug' => false]))->record('BRIDGE_FALLBACK', $ctx + ['error' => $e instanceof BridgeException ? $e->reason : 'UNEXPECTED_ERROR'] + Diagnostics::errorSite($e), true);
            // If generation succeeded, use that exact original response on merge failure.
        }
        if ($response !== null) {
            HookManager::intercept($response);
        }
        return $servers;
    }
}
