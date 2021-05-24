<?php


namespace Bx\Model;

use Bx\Model\Interfaces\FetcherModelInterface;
use Bx\Model\Interfaces\ModelServiceInterface;
use Bx\Model\Interfaces\AggregateModelInterface;
use Bx\Model\Interfaces\QueryInterface;
use Exception;

class FetcherModel implements FetcherModelInterface
{
    /**
     * @var ModelServiceInterface
     */
    private $service;
    /**
     * @var string
     */
    private $foreignKey;
    /**
     * @var string
     */
    private $destKey;
    /**
     * @var array
     */
    private $addFilter;
    /**
     * @var string
     */
    private $keySave;
    /**
     * @var string
     */
    private $linkedModelKey;
    /**
     * @var bool
     */
    private $isMultipleValue;
    /**
     * @var AggregateModelInterface|string|null
     */
    private $classCast;
    /**
     * @var QueryInterface
     */
    private $query;
    /**
     * @var callable
     */
    private $compareCallback;

    /**
     * FetcherModel constructor.
     * @param ModelServiceInterface $linkedService
     * @param string $keySave
     * @param string $foreignKey
     * @param string $destKey
     * @param bool $isMultipleValue
     * @param QueryInterface|null $query
     */
    public function __construct(
        ModelServiceInterface $linkedService,
        string $keySave,
        string $foreignKey,
        string $destKey,
        bool $isMultipleValue = false,
        QueryInterface $query = null
    )
    {
        $this->classCast = null;
        $this->service = $linkedService;
        $this->keySave = $keySave;
        $this->foreignKey = $foreignKey;
        $this->linkedModelKey = $this->destKey = $destKey;
        $this->isMultipleValue = $isMultipleValue;
        $this->query = $query;
    }

    /**
     * @param ModelServiceInterface $linkedService
     * @param string $keySave
     * @param string $foreignKey
     * @param string $destKey
     * @param QueryInterface|null $query
     * @return static
     */
    public static function initAsSingleValue(
        ModelServiceInterface $linkedService,
        string $keySave,
        string $foreignKey,
        string $destKey,
        QueryInterface $query = null
    ): self
    {
        return new static($linkedService, $keySave, $foreignKey, $destKey, false, $query);
    }

    /**
     * @param ModelServiceInterface $linkedService
     * @param string $keySave
     * @param string $foreignKey
     * @param string $destKey
     * @param QueryInterface|null $query
     * @return static
     */
    public static function initAsMultipleValue(
        ModelServiceInterface $linkedService,
        string $keySave,
        string $foreignKey,
        string $destKey,
        QueryInterface $query = null
    ): self
    {
        return new static($linkedService, $keySave, $foreignKey, $destKey, true, $query);
    }

    /**
     * @param string $key
     * @return $this
     */
    public function setLinkedModelKey(string $key): self
    {
        $this->linkedModelKey = $key;
        return $this;
    }

    /**
     * @param array $listKeyValues
     * @return ModelCollection
     */
    private function getLinkedCollection(array $listKeyValues): ModelCollection
    {
        $addFilter = $this->query instanceof QueryInterface ? $this->query->getFilter() : [];
        $filter = array_merge($addFilter, ["={$this->destKey}" => $listKeyValues]);
        $params = ['filter' => $filter];

        if ($this->query instanceof QueryInterface) {
            if ($this->query->hasSelect()) {
                $params['select'] = $this->query->getSelect();
            }
            
            if ($this->query->hasLimit()) {
                $params['limit'] = $this->query->getLimit();
            }

            if ($this->query->hasSort()) {
                $params['order'] = $this->query->getSort();
            }

            if ($this->query->hasFetchList()) {
                $params['fetch'] = $this->query->getFetchList();
            }
        }

        return $this->service->getList($params);
    }

    /**
     * @param ModelCollection $collection
     */
    private function fillAsMultipleValue(ModelCollection $collection)
    {
        $listKeyValues = [];
        foreach($collection->column($this->foreignKey) as $value) {
            $listKeyValues = array_merge($listKeyValues, (array)$value);
        }

        if (empty($listKeyValues)) {
            return;
        }

        $linkedCollection = $this->getLinkedCollection($listKeyValues);
        foreach ($collection as $model) {
            $originalValue = (array)($model[$this->foreignKey] ?? []);
            if (empty($originalValue)) {
                continue;
            }

            $isCallableCallback = $this->compareCallback !== null;
            $resultList = [];
            foreach ($linkedCollection as $linkedModel) {
                $likedValue = $linkedModel[$this->linkedModelKey] ?? null;
                if (
                    ($isCallableCallback && ($this->compareCallback)($model, $linkedModel)) ||
                    (!empty($likedValue) && is_array($originalValue) && in_array($likedValue, $originalValue))
                ) {
                    $resultList[] = $linkedModel;
                    if (empty($class)) {
                        $class = get_class($linkedModel);
                    }
                }
            }

            if (!empty($class)) {
                $resultCollection = new ModelCollection($resultList, $class);
                $model[$this->keySave] = !empty($this->classCast) ? $this->classCast::init($resultCollection) : $resultCollection;
            }
        }
    }

    /**
     * @param string $class
     * @return FetcherModelInterface
     */
    public function castTo(string $class): FetcherModelInterface
    {
        if (!class_exists($class)) {
            throw new Exception("{$class} is not found!");
        }

        $this->classCast = $class;
        return $this;
    }


    /**
     * @param callable $fn
     * @return FetcherModelInterface
     */
    public function setCompareCallback(callable $fn): FetcherModelInterface
    {
        $this->compareCallback = $fn;
        return $this;
    }

    /**
     * @param ModelCollection $collection
     */
    private function fillAsSingleValue(ModelCollection $collection)
    {
        $listKeyValues = $collection->unique($this->foreignKey);
        if (empty($listKeyValues)) {
            return;
        }

        $isCallableCallback = $this->compareCallback !== null;
        $linkedCollection = $this->getLinkedCollection($listKeyValues);
        foreach ($collection as $model) {
            $originalValue = $model[$this->foreignKey] ?? null;
            if (empty($originalValue)) {
                continue;
            }

            foreach ($linkedCollection as $linkedModel) {
                $likedValue = $linkedModel[$this->linkedModelKey] ?? null;
                if (
                    ($isCallableCallback && ($this->compareCallback)($model, $linkedModel)) ||
                    (!empty($likedValue) && $originalValue == $likedValue)
                ) {
                    $model[$this->keySave] = $linkedModel;
                }
            }
        }
    }

    /**
     * @param ModelCollection $collection
     */
    public function fill(ModelCollection $collection)
    {
        if ($this->isMultipleValue) {
            $this->fillAsMultipleValue($collection);
        } else {
            $this->fillAsSingleValue($collection);
        }
    }
}