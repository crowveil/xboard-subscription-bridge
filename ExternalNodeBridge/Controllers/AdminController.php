<?php

namespace Plugin\ExternalNodeBridge\Controllers;

use App\Http\Controllers\Controller;
use App\Models\ServerGroup;
use Illuminate\Http\Request;
use Plugin\ExternalNodeBridge\Services\{BridgeException, ConsoleAccess, Converter, Diagnostics, Metadata, Refresher, Report, Settings};

final class AdminController extends Controller
{
    private function action(callable $fn, bool $gated = true)
    {
        try {
            $response = $gated ? ConsoleAccess::withOpen(fn ($state) => $fn(Settings::load(), $state)) : $fn(Settings::load());
            return $response->header('Cache-Control', 'private, no-store');
        } catch (BridgeException $e) {
            return response()->json(['error' => $e->reason], $e->reason === 'CONSOLE_CLOSED' ? 403 : 422)->header('Cache-Control', 'no-store');
        } catch (\Illuminate\Validation\ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            (new Diagnostics(['debug' => false]))->record('ADMIN_OPERATION_FAILED', Diagnostics::errorSite($e), true);
            return response()->json(['error' => 'ADMIN_OPERATION_FAILED'], 500)->header('Cache-Control', 'no-store');
        }
    }

    public function settings()
    {
        return $this->action(function ($c, $state) {
            unset($c['cache_revision']);
            return response()->json(['config' => $c, 'targets' => Settings::TARGETS, 'groups' => ServerGroup::query()->orderBy('id')->get(['id', 'name']), 'debug_active' => (new Diagnostics($c))->active(), 'access' => $state]);
        });
    }

    public function session()
    {
        return $this->action(fn ($c) => response()->json(['access' => ConsoleAccess::status(), 'version' => Metadata::version(),
            'summary' => ['sources' => count($c['sources']), 'enabled_sources' => count(array_filter($c['sources'], fn ($s) => $s['enabled'])), 'converter_url' => $c['converter_url']]]), false);
    }

    public function renew()
    {
        return $this->action(fn ($c) => response()->json(['access' => ConsoleAccess::renew()]), false);
    }

    public function close()
    {
        return $this->action(function ($c) {
            ConsoleAccess::status(false, true);
            return response()->json(['ok' => true]);
        }, false);
    }

    public function save(Request $request)
    {
        return $this->action(function ($old) use ($request) {
            $request->validate(['config' => 'required|array']);
            $incoming = $request->input('config');
            if (!is_array($incoming['sources'] ?? null)) {
                throw new BridgeException('CONFIG_INVALID');
            }
            $byId = array_column($old['sources'], null, 'id');
            foreach ($incoming['sources'] as &$source) {
                if (!is_array($source)) {
                    throw new BridgeException('CONFIG_INVALID');
                }
                if (empty($source['id'])) {
                    $source['id'] = bin2hex(random_bytes(8));
                    $source['prefix'] ??= '['.($source['name'] ?? '外部').']';
                } elseif (!isset($byId[$source['id']])) {
                    throw new BridgeException('SOURCE_ID_INVALID');
                }
            }
            unset($source);
            unset($incoming['cache_revision'], $incoming['debug'], $incoming['debug_until']);
            $c = Settings::normalize(array_replace($old, $incoming));
            $groups = ServerGroup::query()->pluck('id')->map(fn ($id) => (string) $id)->all();
            foreach ($c['sources'] as $source) {
                if (array_diff($source['group_ids'], $groups)) {
                    throw new BridgeException('GROUP_NOT_FOUND');
                }
            }
            Settings::save($c);
            (new Diagnostics($c))->record('CONFIG_SAVED', ['count' => count($c['sources'])]);
            return response()->json(['ok' => true]);
        });
    }

    public function debug(Request $request)
    {
        return $this->action(function ($c) use ($request) {
            $request->validate(['enabled' => 'required|boolean']);
            $c['debug'] = $request->boolean('enabled');
            $c['debug_until'] = $c['debug'] ? time() + 3600 : 0;
            Settings::save($c);
            (new Diagnostics(array_replace($c, ['debug' => true])))->record($c['debug'] ? 'DEBUG_ENABLED' : 'DEBUG_DISABLED');
            return response()->json(['ok' => true, 'debug_active' => $c['debug'], 'debug_until' => $c['debug_until']]);
        });
    }

    public function status()
    {
        return $this->action(fn ($c) => response()->json(Report::make($c))->header('Cache-Control', 'no-store'));
    }

    public function export()
    {
        return $this->action(fn ($c) => response()->json(Report::make($c), 200, [
            'Cache-Control' => 'no-store', 'Content-Disposition' => 'attachment; filename="external-node-bridge-diagnostics.json"',
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }

    public function health()
    {
        return $this->action(fn ($c) => response()->json((new Converter($c))->version(true)));
    }

    public function refresh(Request $request)
    {
        return $this->action(function ($c) use ($request) {
            $request->validate(['source_id' => 'required|string|max:32', 'target' => 'required|in:'.implode(',', Settings::TARGETS)]);
            $source = collect($c['sources'])->firstWhere('id', $request->input('source_id'));
            if (!$source) {
                throw new BridgeException('SOURCE_NOT_FOUND');
            }
            $state = Refresher::create($c)->refresh($source, $request->input('target'), true);
            return response()->json(['ok' => empty($state['error']), 'state' => $state]);
        });
    }
}
