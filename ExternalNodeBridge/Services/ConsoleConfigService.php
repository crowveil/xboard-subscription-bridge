<?php

namespace Plugin\ExternalNodeBridge\Services;

use App\Models\Plugin;
use App\Services\Plugin\PluginConfigService;
use Illuminate\Http\Request;

/** Adds presentation-only information; all persistence stays with XBoard. */
final class ConsoleConfigService extends PluginConfigService
{
    public function __construct(private readonly PluginConfigService $inner)
    {
    }

    public function getConfig(string $pluginCode): array
    {
        $config = $this->inner->getConfig($pluginCode);
        if ($pluginCode !== Settings::CODE || !isset($config['console_open']) ||
            !Plugin::where('code', Settings::CODE)->where('is_enabled', true)->exists()) {
            return $config;
        }

        // Resolve per call: Octane workers and the container may outlive a request.
        $request = app()->bound('request') ? app('request') : null;
        if (!$request instanceof Request) {
            return $config;
        }

        try {
            // Use the admin request's origin, not app_url / ASSET_URL, which may
            // point to a different frontend or CDN and lose the admin login.
            // Scheme/host/port are interpreted by XBoard's TrustProxies middleware.
            $root = $request->getSchemeAndHttpHost();
            if ((bool) admin_setting('force_https', false)) {
                $root = preg_replace('/^http:/', 'https:', $root);
            }
            $url = $root.rtrim($request->getBasePath(), '/').'/plugins/'.Settings::CODE.'/console.html';
            $config['console_open']['description'] = '开启并保存后，复制此完整地址到浏览器打开管理控制台：'
                .$url.' 。仍需同域 XBoard 管理员登录。到期或关闭后停止管理操作，订阅分发继续运行。';
        } catch (\Throwable) {
            // A malformed host must not break the native configuration form.
            return $config;
        }

        return $config;
    }

    public function updateConfig(string $pluginCode, array $config): bool
    {
        return $this->inner->updateConfig($pluginCode, $config);
    }

    public function getDbConfig(string $pluginCode): array
    {
        return $this->inner->getDbConfig($pluginCode);
    }
}
