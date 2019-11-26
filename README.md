# GuzabaPlatform\RequestCaching

Request Caching component for GuzabaPlatform.

Caches the request responses if the used ActiveRecord objects in the request were not modified (or no new ones were created from the same classes),

## Installation and configuration

```
$ composer require guzaba-platform/request-caching
```

No configuration is required. After installation on application startup the following message should be displayed:
```
[2.022402 Startup] Installed components:
    ...
    - guzaba-platform/request-caching - GuzabaPlatform\RequestCaching : /home/local/PROJECTS/guzaba-platform-marketplace/guzaba-platform-marketplace/vendor/guzaba-platform/request-caching/app/src
    ...
```
And if initialized successfully:
```
[2.022484 Startup] Middlewares:
    ...
    - GuzabaPlatform\RequestCaching\CachingMiddleware - guzaba.source:///home/local/PROJECTS/guzaba-platform-marketplace/guzaba-platform-marketplace/vendor/guzaba-platform/request-caching/app/src/CachingMiddleware.php
    ...

[2.022722 Startup] Starting Swoole HTTP server on 0.0.0.0:8081 at 2019-11-26 15:54:00 UTC
[2.022744 Startup] Static serving is enabled and document_root is set to /home/local/PROJECTS/guzaba-platform-marketplace/guzaba-platform-marketplace/app/public/
```