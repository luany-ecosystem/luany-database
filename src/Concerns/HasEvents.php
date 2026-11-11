<?php

namespace Luany\Database\Concerns;

/**
 * Model lifecycle events.
 *
 *   creating / created     INSERT of a new model
 *   updating / updated     UPDATE of a model with changes
 *   saving   / saved       both of the above
 *   deleting / deleted     delete() (soft or hard), forceDelete()
 *   restoring / restored   SoftDeletes::restore()
 *
 * Register listeners per model class:
 *
 *   Post::creating(fn (Post $post) => $post->slug ??= slugify($post->title));
 *   Post::deleting(fn (Post $post) => $post->comments === []);   // false cancels
 *   Post::observe(PostObserver::class);                         // methods named after events
 *
 * or once, when the class is first used:
 *
 *   protected static function booted(): void
 *   {
 *       static::saving(fn (self $m) => $m->email = strtolower($m->email));
 *   }
 *
 * A listener of an "-ing" event that returns false cancels the operation
 * (save()/delete()/restore() return false). Bulk query updates/deletes do
 * not fire events.
 *
 * @phpstan-require-extends \Luany\Database\Model
 */
trait HasEvents
{
    /** @var list<string> */
    public const EVENTS = [
        'creating', 'created', 'updating', 'updated', 'saving', 'saved',
        'deleting', 'deleted', 'restoring', 'restored',
    ];

    /** @var array<class-string, array<string, list<callable>>> */
    private static array $eventListeners = [];

    /** @var array<class-string, true> */
    private static array $bootedModels = [];

    /**
     * Register a listener for one of self::EVENTS on this model class.
     */
    public static function on(string $event, callable $listener): void
    {
        if (!in_array($event, self::EVENTS, true)) {
            throw new \InvalidArgumentException(
                "Unknown model event [{$event}]. Available: " . implode(', ', self::EVENTS) . '.'
            );
        }

        self::$eventListeners[static::class][$event][] = $listener;
    }

    /**
     * Register an observer: an object (or class name) whose public methods
     * named after events (creating, saved, …) receive the model.
     */
    public static function observe(object|string $observer): void
    {
        $instance = is_string($observer) ? new $observer() : $observer;

        foreach (self::EVENTS as $event) {
            if (method_exists($instance, $event)) {
                static::on($event, [$instance, $event]);
            }
        }
    }

    public static function creating(callable $listener): void  { static::on('creating', $listener); }
    public static function created(callable $listener): void   { static::on('created', $listener); }
    public static function updating(callable $listener): void  { static::on('updating', $listener); }
    public static function updated(callable $listener): void   { static::on('updated', $listener); }
    public static function saving(callable $listener): void    { static::on('saving', $listener); }
    public static function saved(callable $listener): void     { static::on('saved', $listener); }
    public static function deleting(callable $listener): void  { static::on('deleting', $listener); }
    public static function deleted(callable $listener): void   { static::on('deleted', $listener); }
    public static function restoring(callable $listener): void { static::on('restoring', $listener); }
    public static function restored(callable $listener): void  { static::on('restored', $listener); }

    /**
     * Remove this class's listeners (and allow booted() to run again).
     */
    public static function flushEventListeners(): void
    {
        unset(self::$eventListeners[static::class], self::$bootedModels[static::class]);
    }

    /**
     * Hook for registering listeners; runs once per class, on first use.
     */
    protected static function booted(): void
    {
    }

    protected static function bootIfNotBooted(): void
    {
        if (!isset(self::$bootedModels[static::class])) {
            self::$bootedModels[static::class] = true;
            static::booted();
        }
    }

    /**
     * Fire an event. Returns false when a listener of a halting ("-ing")
     * event returned false.
     */
    protected function fireModelEvent(string $event): bool
    {
        static::bootIfNotBooted();

        $halts = str_ends_with($event, 'ing');

        foreach (self::$eventListeners[static::class][$event] ?? [] as $listener) {
            if ($listener($this) === false && $halts) {
                return false;
            }
        }

        return true;
    }
}
