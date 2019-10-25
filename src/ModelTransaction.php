<?php namespace Koozza\ModelTransaction;


use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ModelTransaction
{
    /**
     * @var Collection
     */
    private $models;

    /**
     * @var bool
     */
    private $collecting = false;

    /**
     * @var integer
     */
    private $maxModelsPerQuery = 250;

    /**
     * @var bool
     */
    private $touchTimestamps = true;

    /**
     * @var array
     */
    private $blacklist = [];

    /**
     * @var array
     */
    private $whitelist = [];



    /**
     * ModelTransaction constructor.
     */
    public function __construct()
    {
        $this->models = collect();
    }


    /**
     * Return singleton instance of self.
     *
     * @return mixed
     * @throws BindingResolutionException
     * @return void
     */
    private static function getSingleton() : void
    {
        return app()->make(self::class);
    }


    /**
     * Start transaction
     *
     * @throws BindingResolutionException
     * @return void
     */
    public static function start() : void
    {
        self::getSingleton()->collecting = true;
    }


    /**
     * Commit transaction to database
     *
     * @throws BindingResolutionException
     * @return void
     */
    public static function commit() : void
    {
        $self = self::getSingleton();

        if ($self->collecting) {
            $self->update();
            $self->insert();

            $self->collecting = false;
            $self->models = collect();
        }
    }


    /**
     * Flush transaction to database
     *
     * @deprecated
     * @throws BindingResolutionException
     * @return void
     */
    public static function flush() : void
    {
        self::commit();
    }


    /**
     * Set max amount of models per query. If exceeded query will be split into multiple queries.
     * Default: 250
     *
     * @param  int  $amount
     * @throws BindingResolutionException
     * @return void
     */
    public static function setMaxModelsPerQuery(int $amount) : void
    {
        self::getSingleton()->maxModelsPerQuery = $amount;
    }


    /**
     * Touch timestamps on models if available?
     * Default: true
     *
     * @param  bool  $value
     * @throws BindingResolutionException
     * @return void
     */
    public static function setTouchTimestamps(bool $value) : void
    {
        self::getSingleton()->touchTimestamps = $value;
    }


    /**
     * Set model whitelist. Expects array with class names.
     *
     * @param  array  $whitelist
     * @return void
     * @throws BindingResolutionException
     */
    public static function whitelist(array $whitelist) : void
    {
        self::getSingleton()->whitelist = $whitelist;
    }


    /**
     * Set model blacklist. Expects array with class names.
     *
     * @param  array  $blacklist
     * @return void
     * @throws BindingResolutionException
     */
    public static function blacklist(array $blacklist) : void
    {
        self::getSingleton()->blacklist = $blacklist;
    }


    /**
     * Register model
     *
     * @param $arguments
     * @return bool
     * @throws BindingResolutionException
     */
    public static function register($arguments) : bool
    {
        $self = self::getSingleton();

        if($self->collecting) {
            //Check whitelist
            if (count($self->whitelist) > 0 && !in_array(get_class($arguments), $self->whitelist)) {
                return true;
            }

            //Check blacklist
            if (in_array(get_class($arguments), $self->blacklist)) {
                return true;
            }

            $self->models->add($arguments);

            return false;
        }
        return true;
    }


    /**
     * Get models to INSERT
     *
     * @return Collection
     */
    private function getInsertModels() : Collection
    {
        return $this->models->filter(function($m) { return !$m->exists; })->groupBy(function($m) { return get_class($m); });
    }


    /**
     * Get models to UPDATE
     *
     * @return Collection
     */
    private function getUpdateModels() : Collection
    {
        return $this->models->filter(function($m) { return $m->exists; })->groupBy(function($m) { return get_class($m); });
    }

    /**
     * Insert models to database
     *
     * @return void
     */
    private function insert() : void
    {
        foreach ($this->getInsertModels() as $class => $models) {
            $insert = [];
            $keys = array_fill_keys($models->map(function($m) { return array_keys($m->getAttributes()); })->flatten()->unique()->toArray(), null);

            foreach($models as $model) {
                //Touch timestamps if needed
                $touchTimestamps = [];
                if($this->touchTimestamps && $model->usesTimestamps()) {
                    $touchTimestamps = [
                        $model->getCreatedAtColumn() => $model->freshTimestamp(),
                        $model->getUpdatedAtColumn() => $model->freshTimestamp(),
                    ];
                }

                array_push($insert, $model->getAttributes() + $keys + $touchTimestamps);
            }

            $table = with(new $class)->getTable();
            foreach(array_chunk($insert, $this->maxModelsPerQuery, 2) as $chunckedArray) {
                DB::table($table)->insert($chunckedArray);
            }
        }
    }

    /**
     * Update models in database
     *
     * @return void
     */
    private function update() : void
    {
        foreach ($this->getUpdateModels() as $class => $models) {
            //Get changed attributes, if none: return false.
            $attributes = $models->map(function($m) { return array_keys($m->getDirty()); })->flatten()->unique()->toArray();
            if (empty($attributes)) {
                return;
            }

            $updates = [];
            $pk = with(new $class)->getKeyName();

            foreach ($models as $model) {
                //Touch timestamps if needed
                $touchTimestamps = [];
                if($this->touchTimestamps && $model->usesTimestamps()) {
                    $touchTimestamps = [
                        $model->getUpdatedAtColumn() => $model->freshTimestamp(),
                    ];
                }

                //Create update array
                if(!array_key_exists($model->$pk, $updates)) {
                    //First save of model, create new update key
                    $updates[$model->$pk] = [];

                    foreach ($attributes as $attribute) {
                        $updates[$model->$pk][$attribute] = $model->$attribute;
                    }
                } else {
                    //Second+ save of model
                    //Only override dirty values
                    foreach ($model->getDirty() as $key=>$value) {
                        $updates[$model->$pk][$key] = $value;
                    }
                }
                $updates[$model->$pk] += $touchTimestamps;
            }

            $table = with(new $class)->getTable();
            foreach(array_chunk($updates, $this->maxModelsPerQuery, 2) as $chunkedArray) {
                $statement = $this->createUpdateQuery($table, $chunkedArray, $pk);
                DB::update($statement->query, $statement->params);
            }
        }
    }

    /**
     * Create update query
     *
     * @param  string  $table
     * @param  array  $updateArray
     * @param  string  $key
     * @return object
     */
    private function createUpdateQuery(string $table, array $updateArray, string $key) : object
    {
        $q = "UPDATE `{$table}` SET ";

        $caseArray = [];
        $params = [];

        $firstKey = key($updateArray);
        foreach (array_keys($updateArray[$firstKey]) as $field) {
            $case = $field . ' = (CASE '.$key;

            foreach ($updateArray as $id => $attributes) {
                $case .= ' WHEN ? THEN ?';

                $params[] = $id;
                $params[] = $attributes[$field];
            }

            $case .= ' END)';

            $caseArray[] = $case;
        }

        $q .= implode(' , ', $caseArray);
        $q .= ' WHERE '.$key.' IN ('.implode(',', array_keys($updateArray)).');';

        return (object) [
            'query' => $q,
            'params' => $params,
        ];
    }
}
