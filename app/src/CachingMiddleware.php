<?php
declare(strict_types=1);

namespace GuzabaPlatform\RequestCaching;


use Guzaba2\Base\Base;
use Guzaba2\Coroutine\Coroutine;
use Guzaba2\Event\Event;
use Guzaba2\Kernel\Kernel;
use Guzaba2\Orm\ActiveRecord;
use Guzaba2\Orm\MetaStore\NullMetaStore;
use Guzaba2\Orm\MetaStore\SwooleTable;
use Guzaba2\Orm\Store\Store;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Guzaba2\Orm\Store\Interfaces\StoreInterface;
use Psr\Log\LogLevel;

/**
 * Class CachingMiddleware
 * Uses the OrmMetaStore service to access the modification times of the objects
 * @package GuzabaPlatform\RequestCaching
 */
class CachingMiddleware extends Base implements MiddlewareInterface
{
    protected const CONFIG_DEFAULTS = [
        'services' => [
            'OrmMetaStore',
        ],
    ];

    protected const CONFIG_RUNTIME = [];

    private array $cache = [];

    private iterable $routes_to_cache = [];

    /**
     * CachingMiddleware constructor.
     * @param array $do_not_cache
     */
    public function __construct(iterable $routes_to_cache = [])
    {
        $this->routes_to_cache = $routes_to_cache;
        // TODO implement - check the cache only for the routes that are to be cached
    }

    /**
     *
     *
     * @param ServerRequestInterface $Request
     * @param RequestHandlerInterface $Handler
     * @return ResponseInterface
     * @throws \Guzaba2\Base\Exceptions\RunTimeException
     */
    public function process(ServerRequestInterface $Request, RequestHandlerInterface $Handler) : ResponseInterface
    {


        //this is a very basic implementation - just checks are there any updated ORM objects since the last run
        //in future this should store the ORM objects used in each request and then check these individually and was there a new object of this type

        $path = $Request->getUri()->getPath();
        $method = strtoupper($Request->getMethod());
        $MetaStore = self::get_service('OrmMetaStore');
        //cache only GET and OPTIONS methods
        if (in_array($method, ['GET', 'OPTIONS'] )) {
            if (isset($this->cache[$path][$method])) {
                //check were any of the user ORM objects updated
                //including were there any new classes of the used ones created
                $cache_ok = TRUE;
                foreach($this->cache[$path][$method]['used_instances'] as $class => $instance_data) {
                    foreach ($instance_data as $object_lookup_index=>$last_update_microtime) {
                        $object_lookup_index = (string) $object_lookup_index;//TODO - check why is this cast needed - it should already be string
                        $primary_index = Store::restore_primary_index($class, $object_lookup_index);
                        $store_last_update_microtime = $MetaStore->get_last_update_time($class, $primary_index);
                        //if the object has been deleted or updated
                        if (!$store_last_update_microtime || $last_update_microtime < $store_last_update_microtime) {
                            $cache_ok = FALSE;
                            //break;//do not break - update the data instead
                        }
                        $this->cache[$path][$method]['used_instances'][$class][$object_lookup_index] = $store_last_update_microtime;
                    }
                }
                //of the existing objects nothing was updated or deleted... lets check is there any new object
                //if ($cache_ok) {
                foreach($this->cache[$path][$method]['used_classes'] as $class => $class_last_update_microtime) {
                    $store_class_last_update_microtime = $MetaStore->get_class_last_update_time($class);
                    //there should be always data for the provided class but in case there isnt invalidate the cache
                    if (!$store_class_last_update_microtime || $class_last_update_microtime < $store_class_last_update_microtime) {
                        $cache_ok = FALSE;
                        //break;//do not break - update the data instead
                    }
                    $this->cache[$path][$method]['used_classes'][$class] = $store_class_last_update_microtime;
                }
                //}

                if ($cache_ok) {
                    Kernel::log(sprintf('%s: Request is cached and served by the CachingMiddleware.', __CLASS__, $class, current($primary_index)), LogLevel::DEBUG);
                    return $this->cache[$path][$method]['response'];
                }

            }
        }

        $Response = $Handler->handle($Request);

        $this->cache[$path][$method]['response'] = $Response;

        return $Response;
    }


    public function active_record_read_event_handler(Event $Event) : void
    {
        $Request = Coroutine::getRequest();
        $path = $Request->getUri()->getPath();
        $method = strtoupper($Request->getMethod());
        /**
         * @var ActiveRecord
         */
        $Subject = $Event->get_subject();
        $subject_class = get_class($Subject);
        $subject_id = $Subject->get_id();
        $MetaStore = self::get_service('OrmMetaStore');
        $subject_lookup_index = (string) Store::form_lookup_index($Subject->get_primary_index());//cant form the primary index back from the lookup index

        //if (!array_key_exists($path, $this->cache)) {
        if (!isset($this->cache[$path])) {
            $this->cache[$path] = [];
        }
        //if (!array_key_exists($method, $this->cache[$path])) {
        if (!isset($this->cache[$path][$method])) {
            $this->cache[$path][$method] = [];
        }
        //if (!array_key_exists('used_instances', $this->cache[$path][$method])) {
        if (!isset($this->cache[$path][$method]['used_instances'])) {
            $this->cache[$path][$method]['used_instances'] = [];
        }
        //if (!array_key_exists('used_classes', $this->cache[$path][$method])) {
        if (!isset($this->cache[$path][$method]['used_classes'])) {
            $this->cache[$path][$method]['used_classes'] = [];
        }

        //$subject_key = $MetaStore::get_key_by_object($Subject);
//        if (!array_key_exists($subject_key, $this->cache[$path][$method]['used_instances'])) {
//            $this->cache[$path][$method]['used_instances'][$subject_key] = $MetaStore->get_last_update_time_by_object($Subject);
//        }
        //if (!array_key_exists($subject_class, $this->cache[$path][$method]['used_instances'])) {
        if (!isset($this->cache[$path][$method]['used_instances'][$subject_class])) {
            $this->cache[$path][$method]['used_instances'][$subject_class] = [];
        }
        //if (!array_key_exists($subject_id, $this->cache[$path][$method]['used_instances'][$subject_class])) {
        if (!isset($this->cache[$path][$method]['used_instances'][$subject_class][$subject_lookup_index])) {
            $this->cache[$path][$method]['used_instances'][$subject_class][$subject_lookup_index] = $MetaStore->get_last_update_time_by_object($Subject);
        }
        //if (!array_key_exists($subject_class, $this->cache[$path][$method]['used_classes'])) {
        if (!isset($this->cache[$path][$method]['used_classes'][$subject_class])) {
            $this->cache[$path][$method]['used_classes'][$subject_class] = $MetaStore->get_class_last_update_time(get_class($Subject));
        }

    }

    /*
    public function active_record_update_event_handler(Event $Event) : void
    {

    }
    */
}