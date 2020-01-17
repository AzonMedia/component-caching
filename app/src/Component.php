<?php
declare(strict_types=1);

namespace GuzabaPlatform\RequestCaching;

use Guzaba2\Base\Base;
use Guzaba2\Event\Event;
use Guzaba2\Mvc\Controller;
use Guzaba2\Mvc\ExecutorMiddleware;
use Guzaba2\Orm\ActiveRecord;
use GuzabaPlatform\Components\Base\Interfaces\ComponentInitializationInterface;
use GuzabaPlatform\Components\Base\Interfaces\ComponentInterface;
use GuzabaPlatform\Components\Base\Traits\ComponentTrait;
use GuzabaPlatform\Platform\Admin\Controllers\Navigation;
use GuzabaPlatform\Platform\Application\Middlewares;
use GuzabaPlatform\RequestCaching\Hooks\AdminEntry;

/**
 * Class Component
 * @package Azonmedia\Tags
 */
class Component extends Base implements ComponentInterface, ComponentInitializationInterface
{

    protected const CONFIG_DEFAULTS = [
        'services' => [
            'Events',
            'FrontendRouter',
        ],
    ];

    protected const CONFIG_RUNTIME = [];

    use ComponentTrait;

    protected const COMPONENT_NAME = "Request Caching";
    //https://components.platform.guzaba.org/component/{vendor}/{component}
    protected const COMPONENT_URL = 'https://components.platform.guzaba.org/component/guzaba-platform/request-caching';
    //protected const DEV_COMPONENT_URL//this should come from composer.json
    protected const COMPONENT_NAMESPACE = 'GuzabaPlatform\\RequestCaching';
    protected const COMPONENT_VERSION = '0.0.1';//TODO update this to come from the Composer.json file of the component
    protected const VENDOR_NAME = 'Azonmedia';
    protected const VENDOR_URL = 'https://azonmedia.com';

    private const INITIALIZATION_METHODS = ['register_middleware', 'register_routes'];


    public static function run_all_initializations() : array
    {
        foreach (self::INITIALIZATION_METHODS as $method_name) {
            self::$method_name();
        }
        return self::INITIALIZATION_METHODS;
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

    /**
     * @throws \Guzaba2\Base\Exceptions\RunTimeException
     */
    public static function register_routes() : void
    {
        $meta = ['in_navigation' => TRUE];
        self::get_service('FrontendRouter')->add_route('/admin/request-caching','@GuzabaPlatform.RequestCaching/Admin.vue', 'Request Caching', $meta);
    }
}