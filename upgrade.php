<?php

// Run from the unpacked release bundle, inside the XBoard PHP container.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
$options = getopt('', ['xboard:','apply','rollback:']);
$root = realpath($options['xboard'] ?? getcwd());
if (!$root || !is_file($root.'/artisan') || !is_file($root.'/bootstrap/app.php')) {
    fwrite(STDERR, "用法：php upgrade.php --xboard=/实际/XBoard/目录 [--apply | --rollback=/备份目录]\n不带 --apply 时只检查。\n");
    exit(1);
}
try {
    require $root.'/vendor/autoload.php';
    $app = require $root.'/bootstrap/app.php';
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    require __DIR__.'/tools/migrations/ReleaseInstaller.php';
    $installer = new ReleaseInstaller(__DIR__.'/ExternalNodeBridge', $root.'/plugins/ExternalNodeBridge', public_path('plugins/external_node_bridge'));
    if (isset($options['apply']) || isset($options['rollback'])) {
        if (!$app->isDownForMaintenance()) {
            throw new RuntimeException('请先在 XBoard 目录运行 php artisan down，并暂停 scheduler / queue / Octane 等常驻进程，再执行迁移。');
        }
        if (isset($options['rollback'])) {
            $installer->restore($options['rollback']);
            echo "备份已恢复。\n";
        } else {
            $backup = $installer->apply();
            echo "已迁移至公开版 0.1.0；配置和缓存已保留。备份：".$backup."\n";
        }
        echo "重启 XBoard 常驻进程，然后运行 php artisan up；不要重新安装或卸载插件。\n";
    } else {
        echo json_encode($installer->inspect(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n检查完成；--apply 执行迁移。\n";
    }
} catch (Throwable $e) {
    fwrite(STDERR, $e instanceof RuntimeException ? $e->getMessage()."\n" : "迁移检查失败，请确认 XBoard 路径、数据库和目录权限；未输出敏感异常信息。\n");
    exit(1);
}
