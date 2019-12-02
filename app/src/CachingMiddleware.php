<?php
declare(strict_types=1);

namespace GuzabaPlatform\RequestCaching;


use Guzaba2\Authorization\Acl\Permission;
use Guzaba2\Authorization\Role;
use Guzaba2\Base\Base;
use Guzaba2\Coroutine\Coroutine;
use Guzaba2\Event\Event;
use Guzaba2\Kernel\Kernel;
use Guzaba2\Orm\ActiveRecord;
use Guzaba2\Orm\MetaStore\NullMetaStore;
use Guzaba2\Orm\MetaStore\SwooleTable;
use Guzaba2\Orm\Store\Store;
use Psr\Http\Message\MessageInterface;
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
            'AuthorizationProvider',
            'CurrentUser',
        ],
    ];

    protected const CONFIG_RUNTIME = [];

    //data-origin header
    public const DATA_ORIGIN_CONCRETE_ORM = 1;//only the used ORM instances matter, do not check for new ones
    public const DATA_ORIGIN_GENERIC_ORM = 2;//check all classes from the used ORM instances but do not check the specific instances (as their class meta data is checked)
    public const DATA_ORIGIN_DATABASE = 4;
    public const DATA_ORIGIN_RANDOM = 8;
    public const DATA_ORIGIN_OTHER = 16;

    public const DATA_ORIGIN_MAP = [
        self::DATA_ORIGIN_CONCRETE_ORM  => ['name'  => 'orm-specific'],
        self::DATA_ORIGIN_GENERIC_ORM  => ['name'  => 'orm-generic'],
        self::DATA_ORIGIN_DATABASE  => ['name'  => 'database'],
        self::DATA_ORIGIN_RANDOM  => ['name'  => 'random'],
        self::DATA_ORIGIN_OTHER  => ['name'  => 'other'],
    ];

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

        if (self::request_allows_caching($Request)) {
            
            if (!isset($this->cache[$path])) {
                $this->cache[$path] = [];
            }
            if (!isset($this->cache[$path][$method])) {
                $this->cache[$path][$method] = [];
            }
            if (!isset($this->cache[$path][$method]['used_classes'])) {
                $this->cache[$path][$method]['used_classes'] = [];
            }
            //the classes involved in the permissions should always be checked
            if (self::uses_service('AuthorizationProvider')) {
                foreach (self::get_service('AuthorizationProvider')::get_used_active_record_classes() as $auth_class_name) {
                    $this->cache[$path][$method]['used_classes'][$auth_class_name] = $MetaStore->get_class_last_update_time($auth_class_name);
                }
            }

            if (isset($this->cache[$path][$method]['response'])) {
                //check were any of the user ORM objects updated
                //including were there any new classes of the used ones created
                $cache_ok = TRUE;
                $any_last_update_microtime = 0;

                $data_origin = self::get_data_origin($this->cache[$path][$method]['response']);

                if (count($this->cache[$path][$method]['used_instances'])) {
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
                } else {
                    //no AR instances were used in this request... which would mean that most probably the data is coming from another source
                    //direct DB query or else
                    //in either case this request should not be cached.
                    $cache_ok = FALSE;
                }

                //of the existing objects nothing was updated or deleted... lets check is there any new object
                //if ($cache_ok) {
                if ($data_origin === self::DATA_ORIGIN_GENERIC_ORM) {
                    foreach ($this->cache[$path][$method]['used_classes'] as $class => $class_last_update_microtime) {
                        $store_class_last_update_microtime = $MetaStore->get_class_last_update_time($class);
                        //there should be always data for the provided class but in case there isnt invalidate the cache
                        //no - there may be no data for the provided class if no objects were instantiated so far in this worker - for example for the Role or Permission classes
                        //if (!$store_class_last_update_microtime || $class_last_update_microtime < $store_class_last_update_microtime) {
                        if ($store_class_last_update_microtime !== NULL && $class_last_update_microtime < $store_class_last_update_microtime) {
                            $cache_ok = FALSE;
                            //break;//do not break - update the data instead
                        }
                        $this->cache[$path][$method]['used_classes'][$class] = $store_class_last_update_microtime;
                        if ($store_class_last_update_microtime > $any_last_update_microtime) {
                            $any_last_update_microtime = $store_class_last_update_microtime;
                        }
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

        if (self::response_allows_caching($Response)) {
            $this->cache[$path][$method]['response'] = $Response;
            if (!isset($this->cache[$path][$method]['used_instances'])) {
                $this->cache[$path][$method]['used_instances'] = [];
            }
            if (!isset($this->cache[$path][$method]['used_classes'])) {
                $this->cache[$path][$method]['used_classes'] = [];
            }
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

        if (!isset($this->cache[$path])) {
            $this->cache[$path] = [];
        }
        if (!isset($this->cache[$path][$method])) {
            $this->cache[$path][$method] = [];
        }
        if (!isset($this->cache[$path][$method]['used_instances'])) {
            $this->cache[$path][$method]['used_instances'] = [];
        }
        if (!isset($this->cache[$path][$method]['used_classes'])) {
            $this->cache[$path][$method]['used_classes'] = [];
        }
        if (!isset($this->cache[$path][$method]['used_instances'][$subject_class])) {
            $this->cache[$path][$method]['used_instances'][$subject_class] = [];
        }
        if (!$Subject->is_new()) {
            $subject_lookup_index = (string) Store::form_lookup_index($Subject->get_primary_index());//cant form the primary index back from the lookup index
            if (!isset($this->cache[$path][$method]['used_instances'][$subject_class][$subject_lookup_index])) {
                $this->cache[$path][$method]['used_instances'][$subject_class][$subject_lookup_index] = $MetaStore->get_last_update_time_by_object($Subject);
            }
        }

        if (!isset($this->cache[$path][$method]['used_classes'][$subject_class])) {
            $this->cache[$path][$method]['used_classes'][$subject_class] = $MetaStore->get_class_last_update_time(get_class($Subject));
        }
    }

    /**
     * Returns the cached data. To be used only for debug purposes.
     * @return iterable
     */
    public function get_cached_data_debug() : iterable
    {
        return $this->cache;
    }

    /**
     * Whether the Response from the next middleware allows for caching.
     * If the response contains a "pragma: no-cache" or "cache-control: no-cache" this middleware will not cache
     * @param ResponseInterface $Response
     * @return bool
     */
    private static function response_allows_caching(ResponseInterface $Response) : bool
    {
//        return self::message_allows_caching($Response);
        $ret = FALSE;
        $data_origin = self::get_data_origin($Response);
        if (in_array($data_origin, [self::DATA_ORIGIN_CONCRETE_ORM, self::DATA_ORIGIN_GENERIC_ORM] ) && self::message_allows_caching($Response)) {
            $ret = TRUE;
        }
        return $ret;
    }

    private static function message_allows_caching(MessageInterface $Message) : bool
    {
        $pragma_headers = $Message->getHeader('pragma');
        foreach ($pragma_headers as $header_value) {
            if (strtolower($header_value) === 'no-cache') {
                return FALSE;
            }
        }
        $cache_control_headers = $Message->getHeader('cache-control');
        foreach ($cache_control_headers as $header_value) {
            if (strtolower($header_value) === 'no-cache' || strtolower($header_value) === 'no-store') {
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
    private static function request_allows_caching(RequestInterface $Request) : bool
    {
        $ret = FALSE;
        $method = strtoupper($Request->getMethod());
        if (in_array($method, ['GET', 'OPTIONS'] ) && ! $Request->getAttribute('no_cache', FALSE) && self::message_allows_caching($Request) ) {
            $ret = TRUE;
        }
        return $ret;
    }

    private static function get_data_origin(ResponseInterface $Response) : int
    {
        $ret = 0;
        $data_origin_headers = $Response->getHeader('data-origin');
        foreach ($data_origin_headers as $data_origin_header) {
            $data_origin_header = strtolower($data_origin_header);
            foreach (self::DATA_ORIGIN_MAP as $origin_constant => $origin_data) {
                if ($data_origin_header === $origin_data['name']) {
                    $ret |= $origin_constant;
                }
            }
        }
        if (!$ret) {
            $ret = self::DATA_ORIGIN_OTHER;
        }
        return $ret;
    }

}