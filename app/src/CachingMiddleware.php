<?php
declare(strict_types=1);

namespace GuzabaPlatform\RequestCaching;


use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Guzaba2\Orm\Store\Interfaces\StoreInterface;

class CachingMiddleware implements MiddlewareInterface
{

    private OrmStoreInterface $Store;

    private array $cache = [];

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

//        $path = $Request->getUri()->getPath();
//        $method = strtoupper($Request->getMethod());
//        //cache only GET and OPTIONS methods
//        if (in_array($method, ['GET', 'OPTIONS'] )) {
//            if (isset($this->cache[$path][$method])) {
//                return $this->cache[$path][$method];
//            }
//        }
        print 'BEFORE';

        $Response = $Handler->handle($Request);

        //$this->cache[$path][$method] = $Request;
        print 'AFTER';

        return $Response;
    }
}