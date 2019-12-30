<?php
declare(strict_types=1);

namespace GuzabaPlatform\RequestCaching;

use Guzaba2\Http\Body\Structured;
use Guzaba2\Mvc\AfterControllerMethodHook;
use GuzabaPlatform\Platform\Application\BaseController;
use GuzabaPlatform\Platform\Application\GuzabaPlatform;
use Psr\Http\Message\ResponseInterface;

class AdminEntry extends AfterControllerMethodHook
{

    public function process(ResponseInterface $Response) : ResponseInterface
    {
        $Body = $Response->getBody();
        $struct = $Body->getStructure();//no ref here - we adhere to the immutability
        $struct['links'][] = [
            'name'          => 'Request Caching',
            'route'         => GuzabaPlatform::API_ROUTE_PREFIX.'/request-caching',
        ];
        $Response = $Response->withBody( new Structured($struct) );
        return $Response;
    }
}