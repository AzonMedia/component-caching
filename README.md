# GuzabaPlatform\RequestCaching

Request Caching component for [GuzabaPlatform](https://github.com/AzonMedia/guzaba-platform).

Caches the request responses if the used ActiveRecord objects in the request were not modified (or no new ones were created from the same classes).

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
```

## Preventing requests from beign cached

There are two ways to prevent a request to be cached:
- the $Request to contain an attributed "no_cache"
- the $Response to contain a header "pragma: no-cache" or "cache-control: no-cache"

## Additional details

It injects a new middleware - GuzabaPlatform\RequestCaching\CachingMiddleware and registeres a callback on ActiveRecord:_after_read event.
It also uses the OrmMetaStore service to obtain the last modification times of the objects (and creation of new ones using the class meta data).