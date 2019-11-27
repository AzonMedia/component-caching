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
 * Uses the OrmMetaStore service to access the modification times of the objects.
 * If a request must not be chached it should include an attribute "no_cache".
 * If a response must not be cached it must include header "pragma: no-cache" or "cache-control: no-cache".
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


    /**
     * Middleware processing method
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
            if (isset($this->cache[$path][$method]['response'])) {
                //check were any of the user ORM objects updated
                //including were there any new classes of the used ones created
                $cache_ok = TRUE;
                $any_last_update_microtime = 0;
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
                        if ($store_last_update_microtime > $any_last_update_microtime) {
                            $any_last_update_microtime = $store_last_update_microtime;
                        }
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
                    if ($store_class_last_update_microtime > $any_last_update_microtime) {
                        $any_last_update_microtime = $store_class_last_update_microtime;
                    }
                }
                //}

                if ($cache_ok) {
                    Kernel::log(sprintf('%s: Request is cached and served by the CachingMiddleware.', __CLASS__, $class, current($primary_index)), LogLevel::DEBUG);
                    $Response = $this->cache[$path][$method]['response'];
                    $any_last_update_time = (int) round( $any_last_update_microtime / 1_000_000);
                    $Response = $Response->withHeader('last-modified', gmdate('D, d M Y H:i:s ', $any_last_update_time) . 'GMT' );
                    return $Response;
                }

            }
        }

        $Response = $Handler->handle($Request);

        if ($this->response_allows_caching($Response)) {
            $this->cache[$path][$method]['response'] = $Response;
        }


        return $Response;
    }

    /**
     * The event handler for ActiveRecord:_after_read
     * @param Event $Event
     * @throws \Guzaba2\Base\Exceptions\RunTimeException
     */
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

    /**
     * Whether the Response from the next middleware allows for caching.
     * If the response contains a "pragma: no-cache" or "cache-control: no-cache" this middleware will not cache
     * @param ResponseInterface $Response
     * @return bool
     */
    private function response_allows_caching(ResponseInterface $Response) : bool
    {

        $pragma_headers = $Response->getHeader('pragma');
        foreach ($pragma_headers as $header_value) {
            if (strtolower($header_value) === 'no-cache') {
                return FALSE;
            }
        }
        $cache_control_headers = $Response->getHeader('cache-control');
        foreach ($cache_control_headers as $header_value) {
            if (strtolower($header_value) === 'no-cache') {
                return FALSE;
            }
        }
        return TRUE;
    }

    /**
     * Checks does the Request allows for caching. If the Request has an attribute "no_cache" caching will not be allowed.
     * This attribute can be set from a previous middleware
     * @param RequestInterface $Request
     * @return bool
     */
    private function request_allows_caching(RequestInterface $Request) : bool
    {
        return ! $Request->getAttribute('no_cache', FALSE);
    }

}