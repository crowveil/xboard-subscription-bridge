<?php

// Test-only database/service substitutes. Actual XBoard middleware, controller,
// protocol manager, generators and hook implementation are loaded unchanged.

namespace App\Models {
    class Plugin extends \Illuminate\Database\Eloquent\Model
    {
        protected $table = 'plugins';
        public $timestamps = false;
        protected $guarded = [];
    }
    class User extends \Illuminate\Database\Eloquent\Model
    {
        protected $table = 'users';
        public $timestamps = false;
        protected $guarded = [];
        protected $attributes = ['u' => 0,'d' => 0,'transfer_enable' => 1000000000,'expired_at' => null];
    }
    class ServerGroup extends \Illuminate\Database\Eloquent\Model
    {
        protected $table = 'v2_server_group';
        public $timestamps = false;
        protected $guarded = [];
    }
}

namespace App\Http\Controllers {class Controller extends \Illuminate\Routing\Controller
{
}}

namespace App\Exceptions {class ApiException extends \RuntimeException
{
}}

namespace App\Services {
    class UserService
    {
        public function isAvailable($user)
        {
            return (bool)$user->available;
        }
    }
    class ServerService
    {
        public static int $calls = 0;
        public static function getAvailableServers($user): array
        {
            self::$calls++;
            return [['type' => 'trojan','name' => 'Own-'.$user->id,'host' => 'own.example','port' => 443,'password' => $user->uuid,'tags' => [],'protocol_settings' => ['network' => 'tcp','tls' => 1,'tls_settings' => ['server_name' => 'own.example']]]];
        }
    }
}
