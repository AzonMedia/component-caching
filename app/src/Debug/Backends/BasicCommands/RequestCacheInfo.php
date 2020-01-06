<?php
declare(strict_types=1);

namespace GuzabaPlatform\RequestCaching\Debug\Backends\BasicCommands;

use Guzaba2\Kernel\Kernel;
use Guzaba2\Swoole\Debug\Backends\BasicCommand;
use Guzaba2\Translator\Translator as t;
use Psr\Log\LogLevel;

class RequestCacheInfo extends BasicCommand
{

    protected static $commands = [
        'show request cache' => [ 'method' => 'debug_get_data', 'help_str' => 'Dumps Cache' ],
        'get rcache hits' => [ 'method' => 'get_hits', 'help_str' => 'Shows cache hits' ],
        'get rcache hits percentage' => [ 'method' => 'get_hits_percentage', 'help_str' => 'Shows hits as part of hits + misses' ],
        'get rcache misses' => [ 'method' => 'get_misses', 'help_str' => 'Shows cache misses' ],
        'reset rcache stats' => [ 'method' => 'reset_stats', 'help_str' => 'Resets cache stats - resets hits, resets misses' ],
        'reset request cache' => [ 'method' => 'reset_all', 'help_str' => 'Resets cache - clears cache, resets hits, resets misses' ],
    ];

    public function handle(string $command, string $current_prompt, ?string &$change_prompt_to = NULL) : ?string
    {
        $ret = NULL;
        $class_name = self::get_class_name();
        //TODO implement

        return $ret;
    }
}