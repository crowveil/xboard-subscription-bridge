<?php

namespace Plugin\ExternalNodeBridge\Commands;

use Illuminate\Console\Command;
use Plugin\ExternalNodeBridge\Services\{BridgeException, Settings};
use Plugin\ExternalNodeBridge\Services\Upstream\AccountManager;

final class AccountCommand extends Command
{
    protected $signature = 'external-nodes:accounts {action=status : status|check|probe|resume|rotate} {--source=} {--confirm-reset}';
    protected $description = 'Inspect upstream account status or explicitly rotate one upstream subscription';

    public function handle(): int
    {
        try {
            Settings::load();
            $action = $this->argument('action');
            if ($action === 'status') {
                $data = AccountManager::all(true);
            } else {
                $id = $this->option('source');
                if (!is_string($id) || $id === '') {
                    throw new BridgeException('SOURCE_NOT_FOUND');
                }
                if ($action === 'rotate' && !$this->option('confirm-reset')) {
                    throw new BridgeException('UPSTREAM_CONFIRM_REQUIRED');
                }
                $data = AccountManager::run($id, $action);
                unset($data['config'], $data['info'], $data['revision']);
            }
            $this->line(json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            return empty($data['error']) ? 0 : 1;
        } catch (\Throwable $e) {
            $this->error($e instanceof BridgeException ? $e->reason : 'UPSTREAM_INTERNAL_ERROR');
            return 1;
        }
    }
}
