<?php

namespace Luany\Database;

/**
 * EagerProxy
 *
 * A lightweight proxy returned by Model::with() that carries the list of
 * relations to eager-load and delegates all() / find() to the underlying
 * model class with the eager-load config injected.
 *
 * This avoids calling static methods on instances (which is valid PHP but
 * can trigger static analysis warnings) and keeps the API clean:
 *
 *   User::with('posts')->all()      → EagerProxy::all()  → User::all()  (with eager)
 *   User::with('profile')->find(1)  → EagerProxy::find() → User::find() (with eager)
 *
 * The eager-load state is consumed and reset inside Model::eagerLoadRelations()
 * immediately after the query executes, so there is no cross-request state leakage.
 */
final class EagerProxy
{
    /**
     * @param class-string<Model> $modelClass  The model class this proxy was created for
     * @param string[]            $relations   Relation method names to eager-load
     */
    public function __construct(
        private readonly string $modelClass,
        private readonly array $relations,
    ) {}

    /**
     * Inject eager-load relations and delegate to Model::all().
     *
     * @return Model[]
     */
    public function all(string $orderBy = ''): array
    {
        $this->injectEagerLoad();
        return ($this->modelClass)::all($orderBy);
    }

    /**
     * Inject eager-load relations and delegate to Model::find().
     */
    public function find(int|string $id): ?Model
    {
        $this->injectEagerLoad();
        return ($this->modelClass)::find($id);
    }

    /**
     * Set the eager-load relations on the model class before executing the query.
     * Model::eagerLoadRelations() will read and reset this state after the query.
     */
    private function injectEagerLoad(): void
    {
        // Access the private static $eagerLoad via a public setter on Model
        ($this->modelClass)::setEagerLoad($this->relations);
    }
}
