<?php
declare(strict_types=1);

namespace GuzabaPlatform\RequestCaching;

use Guzaba2\Base\Base;
use Guzaba2\Event\Event;
use Guzaba2\Kernel\Interfaces\ClassInitializationInterface;
use Guzaba2\Mvc\ExecutorMiddleware;
use GuzabaPlatform\Platform\Application\Middlewares;
use GuzabaPlatform\RequestCaching\CachingMiddleware;

/**
 * Class ClassInitialization
 * @package GuzabaPlatform\RequestCaching
 */
class ClassInitialization extends Base implements ClassInitializationInterface
{

    protected const CONFIG_DEFAULTS = [
        'services' => [
            'Events',
        ],
    ];

    protected const CONFIG_RUNTIME = [];

    public static function run_all_initializations() : array
    {
        self::register_middleware();
        return ['register_middleware'];
    }

    public static function register_middleware() : void
    {
        //this is too early
        //$CachingMiddleware = new CachingMiddleware();
        //self::get_service('Middlewares')->add($CachingMiddleware, ExecutorMiddleware::class);
        //instead rely on the events.
        $Events = self::get_service('Events');
        $Callback = static function(Event $Event) : void
        {
            $CachingMiddleware = new CachingMiddleware();
            $Middlewares = $Event->get_subject();
            $Middlewares->add($CachingMiddleware, ExecutorMiddleware::class);
        };
        $Events->add_class_callback(Middlewares::class, '_after_setup', $Callback);
    }
}