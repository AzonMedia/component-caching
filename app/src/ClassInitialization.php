<?php
declare(strict_types=1);

namespace GuzabaPlatform\RequestCaching;

use Guzaba2\Base\Base;
use Guzaba2\Event\Event;
use Guzaba2\Kernel\Interfaces\ClassInitializationInterface;
use Guzaba2\Mvc\ExecutorMiddleware;
use Guzaba2\Orm\ActiveRecord;
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
        $CachingMiddleware = new CachingMiddleware();
        $MiddlwareCallback = static function(Event $Event) use ($CachingMiddleware) : void
        {
            $Middlewares = $Event->get_subject();
            $Middlewares->add($CachingMiddleware, ExecutorMiddleware::class);
        };
        $Events->add_class_callback(Middlewares::class, '_after_setup', $MiddlwareCallback);


        //in multiworker environment $CachingMiddleware must rely on swoole table (or other shared memory mechanism)
        //we also need the _before_read to catch any classes that were attempted to be read but were not found (or no permissions)
        $Events->add_class_callback(ActiveRecord::class, '_before_read', [$CachingMiddleware, 'active_record_read_event_handler']);
        $Events->add_class_callback(ActiveRecord::class, '_after_read', [$CachingMiddleware, 'active_record_read_event_handler']);


        //this would work only when running with a single worker
        //$Events->add_class_callback(ActiveRecord::class, '_after_save', [$CachingMiddleware, 'active_record_update_event_handler']);
        //$Events->add_class_callback(ActiveRecord::class, '_after_delete', [$CachingMiddleware, 'active_record_update_event_handler']);

    }
}