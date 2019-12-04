<?php
declare(strict_types=1);

namespace GuzabaPlatform\RequestCaching;


use Guzaba2\Authorization\Acl\Permission;
use Guzaba2\Authorization\Role;
use Guzaba2\Authorization\User;
use Guzaba2\Base\Base;
use Guzaba2\Base\Exceptions\LogicException;
use Guzaba2\Coroutine\Coroutine;
use Guzaba2\Di\Container;
use Guzaba2\Event\Event;
use Guzaba2\Kernel\Kernel;
use Guzaba2\Orm\ActiveRecord;
use Guzaba2\Orm\MetaStore\NullMetaStore;
use Guzaba2\Orm\MetaStore\SwooleTable;
use Guzaba2\Orm\Store\Store;
use GuzabaPlatform\Platform\Authentication\Models\JwtToken;
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
            //'CurrentUser',//no longer used - the user_id is retrieved from the JWT
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


    private static ?array $autohrization_classes = NULL;

    /**
     * Middleware processing method
     * @param ServerRequestInterface $Request
     * @param RequestHandlerInterface $Handler
     * @return ResponseInterface
     * @throws \Guzaba2\Base\Exceptions\RunTimeException
     */
    public function process(ServerRequestInterface $Request, RequestHandlerInterface $Handler) : ResponseInterface
    {

        $path = $Request->getUri()->getPath();
        $method = strtoupper($Request->getMethod());
        $MetaStore = self::get_service('OrmMetaStore');

        //$current_user_id = self::get_service('CurrentUser')->get()->get_id();
        //the above is too slow and we do not really the user, only the user ID which is stored in the JWT
        $current_user_id = JwtToken::get_user_id_from_request($Request);

        if (self::request_allows_caching($Request)) {

            if (!isset($this->cache[$path])) {
                $this->cache[$path] = [];
            }
            if (!isset($this->cache[$path][$method])) {
                $this->cache[$path][$method] = [];
            }
            if (!isset($this->cache[$path][$method][$current_user_id])) {
                $this->cache[$path][$method][$current_user_id] = [];
            }
            if (!isset($this->cache[$path][$method][$current_user_id]['used_classes'])) {
                $this->cache[$path][$method][$current_user_id]['used_classes'] = [];
            }

            if (isset($this->cache[$path][$method][$current_user_id]['response'])) {
                //check were any of the user ORM objects updated
                //including were there any new classes of the used ones created
                $cache_ok = TRUE;
                $any_last_update_microtime = 0;

                $data_origin = self::get_data_origin($this->cache[$path][$method][$current_user_id]['response']);

                if ($data_origin === self::DATA_ORIGIN_CONCRETE_ORM) {
                    if (count($this->cache[$path][$method][$current_user_id]['used_instances'])) {
                        foreach ($this->cache[$path][$method][$current_user_id]['used_instances'] as $class => $instance_data) {
                            foreach ($instance_data as $object_lookup_index => $last_update_microtime) {
                                $object_lookup_index = (string)$object_lookup_index;//TODO - check why is this cast needed - it should already be string
                                $primary_index = Store::restore_primary_index($class, $object_lookup_index);
                                $store_last_update_microtime = $MetaStore->get_last_update_time($class, $primary_index);
                                //if the object has been deleted or updated
                                if (!$store_last_update_microtime || $last_update_microtime < $store_last_update_microtime) {
                                    $cache_ok = FALSE;
                                    //break;//do not break - update the data instead
                                }
                                $this->cache[$path][$method][$current_user_id]['used_instances'][$class][$object_lookup_index] = $store_last_update_microtime;
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

                    //check only the permission related classes
                    foreach ($this->cache[$path][$method][$current_user_id]['used_classes'] as $class => $class_last_update_microtime) {
                        if (!in_array($class, self::$autohrization_classes)) {
                            continue;
                        }
                        $store_class_last_update_microtime = $MetaStore->get_class_last_update_time($class);
                        if ($store_class_last_update_microtime !== NULL && $class_last_update_microtime < $store_class_last_update_microtime) {
                            $cache_ok = FALSE;
                            //break;//do not break - update the data instead
                        }
                        $this->cache[$path][$method][$current_user_id]['used_classes'][$class] = $store_class_last_update_microtime;
                        if ($store_class_last_update_microtime > $any_last_update_microtime) {
                            $any_last_update_microtime = $store_class_last_update_microtime;
                        }
                    }

                } elseif ($data_origin === self::DATA_ORIGIN_GENERIC_ORM) {
                    foreach ($this->cache[$path][$method][$current_user_id]['used_classes'] as $class => $class_last_update_microtime) {
                        $store_class_last_update_microtime = $MetaStore->get_class_last_update_time($class);
                        //there should be always data for the provided class but in case there isnt invalidate the cache
                        //no - there may be no data for the provided class if no objects were instantiated so far in this worker - for example for the Role or Permission classes
                        //if (!$store_class_last_update_microtime || $class_last_update_microtime < $store_class_last_update_microtime) {
                        if ($store_class_last_update_microtime !== NULL && $class_last_update_microtime < $store_class_last_update_microtime) {
                            $cache_ok = FALSE;
                            //break;//do not break - update the data instead
                        }
                        $this->cache[$path][$method][$current_user_id]['used_classes'][$class] = $store_class_last_update_microtime;
                        if ($store_class_last_update_microtime > $any_last_update_microtime) {
                            $any_last_update_microtime = $store_class_last_update_microtime;
                        }
                    }
                } else {
                    //not supported / unreachable
                    throw new LogicException(sprintf('An unsupported data-origin %s reached.', $data_origin));
                }

                if ($cache_ok) {
                    Kernel::log(sprintf('%s: Request is cached and served by the CachingMiddleware.', __CLASS__), LogLevel::DEBUG);
                    $Response = $this->cache[$path][$method][$current_user_id]['response'];
                    $any_last_update_time = (int) round( $any_last_update_microtime / 1_000_000);
                    $Response = $Response->withHeader('last-modified', gmdate('D, d M Y H:i:s ', $any_last_update_time) . 'GMT' );
                    return $Response;
                }

            }
        }

        $Response = $Handler->handle($Request);

        if (self::response_allows_caching($Response)) {

            $this->cache[$path][$method][$current_user_id]['response'] = $Response;

            if (!isset($this->cache[$path][$method][$current_user_id]['used_instances'])) {
                $this->cache[$path][$method][$current_user_id]['used_instances'] = [];
            }
            if (!isset($this->cache[$path][$method]['used_classes'])) {
                $this->cache[$path][$method][$current_user_id]['used_classes'] = [];
            }

            //the classes involved in the permissions should always be checked
            if (self::$autohrization_classes === NULL) {
                if (self::uses_service('AuthorizationProvider')) {
                    self::$autohrization_classes = self::get_service('AuthorizationProvider')::get_used_active_record_classes();
                } else {
                    self::$autohrization_classes = [];
                }
            }
//            if (self::uses_service('AuthorizationProvider')) {
//                foreach (self::get_service('AuthorizationProvider')::get_used_active_record_classes() as $auth_class_name) {
//                    $this->cache[$path][$method]['used_classes'][$auth_class_name] = $MetaStore->get_class_last_update_time($auth_class_name);
//                }
//            }
            //the permission related classes should always be checked
            foreach (self::$autohrization_classes as $auth_class_name) {
                $this->cache[$path][$method][$current_user_id]['used_classes'][$auth_class_name] = $MetaStore->get_class_last_update_time($auth_class_name);
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
        /**
         * @var ActiveRecord
         */
        $Subject = $Event->get_subject();
        $subject_class = get_class($Subject);
//        if ($Subject instanceof User && $Subject->get_id() === Container::get_default_current_user_id()) {
//            //if this is the default user being loaded skip this - will trigger recursion
//            return;
//        }

        if ($Subject instanceof User && $Subject->are_permission_checks_disabled()) { //this would mean it was instantiated as part of the login process or CurrentUser
            return;
        }

        $Request = Coroutine::getRequest();
        //$current_user_id = self::get_service('CurrentUser')->get()->get_id();
        //the above is too slow and we do not really the user, only the user ID which is stored in the JWT
        $current_user_id = JwtToken::get_user_id_from_request($Request);

        $path = $Request->getUri()->getPath();
        $method = strtoupper($Request->getMethod());



        $subject_id = $Subject->get_id();
        $MetaStore = self::get_service('OrmMetaStore');

        if (!isset($this->cache[$path])) {
            $this->cache[$path] = [];
        }
        if (!isset($this->cache[$path][$method])) {
            $this->cache[$path][$method] = [];
        }
        if (!isset($this->cache[$path][$method][$current_user_id])) {
            $this->cache[$path][$method][$current_user_id] = [];
        }
        if (!isset($this->cache[$path][$method][$current_user_id]['used_instances'])) {
            $this->cache[$path][$method][$current_user_id]['used_instances'] = [];
        }
        if (!isset($this->cache[$path][$method][$current_user_id]['used_classes'])) {
            $this->cache[$path][$method][$current_user_id]['used_classes'] = [];
        }
        if (!isset($this->cache[$path][$method][$current_user_id]['used_instances'][$subject_class])) {
            $this->cache[$path][$method][$current_user_id]['used_instances'][$subject_class] = [];
        }
        if (!$Subject->is_new()) {
            $subject_lookup_index = (string) Store::form_lookup_index($Subject->get_primary_index());//cant form the primary index back from the lookup index
            if (!isset($this->cache[$path][$method][$current_user_id]['used_instances'][$subject_class][$subject_lookup_index])) {
                $this->cache[$path][$method][$current_user_id]['used_instances'][$subject_class][$subject_lookup_index] = $MetaStore->get_last_update_time_by_object($Subject);
            }
        }

        if (!isset($this->cache[$path][$method][$current_user_id]['used_classes'][$subject_class])) {
            $this->cache[$path][$method][$current_user_id]['used_classes'][$subject_class] = $MetaStore->get_class_last_update_time(get_class($Subject));
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

    /**
     * Checks whether the message (Request or Response) allows caching.
     * Checks the "pragma" and "cache-control" headers for the "no-cache" value.
     * @param MessageInterface $Message
     * @return bool
     */
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

    /**
     * Gets the data origin based on the "data-origin" header if present in the $Response
     * @param ResponseInterface $Response
     * @return int
     */
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