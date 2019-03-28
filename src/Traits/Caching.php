<?php namespace GeneaLabs\LaravelModelCaching\Traits;

use Carbon\Carbon;
use GeneaLabs\LaravelModelCaching\CacheKey;
use GeneaLabs\LaravelModelCaching\CacheTags;
use Illuminate\Cache\TaggableStore;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;

trait Caching
{
    protected $isCachable = true;

    public function cache(array $tags = [])
    {
        $cache = app('cache');

        if (config('laravel-model-caching.store')) {
            $cache = $cache->store(config('laravel-model-caching.store'));
        }

        if (is_subclass_of($cache->getStore(), TaggableStore::class)) {
            $cache = $cache->tags($tags);
        }

        return $cache;
    }

    public function disableModelCaching()
    {
        $this->isCachable = false;

        return $this;
    }

    public function flushCache(array $tags = [])
    {
        if (count($tags) === 0) {
            $tags = $this->makeCacheTags();
        }

        $this->cache($tags)->flush();

        [$cacheCooldown] = $this->getModelCacheCooldown($this);

        if ($cacheCooldown) {
            $cachePrefix = $this->getCachePrefix();
            $modelClassName = get_class($this);
            $cacheKey = "{$cachePrefix}:{$modelClassName}-cooldown:saved-at";

            $this->cache()
                ->rememberForever($cacheKey, function () {
                    return (new Carbon)->now();
                });
        }
    }

    protected function getCachePrefix() : string
    {
        return "genealabs:laravel-model-caching:"
            . (config('laravel-model-caching.cache-prefix')
                ? config('laravel-model-caching.cache-prefix', '') . ":"
                : "");
    }

    protected function makeCacheKey(
        array $columns = ['*'],
        $idColumn = null,
        string $keyDifferentiator = ''
    ) : string {
        $eagerLoad = $this->eagerLoad ?? [];
        $model = $this->model ?? $this;
        $query = $this->query ?? app('db')->query();

        return (new CacheKey($eagerLoad, $model, $query))
            ->make($columns, $idColumn, $keyDifferentiator);
    }

    protected function makeCacheTags() : array
    {
        $eagerLoad = $this->eagerLoad ?? [];
        $model = $this->model instanceof Model
            ? $this->model
            : $this;
        $query = $this->query instanceof Builder
            ? $this->query
            : app('db')->query();
        $tags = (new CacheTags($eagerLoad, $model, $query))
            ->make();

        return $tags;
    }

    public function getModelCacheCooldown(Model $instance) : array
    {
        if (! $instance->cacheCooldownSeconds) {
            return [null, null, null];
        }

        $cachePrefix = $this->getCachePrefix();
        $modelClassName = get_class($instance);

        if( $this->cooldownDisabled($modelClassName) ){
        	return [null, null, null];
        }

        [$cacheCooldown, $invalidatedAt, $savedAt] = $this
            ->getCacheCooldownDetails($instance, $cachePrefix, $modelClassName);

        if (! $cacheCooldown || $cacheCooldown === 0) {
            return [null, null, null];
        }

        return [$cacheCooldown, $invalidatedAt, $savedAt];
    }

    protected function getCacheCooldownDetails(
        Model $instance,
        string $cachePrefix,
        string $modelClassName
    ) : array {
        return [
            $instance
                ->cache()
                ->get("{$cachePrefix}:{$modelClassName}-cooldown:seconds"),
            $instance
                ->cache()
                ->get("{$cachePrefix}:{$modelClassName}-cooldown:invalidated-at"),
            $instance
                ->cache()
                ->get("{$cachePrefix}:{$modelClassName}-cooldown:saved-at"),
        ];
    }

    protected function checkCooldownAndRemoveIfExpired(Model $instance)
    {
        [$cacheCooldown, $invalidatedAt] = $this->getModelCacheCooldown($instance);

        if (! $cacheCooldown
            || (new Carbon)->now()->diffInSeconds($invalidatedAt) < $cacheCooldown
        ) {
            return;
        }

        $cachePrefix = $this->getCachePrefix();
        $modelClassName = get_class($instance);

        $instance
            ->cache()
            ->forget("{$cachePrefix}:{$modelClassName}-cooldown:seconds");
        $instance
            ->cache()
            ->forget("{$cachePrefix}:{$modelClassName}-cooldown:invalidated-at");
        $instance
            ->cache()
            ->forget("{$cachePrefix}:{$modelClassName}-cooldown:saved-at");
        $instance->flushCache();
    }

    protected function checkCooldownAndFlushAfterPersisting(Model $instance)
    {
        [$cacheCooldown, $invalidatedAt] = $instance->getModelCacheCooldown($instance);

        if (! $cacheCooldown) {
            $instance->flushCache();

            return;
        }

        $this->setCacheCooldownSavedAtTimestamp($instance);

        if ((new Carbon)->now()->diffInSeconds($invalidatedAt) >= $cacheCooldown) {
            $instance->flushCache();
        }
    }

    public function isCachable() : bool
    {
        return $this->isCachable
            && ! config('laravel-model-caching.disabled');
    }

    public function cooldownDisabled(string $class) : bool
    {
    	return ! config('laravel-model-caching.enable-cooldown', true)
		    || in_array($class, config('laravel-model-caching.cooldown-disable', []))
		    || $this->getAllTraitsUsedByClass($class)
		            ->contains('GeneaLabs\LaravelModelCaching\Traits\DisableCooldown');
    }

	/** @SuppressWarnings(PHPMD.BooleanArgumentFlag) */
	public static function getAllTraitsUsedByClass(
		string $classname,
		bool $autoload = true
	) : Collection {
		$traits = collect();

		if (class_exists($classname, $autoload)) {
			$traits = collect(class_uses($classname, $autoload));
		}

		$parentClass = get_parent_class($classname);

		if ($parentClass) {
			$traits = $traits
				->merge(static::getAllTraitsUsedByClass($parentClass, $autoload));
		}

		return $traits;
	}

    protected function setCacheCooldownSavedAtTimestamp(Model $instance)
    {
        $cachePrefix = $this->getCachePrefix();
        $modelClassName = get_class($instance);
        $cacheKey = "{$cachePrefix}:{$modelClassName}-cooldown:saved-at";

        $instance->cache()
            ->rememberForever($cacheKey, function () {
                return (new Carbon)->now();
            });
    }
}
