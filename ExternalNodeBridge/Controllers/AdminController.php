<?php

namespace Plugin\ExternalNodeBridge\Controllers;

use App\Http\Controllers\Controller;
use App\Models\ServerGroup;
use Illuminate\Http\Request;
use Plugin\ExternalNodeBridge\Services\{BridgeException, ConsoleAccess, Converter, Diagnostics, Metadata, Refresher, Report, Settings};
use Plugin\ExternalNodeBridge\Services\Upstream\{AccountManager, AccountStore, Notifier};

final class AdminController extends Controller
{
    private function action(callable $fn, bool $gated = true)
    {
        try {
            \Plugin\ExternalNodeBridge\Services\RuntimeCompatibility::requireSupported();
            Settings::requireEnabled();
            $response = $gated ? ConsoleAccess::withOpen(fn ($state) => $fn(Settings::load(), $state)) : $fn(Settings::load());
            return $response->header('Cache-Control', 'private, no-store');
        } catch (BridgeException $e) {
            if ($e->reason === 'PLUGIN_RESTART_REQUIRED') {
                (new Diagnostics(['debug' => false]))->record($e->reason, ['state' => 'runtime_mismatch'], true);
            }
            $status = match ($e->reason) {
                'CONSOLE_CLOSED' => 403,
                'PLUGIN_RESTART_REQUIRED' => 503,
                default => 422,
            };
            return response()->json(['error' => $e->reason], $status)->header('Cache-Control', 'no-store');
        } catch (\Illuminate\Validation\ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            $trace = bin2hex(random_bytes(8));
            $diagnostic = Diagnostics::errorSite($e);
            (new Diagnostics(['debug' => false]))->record('ADMIN_OPERATION_FAILED', ['trace' => $trace] + $diagnostic, true);
            return response()->json(['error' => 'ADMIN_OPERATION_FAILED', 'trace' => $trace, 'diagnostic' => $diagnostic], 500)->header('Cache-Control', 'no-store');
        }
    }

    public function settings()
    {
        return $this->action(function ($c, $state) {
            $revision = Settings::revision($c);
            unset($c['cache_revision']);
            return response()->json(['config' => $c, 'revision' => $revision, 'targets' => Settings::TARGETS, 'groups' => ServerGroup::query()->orderBy('id')->get(['id', 'name']), 'debug_active' => (new Diagnostics($c))->active(), 'access' => $state]);
        });
    }

    public function session()
    {
        return $this->action(fn ($c) => response()->json(['access' => ConsoleAccess::status(), 'version' => Metadata::version(), 'release' => Metadata::release(),
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
            unset($incoming['cache_revision'], $incoming['debug'], $incoming['debug_until'], $incoming['retired_provider_keys'], $incoming['remove_provider_keys']);
            $c = Settings::normalize(array_replace($old, $incoming));
            $groups = ServerGroup::query()->pluck('id')->map(fn ($id) => (string) $id)->all();
            foreach ($c['sources'] as $source) {
                if (array_diff($source['group_ids'], $groups)) {
                    throw new BridgeException('GROUP_NOT_FOUND');
                }
            }
            AccountStore::lockedMany(array_keys($byId), function () use ($byId, $c, $request) {
                $incoming = array_column($c['sources'], null, 'id');
                foreach ($byId as $id => $source) {
                    if (AccountStore::suspended(AccountStore::read($id)) && (!isset($incoming[$id]) || $incoming[$id]['url'] !== $source['url'])) {
                        throw new BridgeException('UPSTREAM_ROTATION_PENDING');
                    }
                }
                if (!$request->input('revision') && array_filter(AccountManager::all(), fn ($s) => $s['configured'])) {
                    throw new BridgeException('SETTINGS_CHANGED');
                }
                Settings::save($c, $request->input('revision'));
                foreach (array_intersect_key($incoming, $byId) as $id => $source) {
                    AccountStore::reconcileTargets($id, $source['targets']);
                }
                foreach (array_diff(array_keys($byId), array_keys($incoming)) as $removed) {
                    AccountStore::write($removed, []);
                }
            });
            (new Diagnostics($c))->record('CONFIG_SAVED', ['count' => count($c['sources'])]);
            $saved = Settings::load();
            $revision = Settings::revision($saved);
            unset($saved['cache_revision']);
            return response()->json(['ok' => true, 'revision' => $revision, 'config' => $saved]);
        });
    }

    public function debug(Request $request)
    {
        return $this->action(function ($c) use ($request) {
            $request->validate(['enabled' => 'required|boolean']);
            $revision = Settings::revision($c);
            $c['debug'] = $request->boolean('enabled');
            $c['debug_until'] = $c['debug'] ? time() + 3600 : 0;
            Settings::save($c, $revision);
            (new Diagnostics(array_replace($c, ['debug' => true])))->record($c['debug'] ? 'DEBUG_ENABLED' : 'DEBUG_DISABLED');
            return response()->json(['ok' => true, 'debug_active' => $c['debug'], 'debug_until' => $c['debug_until'], 'revision' => Settings::revision($c)]);
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

    public function preflight()
    {
        return $this->action(fn () => response()->json(\Plugin\ExternalNodeBridge\Services\DeploymentCheck::inspect(true)));
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
            return response()->json(['ok' => empty($state['error']) || $state['error'] === 'NO_COMPATIBLE_NODES', 'state' => $state]);
        });
    }

    public function accounts()
    {
        return $this->action(fn () => response()->json(['accounts' => AccountManager::all(), 'notifications' => Notifier::settings()]));
    }

    public function accountSave(Request $request)
    {
        return $this->action(function () use ($request) {
            $request->validate(['source_id' => 'required|string|max:32', 'config' => 'required|array', 'revision' => 'nullable|string|max:64']);
            $id = $request->input('source_id');
            AccountManager::source($id);
            return response()->json(['account' => AccountStore::save($id, \Plugin\ExternalNodeBridge\Services\CredentialInput::config($request), $request->input('revision'))]);
        });
    }

    public function accountAction(Request $request)
    {
        return $this->action(function () use ($request) {
            $request->validate(['source_id' => 'required|string|max:32', 'action' => 'required|in:probe,check,resume,rotate,remove', 'confirm' => 'sometimes|boolean']);
            $id = $request->input('source_id');
            AccountManager::source($id);
            $action = $request->input('action');
            if (in_array($action, ['rotate', 'remove'], true) && !$request->boolean('confirm')) {
                throw new BridgeException('UPSTREAM_CONFIRM_REQUIRED');
            }
            if ($action === 'remove') {
                AccountStore::remove($id);
                return response()->json(['ok' => true]);
            }
            $state = AccountManager::run($id, $action);
            return response()->json(['ok' => empty($state['error']), 'account' => $state]);
        });
    }

    public function notifications(Request $request)
    {
        return $this->action(function () use ($request) {
            $request->validate(['action' => 'required|in:save,test', 'config' => 'sometimes|array']);
            if ($request->input('action') === 'test') {
                Notifier::test();
            } else {
                Notifier::save($request->input('config', []));
            }
            return response()->json(['ok' => true, 'notifications' => Notifier::settings()]);
        });
    }
}
