<?php
declare(strict_types=1);

namespace GuzabaPlatform\RequestCaching\Controllers;

use Guzaba2\Http\Method;
use GuzabaPlatform\Platform\Application\BaseController;
use Psr\Http\Message\ResponseInterface;

class Admin extends BaseController
{
    protected const CONFIG_DEFAULTS = [
        'routes'        => [
            '/admin/request-caching'    => [
                Method::HTTP_GET              => [self::class, 'main']
            ],
        ],
    ];

    protected const CONFIG_RUNTIME = [];

    public function main() : ResponseInterface
    {

    }
}