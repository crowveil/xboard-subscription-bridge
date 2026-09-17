<?php

use App\Models\Plugin as PluginRow;
use Illuminate\Support\Facades\{Crypt, DB, File};

/** Explicit one-time prototype-to-public release migration. No forged version. */
final class ReleaseInstaller
{
    public function __construct(private string $source, private string $destination, private string $public)
    {
    }

    private function row(): PluginRow
    {
        return PluginRow::query()->where('code', 'external_node_bridge')->firstOrFail();
    }
    private static function raw(PluginRow $row): array
    {
        return is_array($row->config) ? $row->config : json_decode($row->config ?: '{}', true, 64, JSON_THROW_ON_ERROR);
    }
    private function stateDir(): string
    {
        return storage_path('app/external-node-bridge');
    }
    private function privateOwner(): array
    {
        $path = is_dir($this->stateDir()) ? $this->stateDir() : storage_path('app');
        return ['uid' => fileowner($path),'gid' => filegroup($path)];
    }
    private function protectPrivate(array $owner): void
    {
        $paths = [$this->stateDir(),...array_map(fn ($f) => $f->getPathname(), File::allFiles($this->stateDir(), true))];
        foreach ($paths as $path) {
            if (is_link($path)) {
                throw new RuntimeException('私有状态目录中不能包含符号链接。');
            }
            if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
                if (fileowner($path) !== $owner['uid'] && !chown($path, $owner['uid'])) {
                    throw new RuntimeException('无法保留私有文件所属用户。');
                }
                if (filegroup($path) !== $owner['gid'] && !chgrp($path, $owner['gid'])) {
                    throw new RuntimeException('无法保留私有文件所属组。');
                }
            }
            if (!chmod($path, is_dir($path) ? 0700 : 0600)) {
                throw new RuntimeException('无法保留私有文件权限。');
            }
        }
    }

    public function inspect(): array
    {
        $row = $this->row();
        $manifest = json_decode(file_get_contents($this->source.'/config.json'), true, 64, JSON_THROW_ON_ERROR);
        $release = json_decode(file_get_contents(dirname(__DIR__, 2).'/ExternalNodeBridge/config.json'), true, 64, JSON_THROW_ON_ERROR);
        $old = json_decode(file_get_contents($this->destination.'/config.json'), true, 64, JSON_THROW_ON_ERROR);
        if (($manifest['code'] ?? '') !== 'external_node_bridge' || ($old['code'] ?? '') !== 'external_node_bridge' || $manifest['version'] !== $release['version']) {
            throw new RuntimeException('插件标识或发布版本不匹配。');
        }
        if (!in_array($row->version, ['0.1.0','0.1.1'], true)) {
            throw new RuntimeException('仅允许迁移原型版 0.1.0 / 0.1.1。');
        }
        if (realpath($this->source) === realpath($this->destination)) {
            throw new RuntimeException('请从独立解压目录执行迁移，不能覆盖源码后再运行。');
        }
        $owner = $this->privateOwner();
        if (function_exists('posix_geteuid') && !in_array(posix_geteuid(), [0,$owner['uid']], true)) {
            throw new RuntimeException('请使用原私有状态目录所属用户或 root 执行迁移。');
        }
        return ['installed' => $row->version,'release' => $manifest['version'],'enabled' => (bool)$row->is_enabled,'destination' => $this->destination];
    }

    public function apply(): string
    {
        $this->inspect();
        $root = storage_path('app/external-node-bridge-backups');
        File::ensureDirectoryExists($root, 0700);
        chmod($root, 0700);
        $backup = $root.'/'.gmdate('Ymd-His').'-'.bin2hex(random_bytes(3));
        mkdir($backup, 0700);
        $row = $this->row();
        $raw = self::raw($row);
        $snapshot = ['version' => $row->version,'name' => $row->name,'config' => $raw,'is_enabled' => (bool)$row->is_enabled,'public_existed' => is_dir($this->public),'private_existed' => is_dir($this->stateDir()),'private_owner' => $this->privateOwner()];
        self::write($backup.'/database.enc', Crypt::encryptString(json_encode($snapshot, JSON_THROW_ON_ERROR)));
        $this->copy($this->destination, $backup.'/plugin');
        if ($snapshot['public_existed']) {
            $this->copy($this->public, $backup.'/public');
        }
        if ($snapshot['private_existed']) {
            $this->copy($this->stateDir(), $backup.'/private');
        }
        self::write($backup.'/ready', 'complete');
        $legacy = json_decode(file_get_contents($this->destination.'/config.json'), true, 64, JSON_THROW_ON_ERROR);
        $defaults = array_map(fn ($field) => $field['default'], $legacy['config'] ?? []);
        try {
            DB::transaction(function () use ($row, $raw, $defaults, $snapshot) {
                File::ensureDirectoryExists($this->stateDir(), 0700);
                if (!is_file($this->stateDir().'/settings.state')) {
                    self::write($this->stateDir().'/settings.state', Crypt::encryptString(json_encode(array_replace($defaults, $raw), JSON_THROW_ON_ERROR)));
                }
                self::write($this->stateDir().'/console.state', Crypt::encryptString(json_encode(['expires_at' => 0])));
                $this->protectPrivate($snapshot['private_owner']);
                $this->replace($this->source, $this->destination);
                $this->replace($this->source.'/resources/assets', $this->public);
                $manifest = json_decode(file_get_contents($this->source.'/config.json'), true, 64, JSON_THROW_ON_ERROR);
                $row->version = $manifest['version'];
                $row->name = $manifest['name'];
                $row->config = json_encode(['converter_url' => $raw['converter_url'] ?? $defaults['converter_url'] ?? 'http://subconverter-extended:25500','console_open' => false]);
                $row->save();
            });
        } catch (Throwable $e) {
            $this->restore($backup);
            throw new RuntimeException('迁移失败，已恢复备份；请检查目录权限。备份：'.$backup, 0, $e);
        }
        return $backup;
    }

    public function restore(string $backup): void
    {
        $root = realpath(storage_path('app/external-node-bridge-backups'));
        $path = realpath($backup);
        if (!$root || !$path || !str_starts_with($path, $root.DIRECTORY_SEPARATOR) || !is_file($path.'/database.enc') || !is_file($path.'/ready')) {
            throw new RuntimeException('备份目录无效或尚未完整写入。');
        }
        $data = json_decode(Crypt::decryptString(file_get_contents($path.'/database.enc')), true, 64, JSON_THROW_ON_ERROR);
        $this->replace($path.'/plugin', $this->destination);
        if ($data['public_existed']) {
            $this->replace($path.'/public', $this->public);
        } else {
            File::deleteDirectory($this->public);
        }
        if ($data['private_existed']) {
            $this->replace($path.'/private', $this->stateDir());
            $this->protectPrivate($data['private_owner']);
        } else {
            File::deleteDirectory($this->stateDir());
        }
        $row = $this->row();
        $row->version = $data['version'];
        $row->name = $data['name'];
        $row->config = json_encode($data['config']);
        $row->is_enabled = $data['is_enabled'];
        $row->save();
    }

    private function replace(string $from, string $to): void
    {
        if (is_dir($to) && !File::deleteDirectory($to)) {
            throw new RuntimeException('无法替换插件目录。');
        }
        $this->copy($from, $to);
    }
    private function copy(string $from, string $to): void
    {
        if (!File::copyDirectory($from, $to)) {
            throw new RuntimeException('目录复制失败。');
        }
    }
    private static function write(string $file, string $data): void
    {
        if (file_put_contents($file, $data) === false) {
            throw new RuntimeException('无法写入迁移状态。');
        }chmod($file, 0600);
    }
}
