<?php

require dirname(__DIR__).'/vendor/autoload.php';
require __DIR__.'/HarnessModels.php';

spl_autoload_register(function ($class) {
    if (str_starts_with($class, 'App\\')) {
        $path = __DIR__.'/upstream/app/'.str_replace('\\', '/', substr($class, 4)).'.php';
        if (is_file($path)) {
            require $path;
        }
    }
});

function admin_setting($key, $default = null)
{
    return $GLOBALS['test_settings'][$key] ?? $default;
}
function subscribe_template($name)
{
    return $GLOBALS['test_templates'][$name];
}

abstract class BridgeTestCase extends PHPUnit\Framework\TestCase
{
    protected Illuminate\Foundation\Application $app;
    protected array $settings;
    protected function setUp(): void
    {
        parent::setUp();
        $this->app = new Illuminate\Foundation\Application(dirname(__DIR__));
        $runtime = __DIR__.'/runtime/'.bin2hex(random_bytes(6));
        mkdir($runtime, 0700, true);
        $this->app->useStoragePath($runtime);
        $this->app->usePublicPath($runtime.'/public');
        $this->app->useAppPath(__DIR__.'/upstream/app');
        Illuminate\Support\Facades\Facade::clearResolvedInstances();
        Illuminate\Support\Facades\Facade::setFacadeApplication($this->app);
        $this->app->instance('request', Illuminate\Http\Request::create('/'));
        $this->app->instance('config', new Illuminate\Config\Repository([
            'app' => ['key' => 'base64:'.base64_encode(str_repeat('k', 32)), 'cipher' => 'AES-256-CBC', 'locale' => 'en'],
            'database' => ['default' => 'sqlite', 'connections' => ['sqlite' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]],
            'cache' => ['default' => 'array', 'stores' => ['array' => ['driver' => 'array']]],
            'view' => ['paths' => [], 'compiled' => $runtime],
        ]));
        foreach ([Illuminate\Events\EventServiceProvider::class, Illuminate\Filesystem\FilesystemServiceProvider::class,
            Illuminate\Database\DatabaseServiceProvider::class, Illuminate\Cache\CacheServiceProvider::class,
            Illuminate\Encryption\EncryptionServiceProvider::class, Illuminate\Routing\RoutingServiceProvider::class,
            Illuminate\View\ViewServiceProvider::class, Illuminate\Translation\TranslationServiceProvider::class,
            Illuminate\Validation\ValidationServiceProvider::class] as $provider) {
            $this->app->register($provider);
        }
        $this->app->boot();
        app('router')->get('api/v1/client/subscribe', fn () => '')->name('client.subscribe');
        app('router')->getRoutes()->refreshNameLookups();
        Illuminate\Http\Request::macro('validate', function ($rules) {
            return Illuminate\Support\Facades\Validator::make($this->all(), $rules)->validate();
        });
        $schema = Illuminate\Support\Facades\DB::connection()->getSchemaBuilder();
        $schema->create('plugins', function ($t) {
            $t->increments('id');
            $t->string('code');
            $t->boolean('is_enabled');
            $t->text('config');
            $t->timestamp('updated_at')->nullable();
            $t->string('name')->nullable();
            $t->string('version')->nullable();
            $t->string('type')->nullable();
            $t->timestamp('installed_at')->nullable();
        });
        $schema->create('users', function ($t) {
            $t->increments('id');
            $t->string('token');
            $t->integer('group_id')->nullable();
            $t->boolean('available')->default(true);
            $t->boolean('is_admin')->default(false);
            $t->string('uuid');
        });
        $schema->create('v2_server_group', function ($t) {
            $t->increments('id');
            $t->string('name');
        });
        $this->settings = Plugin\ExternalNodeBridge\Services\Settings::normalize(['debug' => true, 'sources' => [self::source('a', [1]), self::source('b', [2])]]);
        $this->saveSettings($this->settings);
        App\Models\ServerGroup::insert([['id' => 1,'name' => 'Basic'],['id' => 2,'name' => 'Premium']]);
        App\Models\User::insert([
            ['id' => 1,'token' => 'user-a-token','group_id' => 1,'available' => true,'is_admin' => false,'uuid' => 'user-a-private'],
            ['id' => 2,'token' => 'user-b-token','group_id' => 2,'available' => true,'is_admin' => false,'uuid' => 'user-b-private'],
            ['id' => 3,'token' => 'expired-token','group_id' => 1,'available' => false,'is_admin' => false,'uuid' => 'expired-private'],
        ]);
        $GLOBALS['test_settings'] = ['app_name' => 'Test', 'show_info_to_server_enable' => 0];
        $GLOBALS['test_templates'] = [
            'clashmeta' => "proxies: []\nproxy-groups:\n  - name: 🚀 节点选择\n    type: select\n    proxies: [DIRECT]\n  - name: ♻️ 自动选择\n    type: url-test\n    proxies: []\n    url: http://www.gstatic.com/generate_204\n    interval: 600\nrules: [MATCH,🚀 节点选择]\n",
            'singbox' => ['outbounds' => [['type' => 'selector','tag' => 'Select','outbounds' => ['direct']],['type' => 'direct','tag' => 'direct']], 'route' => ['rules' => []], 'dns' => ['servers' => [],'rules' => []]],
        ];
        $GLOBALS['test_templates']['clash'] = $GLOBALS['test_templates']['clashmeta'];
        $GLOBALS['test_templates']['stash'] = $GLOBALS['test_templates']['clashmeta'];
        $GLOBALS['test_templates']['surge'] = $GLOBALS['test_templates']['surfboard'] = "[General]\nloglevel = notify\n[Proxy]\n\$proxies\n[Proxy Group]\nSelect = select, \$proxy_group\n[Rule]\nFINAL,Select\n";
        $manager = new App\Support\ProtocolManager($this->app);
        $this->app->instance('protocols.manager', $manager);
        $this->app->instance('protocols.flags', $manager->getAllFlags());
        $this->app->instance('auth', new TestAuth());
        $this->app->instance(App\Services\Plugin\PluginManager::class, new class () extends App\Services\Plugin\PluginManager {
            public function __construct()
            {
                $this->pluginPath = dirname(__DIR__);
                $this->corePluginPath = __DIR__.'/missing-core';
            }
        });
        App\Services\ServerService::$calls = 0;
        (new Plugin\ExternalNodeBridge\Plugin('external_node_bridge'))->boot();
    }
    protected function tearDown(): void
    {
        Mockery::close();
        Illuminate\Support\Facades\Facade::clearResolvedInstances();
        parent::tearDown();
    }
    public static function source(string $id, array $groups): array
    {
        return ['id' => $id,'name' => $id,'url' => 'https://upstream.example/'.$id.'?token=UPSTREAM_SECRET','group_ids' => $groups,'targets' => ['mihomo','shadowrocket','singbox'],'enabled' => true,'interval' => 3600];
    }
    protected function saveSettings(array $c): void
    {
        App\Models\Plugin::updateOrCreate(['code' => 'external_node_bridge'], ['is_enabled' => true,'config' => json_encode($c)]);
        Plugin\ExternalNodeBridge\Services\PrivateStore::write('settings', Plugin\ExternalNodeBridge\Services\Settings::normalize($c));
    }
    protected function cache(string $source, string $target, array $nodes, int $age = 0): void
    {
        $s = collect($this->settings['sources'])->firstWhere('id', $source);
        $log = new Plugin\ExternalNodeBridge\Services\Diagnostics($this->settings);
        (new Plugin\ExternalNodeBridge\Services\NodeCache($this->settings, $log))->write($s, $target, ['nodes' => $nodes,'updated_at' => time() - $age,'attempted_at' => time(),'error' => null]);
    }
    protected function subscribe(string $token, string $flag = 'meta', array $extra = []): Symfony\Component\HttpFoundation\Response
    {
        $r = Illuminate\Http\Request::create('/api/v1/client/subscribe', 'GET', array_merge(['token' => $token,'flag' => $flag], $extra));
        $r->headers->set('Host', 'panel.example');
        $r->setUserResolver(fn () => app('auth')->current);
        $this->app->instance('request', $r);
        // Laravel converts PHP warnings to ErrorException; match that behavior.
        set_error_handler(function ($severity, $message, $file, $line) {
            throw new ErrorException($message, 0, $severity, $file, $line);
        }, E_WARNING);
        try {
            return (new App\Http\Middleware\Client())->handle($r, fn ($req) => (new App\Http\Controllers\V1\Client\ClientController())->subscribe($req));
        } catch (App\Services\Plugin\InterceptResponseException $e) {
            return $e->getResponse();
        } finally {
            restore_error_handler();
        }
    }
    public static function node(string $name = 'HK', string $password = 'EXTERNAL_PASSWORD'): array
    {
        return ['name' => $name,'type' => 'trojan','server' => 'node.example','port' => 443,'password' => $password,'sni' => 'tls.example'];
    }
}

final class TestAuth
{
    public mixed $current = null;
    public mixed $admin = null;
    public function setUser($user)
    {
        $this->current = $user;
    }
    public function guard($name)
    {
        return new class ($this->admin) {
            public function __construct(private $u)
            {
            }public function user()
            {
                return $this->u;
            }
        };
    }
}
