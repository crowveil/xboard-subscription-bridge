<?php

namespace Plugin\ExternalNodeBridge\Commands;

use Illuminate\Console\Command;
use Plugin\ExternalNodeBridge\Services\{BridgeException, Refresher, Report, Settings};

final class BridgeCommand extends Command
{
    protected $signature = 'external-nodes:manage {action=status : status|refresh|diagnose} {--source=} {--target=} {--force}';
    protected $description = 'Refresh external subscriptions or export credential-free diagnostics';

    public function handle(): int
    {
        try {
            $c = Settings::load();
            if (in_array($this->argument('action'), ['status', 'diagnose'], true)) {
                $report = Report::make($c);
                if ($this->argument('action') === 'status') {
                    unset($report['events']);
                }
                $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
                return 0;
            }
            if ($this->argument('action') !== 'refresh') {
                throw new BridgeException('ACTION_INVALID');
            }
            $refresher = Refresher::create($c);
            $matched = false;
            $failed = false;
            foreach ($c['sources'] as $source) {
                if ($this->option('source') && $source['id'] !== $this->option('source')) {
                    continue;
                }
                if (!$source['enabled'] || !$source['group_ids']) {
                    continue;
                }
                foreach ($source['targets'] as $target) {
                    if ($this->option('target') && $target !== $this->option('target')) {
                        continue;
                    }
                    $matched = true;
                    $result = $refresher->refresh($source, $target, (bool) $this->option('force'));
                    $failed = $failed || !empty($result['error']);
                    $this->line(json_encode($result));
                }
            }
            if (!$matched) {
                throw new BridgeException('NO_ACTIVE_SOURCE_MATCHED');
            }
            return $failed ? 1 : 0;
        } catch (\Throwable $e) {
            $this->error($e instanceof BridgeException ? $e->reason : 'COMMAND_FAILED');
            return 1;
        }
    }
}
