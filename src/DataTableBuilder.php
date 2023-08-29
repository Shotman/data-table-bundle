<?php

declare(strict_types=1);

namespace Kreyu\Bundle\DataTableBundle;

use Kreyu\Bundle\DataTableBundle\Action\ActionBuilderInterface;
use Kreyu\Bundle\DataTableBundle\Action\ActionContext;
use Kreyu\Bundle\DataTableBundle\Action\ActionFactoryInterface;
use Kreyu\Bundle\DataTableBundle\Action\ActionInterface;
use Kreyu\Bundle\DataTableBundle\Action\Type\ActionType;
use Kreyu\Bundle\DataTableBundle\Action\Type\ActionTypeInterface;
use Kreyu\Bundle\DataTableBundle\Column\ColumnBuilderInterface;
use Kreyu\Bundle\DataTableBundle\Column\ColumnFactoryInterface;
use Kreyu\Bundle\DataTableBundle\Column\ColumnInterface;
use Kreyu\Bundle\DataTableBundle\Column\Type\ActionsColumnType;
use Kreyu\Bundle\DataTableBundle\Column\Type\CheckboxColumnType;
use Kreyu\Bundle\DataTableBundle\Column\Type\ColumnType;
use Kreyu\Bundle\DataTableBundle\Column\Type\ColumnTypeInterface;
use Kreyu\Bundle\DataTableBundle\Exception\InvalidArgumentException;
use Kreyu\Bundle\DataTableBundle\Exporter\ExportData;
use Kreyu\Bundle\DataTableBundle\Exporter\ExporterBuilderInterface;
use Kreyu\Bundle\DataTableBundle\Exporter\ExporterFactoryInterface;
use Kreyu\Bundle\DataTableBundle\Exporter\ExporterInterface;
use Kreyu\Bundle\DataTableBundle\Exporter\Type\ExporterType;
use Kreyu\Bundle\DataTableBundle\Exporter\Type\ExporterTypeInterface;
use Kreyu\Bundle\DataTableBundle\Filter\FilterBuilderInterface;
use Kreyu\Bundle\DataTableBundle\Filter\FilterFactoryInterface;
use Kreyu\Bundle\DataTableBundle\Filter\FilterInterface;
use Kreyu\Bundle\DataTableBundle\Filter\FiltrationData;
use Kreyu\Bundle\DataTableBundle\Filter\Type\FilterType;
use Kreyu\Bundle\DataTableBundle\Filter\Type\FilterTypeInterface;
use Kreyu\Bundle\DataTableBundle\Filter\Type\SearchFilterType;
use Kreyu\Bundle\DataTableBundle\Pagination\PaginationData;
use Kreyu\Bundle\DataTableBundle\Persistence\PersistenceAdapterInterface;
use Kreyu\Bundle\DataTableBundle\Persistence\PersistenceSubjectProviderInterface;
use Kreyu\Bundle\DataTableBundle\Personalization\PersonalizationData;
use Kreyu\Bundle\DataTableBundle\Query\ProxyQueryInterface;
use Kreyu\Bundle\DataTableBundle\Request\RequestHandlerInterface;
use Kreyu\Bundle\DataTableBundle\Sorting\SortingData;
use Kreyu\Bundle\DataTableBundle\Type\ResolvedDataTableTypeInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\EventDispatcher\ImmutableEventDispatcher;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Translation\TranslatableMessage;

class DataTableBuilder implements DataTableBuilderInterface
{
    /**
     * Resolved type class, containing instructions on how to build a data table.
     */
    private ResolvedDataTableTypeInterface $type;

    /**
     * Stores an array of themes to used to render the data table.
     *
     * @var array<string>
     */
    private array $themes = [];

    /**
     * User-friendly title used to describe a data table.
     */
    private null|string|TranslatableMessage $title = null;

    /**
     * Stores an array of parameters used in translation of the user-friendly title.
     */
    private array $titleTranslationParameters = [];

    /**
     * Domain name used in the translation of the data table elements.
     */
    private null|false|string $translationDomain = null;

    /**
     * The column builders defined for the data table.
     *
     * @var array<ColumnBuilderInterface>
     */
    private array $columns = [];

    /**
     * The data of columns that haven't been converted to column builders yet.
     *
     * @var array<array{0: class-string<ColumnTypeInterface>, 1: array}>
     */
    private array $unresolvedColumns = [];

    /**
     * The column builders defined for the data table.
     *
     * @var array<FilterBuilderInterface>
     */
    private array $filters = [];

    /**
     * The data of filters that haven't been converted to filter builders yet.
     *
     * @var array<array{0: class-string<FilterTypeInterface>, 1: array}>
     */
    private array $unresolvedFilters = [];

    /**
     * The search handler used to filter the data table using a single search query.
     */
    private ?\Closure $searchHandler = null;

    /**
     * Determines whether the builder should automatically add {@see SearchFilterType}
     * when a search handler is defined in {@see DataTableBuilder::$searchHandler}.
     */
    private bool $autoAddingSearchFilter = true;

    /**
     * The action builders defined for the data table.
     *
     * @var array<ActionBuilderInterface>
     */
    private array $actions = [];

    /**
     * The data of actions that haven't been converted to action builders yet.
     *
     * @var array<array{0: class-string<ActionTypeInterface>, 1: array}>
     */
    private array $unresolvedActions = [];

    /**
     * The batch action builders defined for the data table.
     *
     * @var array<ActionBuilderInterface>
     */
    private array $batchActions = [];

    /**
     * The data of batch actions that haven't been converted to action builders yet.
     *
     * @var array<array{0: class-string<ActionTypeInterface>, 1: array}>
     */
    private array $unresolvedBatchActions = [];

    /**
     * Determines whether the builder should automatically add {@see CheckboxColumnType}
     * when at least one batch action is defined in {@see DataTableBuilder::$batchActions}.
     */
    private bool $autoAddingBatchCheckboxColumn = true;

    /**
     * The row action builders defined for the data table.
     *
     * @var array<ActionBuilderInterface>
     */
    private array $rowActions = [];

    /**
     * The data of row actions that haven't been converted to action builders yet.
     *
     * @var array<array{0: class-string<ActionTypeInterface>, 1: array}>
     */
    private array $unresolvedRowActions = [];

    /**
     * Determines whether the builder should automatically add {@see ActionsColumnType}
     * when at least one row action is defined in {@see DataTableBuilder::$rowActions}.
     */
    private bool $autoAddingActionsColumn = true;

    /**
     * The exporter builders defined for the data table.
     *
     * @var array<ExporterBuilderInterface>
     */
    private array $exporters = [];

    /**
     * The data of exporters that haven't been converted to exporter builders yet.
     *
     * @var array<array{0: class-string<ExporterTypeInterface>, 1: array}>
     */
    private array $unresolvedExporters = [];

    /**
     * Factory used to create instances of {@see ColumnInterface} or {@see ColumnBuilderInterface}.
     */
    private ColumnFactoryInterface $columnFactory;

    /**
     * Factory used to create instances of {@see FilterInterface} or {@see FilterBuilderInterface}.
     */
    private FilterFactoryInterface $filterFactory;

    /**
     * Factory used to create instances of {@see ActionInterface} or {@see ActionBuilderInterface}.
     */
    private ActionFactoryInterface $actionFactory;

    /**
     * Factory used to create instances of {@see ExporterInterface} or {@see ExporterBuilderInterface}.
     */
    private ExporterFactoryInterface $exporterFactory;

    /**
     * Determines whether the data table exporting feature is enabled.
     */
    private bool $exportingEnabled = true;

    /**
     * Form factory used to create an export form.
     */
    private null|FormFactoryInterface $exportFormFactory = null;

    /**
     * Default export data, which is applied to the data table if no data is given by the user.
     */
    private null|ExportData $defaultExportData = null;

    /**
     * Determines whether the data table personalization feature is enabled.
     */
    private bool $personalizationEnabled = true;

    /**
     * Determines whether the data table personalization persistence feature is enabled.
     */
    private bool $personalizationPersistenceEnabled = true;

    /**
     * Persistence adapter used to read/write personalization feature data.
     */
    private null|PersistenceAdapterInterface $personalizationPersistenceAdapter = null;

    /**
     * Provider used to retrieve persistence subject (e.g. logged-in user) used to associate with the personalization data.
     */
    private null|PersistenceSubjectProviderInterface $personalizationPersistenceSubjectProvider = null;

    /**
     * Form factory used to create a personalization form.
     */
    private null|FormFactoryInterface $personalizationFormFactory = null;

    /**
     * Default personalization data, which is applied to the data table if no data is given by the user.
     */
    private null|PersonalizationData $defaultPersonalizationData = null;

    /**
     * Determines whether the data table filtration feature is enabled.
     */
    private bool $filtrationEnabled = true;

    /**
     * Determines whether the data table filtration persistence feature is enabled.
     */
    private bool $filtrationPersistenceEnabled = true;

    /**
     * Persistence adapter used to read/write filtration feature data.
     */
    private null|PersistenceAdapterInterface $filtrationPersistenceAdapter = null;

    /**
     * Provider used to retrieve persistence subject (e.g. logged-in user) used to associate with the filtration data.
     */
    private null|PersistenceSubjectProviderInterface $filtrationPersistenceSubjectProvider = null;

    /**
     * Form factory used to create a filtration form.
     */
    private null|FormFactoryInterface $filtrationFormFactory = null;

    /**
     * Default filtration data, which is applied to the data table if no data is given by the user.
     */
    private null|FiltrationData $defaultFiltrationData = null;

    /**
     * Determines whether the data table sorting feature is enabled.
     */
    private bool $sortingEnabled = true;

    /**
     * Determines whether the data table sorting persistence feature is enabled.
     */
    private bool $sortingPersistenceEnabled = true;

    /**
     * Persistence adapter used to read/write sorting feature data.
     */
    private null|PersistenceAdapterInterface $sortingPersistenceAdapter = null;

    /**
     * Provider used to retrieve persistence subject (e.g. logged-in user) used to associate with the sorting data.
     */
    private null|PersistenceSubjectProviderInterface $sortingPersistenceSubjectProvider = null;

    /**
     * Default sorting data, which is applied to the data table if no data is given by the user.
     */
    private null|SortingData $defaultSortingData = null;

    /**
     * Determines whether the data table pagination feature is enabled.
     */
    private bool $paginationEnabled = true;

    /**
     * Determines whether the data table pagination persistence feature is enabled.
     */
    private bool $paginationPersistenceEnabled = true;

    /**
     * Persistence adapter used to read/write pagination feature data.
     */
    private null|PersistenceAdapterInterface $paginationPersistenceAdapter = null;

    /**
     * Provider used to retrieve persistence subject (e.g. logged-in user) used to associate with the pagination data.
     */
    private null|PersistenceSubjectProviderInterface $paginationPersistenceSubjectProvider = null;

    /**
     * Default pagination data, which is applied to the data table if no data is given by the user.
     */
    private null|PaginationData $defaultPaginationData = null;

    /**
     * Request handler class used to automatically apply data from request to the data table.
     */
    private null|RequestHandlerInterface $requestHandler = null;

    /**
     * Represents HTML attributes rendered on header row.
     */
    private array $headerRowAttributes = [];

    /**
     * Represents HTML attributes rendered on each value row.
     */
    private array $valueRowAttributes = [];

    /**
     * Determines whether the builder is locked, therefore no setters can be called.
     */
    private bool $locked = false;

    public function __construct(
        private string $name,
        private ?ProxyQueryInterface $query,
        private EventDispatcherInterface $dispatcher,
        private array $options = [],
    ) {
    }

    public function __clone(): void
    {
        $this->query = clone $this->query;
    }

    public function getEventDispatcher(): EventDispatcherInterface
    {
        if (!$this->dispatcher instanceof ImmutableEventDispatcher) {
            $this->dispatcher = new ImmutableEventDispatcher($this->dispatcher);
        }

        return $this->dispatcher;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;

        return $this;
    }

    public function getType(): ResolvedDataTableTypeInterface
    {
        return $this->type;
    }

    public function setType(ResolvedDataTableTypeInterface $type): static
    {
        $this->type = $type;

        return $this;
    }

    public function getQuery(): ?ProxyQueryInterface
    {
        return $this->query;
    }

    public function setQuery(?ProxyQueryInterface $query): static
    {
        $this->query = $query;

        return $this;
    }

    public function getOptions(): array
    {
        return $this->options;
    }

    public function setOptions(array $options): static
    {
        $this->options = $options;

        return $this;
    }

    public function getThemes(): array
    {
        return $this->themes;
    }

    public function setThemes(array $themes): static
    {
        $this->themes = $themes;

        return $this;
    }

    public function addTheme(string $theme): static
    {
        $this->themes[] = $theme;

        return $this;
    }

    public function removeTheme(string $theme): static
    {
        if (false !== $key = array_search($theme, $this->themes, true)) {
            unset($this->themes[$key]);
        }

        return $this;
    }

    public function getTitle(): null|string|TranslatableMessage
    {
        return $this->title;
    }

    public function setTitle(null|string|TranslatableMessage $title): static
    {
        $this->title = $title;

        return $this;
    }

    public function getTitleTranslationParameters(): array
    {
        return $this->titleTranslationParameters;
    }

    public function setTitleTranslationParameters(array $titleTranslationParameters): static
    {
        $this->titleTranslationParameters = $titleTranslationParameters;

        return $this;
    }

    public function getTranslationDomain(): null|bool|string
    {
        return $this->translationDomain;
    }

    public function setTranslationDomain(null|bool|string $translationDomain): static
    {
        $this->translationDomain = $translationDomain;

        return $this;
    }

    public function getColumns(): array
    {
        $this->resolveColumns();

        return $this->columns;
    }

    public function getColumn(string $name): ColumnBuilderInterface
    {
        if (isset($this->unresolvedColumns[$name])) {
            return $this->resolveColumn($name);
        }

        if (isset($this->columns[$name])) {
            return $this->columns[$name];
        }

        throw new InvalidArgumentException(sprintf('The column with the name "%s" does not exist.', $name));
    }

    public function addColumn(ColumnBuilderInterface|string $column, string $type = ColumnType::class, array $options = []): static
    {
        if ($column instanceof ColumnBuilderInterface) {
            $this->columns[$column->getName()] = $column;

            unset($this->unresolvedColumns[$column->getName()]);

            return $this;
        }

        $this->columns[$column] = null;
        $this->unresolvedColumns[$column] = [$type, $options];

        return $this;
    }

    public function hasColumn(string $name): bool
    {
        return array_key_exists($name, $this->columns)
            || array_key_exists($name, $this->unresolvedColumns);
    }

    public function removeColumn(string $name): static
    {
        unset($this->unresolvedColumns[$name], $this->columns[$name]);

        return $this;
    }

    public function getFilters(): array
    {
        $this->resolveFilters();

        return $this->filters;
    }

    public function getFilter(string $name): FilterBuilderInterface
    {
        if (isset($this->unresolvedFilters[$name])) {
            return $this->resolveFilter($name);
        }

        if (isset($this->filters[$name])) {
            return $this->filters[$name];
        }

        throw new InvalidArgumentException(sprintf('The filter with the name "%s" does not exist.', $name));
    }

    public function addFilter(string|FilterBuilderInterface $filter, string $type = FilterType::class, array $options = []): static
    {
        if ($filter instanceof FilterBuilderInterface) {
            $this->filters[$filter->getName()] = $filter;

            unset($this->unresolvedFilters[$filter->getName()]);

            return $this;
        }

        $this->filters[$filter] = null;
        $this->unresolvedFilters[$filter] = [$type, $options];

        return $this;
    }

    public function hasFilter(string $name): bool
    {
        return array_key_exists($name, $this->filters)
            || array_key_exists($name, $this->unresolvedFilters);
    }

    public function removeFilter(string $name): static
    {
        unset($this->unresolvedFilters[$name], $this->filters[$name]);

        return $this;
    }

    public function getSearchHandler(): ?callable
    {
        return $this->searchHandler;
    }

    public function setSearchHandler(?callable $searchHandler): static
    {
        $this->searchHandler = $searchHandler;

        return $this;
    }

    public function isAutoAddingSearchFilter(): bool
    {
        return $this->autoAddingSearchFilter;
    }

    public function setAutoAddingSearchFilter(bool $autoAddingSearchFilter): static
    {
        $this->autoAddingSearchFilter = $autoAddingSearchFilter;

        return $this;
    }

    public function getActions(): array
    {
        $this->resolveActions();

        return $this->actions;
    }

    public function getAction(string $name): ActionBuilderInterface
    {
        if (isset($this->unresolvedActions[$name])) {
            return $this->resolveAction($name);
        }

        if (isset($this->actions[$name])) {
            return $this->actions[$name];
        }

        throw new InvalidArgumentException(sprintf('The action with the name "%s" does not exist.', $name));
    }

    public function addAction(string|ActionBuilderInterface $action, string $type = ActionType::class, array $options = []): static
    {
        if ($action instanceof ActionBuilderInterface) {
            $this->actions[$action->getName()] = $action;

            unset($this->unresolvedActions[$action->getName()]);

            return $this;
        }

        $this->actions[$action] = null;
        $this->unresolvedActions[$action] = [$type, $options];

        return $this;
    }

    public function hasAction(string $name): bool
    {
        return array_key_exists($name, $this->actions)
            || array_key_exists($name, $this->unresolvedActions);
    }

    public function removeAction(string $name): static
    {
        unset($this->unresolvedActions[$name], $this->actions[$name]);

        return $this;
    }

    public function getBatchActions(): array
    {
        $this->resolveBatchActions();

        return $this->batchActions;
    }

    public function getBatchAction(string $name): ActionBuilderInterface
    {
        if (isset($this->unresolvedBatchActions[$name])) {
            return $this->resolveBatchAction($name);
        }

        if (isset($this->batchActions[$name])) {
            return $this->batchActions[$name];
        }

        throw new InvalidArgumentException(sprintf('The batch action with the name "%s" does not exist.', $name));
    }

    public function hasBatchAction(string $name): bool
    {
        return array_key_exists($name, $this->batchActions)
            || array_key_exists($name, $this->unresolvedBatchActions);
    }

    public function addBatchAction(string|ActionBuilderInterface $action, string $type = ActionType::class, array $options = []): static
    {
        if ($action instanceof ActionBuilderInterface) {
            $this->batchActions[$action->getName()] = $action;

            unset($this->unresolvedBatchActions[$action->getName()]);

            return $this;
        }

        $this->batchActions[$action] = null;
        $this->unresolvedBatchActions[$action] = [$type, $options];

        return $this;
    }

    public function removeBatchAction(string $name): static
    {
        unset($this->unresolvedActions[$name], $this->batchActions[$name]);

        return $this;
    }

    public function isAutoAddingBatchCheckboxColumn(): bool
    {
        return $this->autoAddingBatchCheckboxColumn;
    }

    public function setAutoAddingBatchCheckboxColumn(bool $autoAddingBatchCheckboxColumn): static
    {
        $this->autoAddingBatchCheckboxColumn = $autoAddingBatchCheckboxColumn;

        return $this;
    }

    public function getRowActions(): array
    {
        $this->resolveRowActions();

        return $this->rowActions;
    }

    public function getRowAction(string $name): ActionBuilderInterface
    {
        if (isset($this->unresolvedRowActions[$name])) {
            return $this->resolveRowAction($name);
        }

        if (isset($this->rowActions[$name])) {
            return $this->rowActions[$name];
        }

        throw new InvalidArgumentException(sprintf('The row action with the name "%s" does not exist.', $name));
    }

    public function hasRowAction(string $name): bool
    {
        return array_key_exists($name, $this->rowActions)
            || array_key_exists($name, $this->unresolvedRowActions);
    }

    public function addRowAction(string|ActionBuilderInterface $action, string $type = ActionType::class, array $options = []): static
    {
        if ($action instanceof ActionBuilderInterface) {
            $this->rowActions[$action->getName()] = $action;

            unset($this->unresolvedRowActions[$action->getName()]);

            return $this;
        }

        $this->rowActions[$action] = null;
        $this->unresolvedRowActions[$action] = [$type, $options];

        return $this;
    }

    public function removeRowAction(string $name): static
    {
        unset($this->unresolvedActions[$name], $this->rowActions[$name]);

        return $this;
    }

    public function isAutoAddingActionsColumn(): bool
    {
        return $this->autoAddingActionsColumn;
    }

    public function setAutoAddingActionsColumn(bool $autoAddingActionsColumn): static
    {
        $this->autoAddingActionsColumn = $autoAddingActionsColumn;

        return $this;
    }

    public function getExporters(): array
    {
        $this->resolveExporters();

        return $this->exporters;
    }

    public function getExporter(string $name): ExporterBuilderInterface
    {
        if (isset($this->unresolvedExporters[$name])) {
            return $this->resolveExporter($name);
        }

        if (isset($this->exporters[$name])) {
            return $this->exporters[$name];
        }

        throw new InvalidArgumentException(sprintf('The exporter with the name "%s" does not exist.', $name));
    }

    public function addExporter(string|ExporterBuilderInterface $exporter, string $type = ExporterType::class, array $options = []): static
    {
        if ($exporter instanceof ColumnBuilderInterface) {
            $this->exporters[$exporter->getName()] = $exporter;

            unset($this->unresolvedExporters[$exporter->getName()]);

            return $this;
        }

        $this->exporters[$exporter] = null;
        $this->unresolvedExporters[$exporter] = [$type, $options];

        return $this;
    }

    public function hasExporter(string $name): bool
    {
        return array_key_exists($name, $this->exporters)
            || array_key_exists($name, $this->unresolvedExporters);
    }

    public function removeExporter(string $name): static
    {
        unset($this->unresolvedExporters[$name], $this->exporters[$name]);

        return $this;
    }

    public function getColumnFactory(): ColumnFactoryInterface
    {
        return $this->columnFactory;
    }

    public function setColumnFactory(ColumnFactoryInterface $columnFactory): static
    {
        $this->columnFactory = $columnFactory;

        return $this;
    }

    public function getFilterFactory(): FilterFactoryInterface
    {
        return $this->filterFactory;
    }

    public function setFilterFactory(FilterFactoryInterface $filterFactory): static
    {
        $this->filterFactory = $filterFactory;

        return $this;
    }

    public function getActionFactory(): ActionFactoryInterface
    {
        return $this->actionFactory;
    }

    public function setActionFactory(ActionFactoryInterface $actionFactory): static
    {
        $this->actionFactory = $actionFactory;

        return $this;
    }

    public function getExporterFactory(): ExporterFactoryInterface
    {
        return $this->exporterFactory;
    }

    public function setExporterFactory(ExporterFactoryInterface $exporterFactory): static
    {
        $this->exporterFactory = $exporterFactory;

        return $this;
    }

    public function isExportingEnabled(): bool
    {
        return $this->exportingEnabled;
    }

    public function setExportingEnabled(bool $exportingEnabled): static
    {
        $this->exportingEnabled = $exportingEnabled;

        return $this;
    }

    public function getExportFormFactory(): ?FormFactoryInterface
    {
        return $this->exportFormFactory;
    }

    public function setExportFormFactory(?FormFactoryInterface $exportFormFactory): static
    {
        $this->exportFormFactory = $exportFormFactory;

        return $this;
    }

    public function getDefaultExportData(): ?ExportData
    {
        return $this->defaultExportData;
    }

    public function setDefaultExportData(?ExportData $defaultExportData): static
    {
        $this->defaultExportData = $defaultExportData;

        return $this;
    }

    public function isPersonalizationEnabled(): bool
    {
        return $this->personalizationEnabled;
    }

    public function setPersonalizationEnabled(bool $personalizationEnabled): static
    {
        $this->personalizationEnabled = $personalizationEnabled;

        return $this;
    }

    public function isPersonalizationPersistenceEnabled(): bool
    {
        return $this->personalizationPersistenceEnabled;
    }

    public function setPersonalizationPersistenceEnabled(bool $personalizationPersistenceEnabled): static
    {
        $this->personalizationPersistenceEnabled = $personalizationPersistenceEnabled;

        return $this;
    }

    public function getPersonalizationPersistenceAdapter(): ?PersistenceAdapterInterface
    {
        return $this->personalizationPersistenceAdapter;
    }

    public function setPersonalizationPersistenceAdapter(?PersistenceAdapterInterface $personalizationPersistenceAdapter): static
    {
        $this->personalizationPersistenceAdapter = $personalizationPersistenceAdapter;

        return $this;
    }

    public function getPersonalizationPersistenceSubjectProvider(): ?PersistenceSubjectProviderInterface
    {
        return $this->personalizationPersistenceSubjectProvider;
    }

    public function setPersonalizationPersistenceSubjectProvider(?PersistenceSubjectProviderInterface $personalizationPersistenceSubjectProvider): static
    {
        $this->personalizationPersistenceSubjectProvider = $personalizationPersistenceSubjectProvider;

        return $this;
    }

    public function getPersonalizationFormFactory(): FormFactoryInterface
    {
        return $this->personalizationFormFactory;
    }

    public function setPersonalizationFormFactory(?FormFactoryInterface $personalizationFormFactory): static
    {
        $this->personalizationFormFactory = $personalizationFormFactory;

        return $this;
    }

    public function getDefaultPersonalizationData(): ?PersonalizationData
    {
        return $this->defaultPersonalizationData;
    }

    public function setDefaultPersonalizationData(?PersonalizationData $defaultPersonalizationData): static
    {
        $this->defaultPersonalizationData = $defaultPersonalizationData;

        return $this;
    }

    public function isFiltrationEnabled(): bool
    {
        return $this->filtrationEnabled;
    }

    public function setFiltrationEnabled(bool $filtrationEnabled): static
    {
        $this->filtrationEnabled = $filtrationEnabled;

        return $this;
    }

    public function isFiltrationPersistenceEnabled(): bool
    {
        return $this->filtrationPersistenceEnabled;
    }

    public function setFiltrationPersistenceEnabled(bool $filtrationPersistenceEnabled): static
    {
        $this->filtrationPersistenceEnabled = $filtrationPersistenceEnabled;

        return $this;
    }

    public function getFiltrationPersistenceAdapter(): ?PersistenceAdapterInterface
    {
        return $this->filtrationPersistenceAdapter;
    }

    public function setFiltrationPersistenceAdapter(?PersistenceAdapterInterface $filtrationPersistenceAdapter): static
    {
        $this->filtrationPersistenceAdapter = $filtrationPersistenceAdapter;

        return $this;
    }

    public function getFiltrationPersistenceSubjectProvider(): ?PersistenceSubjectProviderInterface
    {
        return $this->filtrationPersistenceSubjectProvider;
    }

    public function setFiltrationPersistenceSubjectProvider(?PersistenceSubjectProviderInterface $filtrationPersistenceSubjectProvider): static
    {
        $this->filtrationPersistenceSubjectProvider = $filtrationPersistenceSubjectProvider;

        return $this;
    }

    public function getFiltrationFormFactory(): ?FormFactoryInterface
    {
        return $this->filtrationFormFactory;
    }

    public function setFiltrationFormFactory(?FormFactoryInterface $filtrationFormFactory): static
    {
        $this->filtrationFormFactory = $filtrationFormFactory;

        return $this;
    }

    public function getDefaultFiltrationData(): ?FiltrationData
    {
        return $this->defaultFiltrationData;
    }

    public function setDefaultFiltrationData(?FiltrationData $defaultFiltrationData): static
    {
        $this->defaultFiltrationData = $defaultFiltrationData;

        return $this;
    }

    public function isSortingEnabled(): bool
    {
        return $this->sortingEnabled;
    }

    public function setSortingEnabled(bool $sortingEnabled): static
    {
        $this->sortingEnabled = $sortingEnabled;

        return $this;
    }

    public function isSortingPersistenceEnabled(): bool
    {
        return $this->sortingPersistenceEnabled;
    }

    public function setSortingPersistenceEnabled(bool $sortingPersistenceEnabled): static
    {
        $this->sortingPersistenceEnabled = $sortingPersistenceEnabled;

        return $this;
    }

    public function getSortingPersistenceAdapter(): ?PersistenceAdapterInterface
    {
        return $this->sortingPersistenceAdapter;
    }

    public function setSortingPersistenceAdapter(?PersistenceAdapterInterface $sortingPersistenceAdapter): static
    {
        $this->sortingPersistenceAdapter = $sortingPersistenceAdapter;

        return $this;
    }

    public function getSortingPersistenceSubjectProvider(): ?PersistenceSubjectProviderInterface
    {
        return $this->sortingPersistenceSubjectProvider;
    }

    public function setSortingPersistenceSubjectProvider(?PersistenceSubjectProviderInterface $sortingPersistenceSubjectProvider): static
    {
        $this->sortingPersistenceSubjectProvider = $sortingPersistenceSubjectProvider;

        return $this;
    }

    public function getDefaultSortingData(): ?SortingData
    {
        return $this->defaultSortingData;
    }

    public function setDefaultSortingData(?SortingData $defaultSortingData): static
    {
        $this->defaultSortingData = $defaultSortingData;

        return $this;
    }

    public function isPaginationEnabled(): bool
    {
        return $this->paginationEnabled;
    }

    public function setPaginationEnabled(bool $paginationEnabled): static
    {
        $this->paginationEnabled = $paginationEnabled;

        return $this;
    }

    public function isPaginationPersistenceEnabled(): bool
    {
        return $this->paginationPersistenceEnabled;
    }

    public function setPaginationPersistenceEnabled(bool $paginationPersistenceEnabled): static
    {
        $this->paginationPersistenceEnabled = $paginationPersistenceEnabled;

        return $this;
    }

    public function getPaginationPersistenceAdapter(): ?PersistenceAdapterInterface
    {
        return $this->paginationPersistenceAdapter;
    }

    public function setPaginationPersistenceAdapter(?PersistenceAdapterInterface $paginationPersistenceAdapter): static
    {
        $this->paginationPersistenceAdapter = $paginationPersistenceAdapter;

        return $this;
    }

    public function getPaginationPersistenceSubjectProvider(): ?PersistenceSubjectProviderInterface
    {
        return $this->paginationPersistenceSubjectProvider;
    }

    public function setPaginationPersistenceSubjectProvider(?PersistenceSubjectProviderInterface $paginationPersistenceSubjectProvider): static
    {
        $this->paginationPersistenceSubjectProvider = $paginationPersistenceSubjectProvider;

        return $this;
    }

    public function getDefaultPaginationData(): ?PaginationData
    {
        return $this->defaultPaginationData;
    }

    public function setDefaultPaginationData(?PaginationData $defaultPaginationData): static
    {
        $this->defaultPaginationData = $defaultPaginationData;

        return $this;
    }

    public function getRequestHandler(): ?RequestHandlerInterface
    {
        return $this->requestHandler;
    }

    public function setRequestHandler(?RequestHandlerInterface $requestHandler): static
    {
        $this->requestHandler = $requestHandler;

        return $this;
    }

    public function getHeaderRowAttributes(): array
    {
        return $this->headerRowAttributes;
    }

    public function setHeaderRowAttributes(array $headerRowAttributes): static
    {
        $this->headerRowAttributes = $headerRowAttributes;

        return $this;
    }

    public function getValueRowAttributes(): array
    {
        return $this->valueRowAttributes;
    }

    public function setValueRowAttributes(array $valueRowAttributes): static
    {
        $this->valueRowAttributes = $valueRowAttributes;

        return $this;
    }

    public function getPageParameterName(): string
    {
        return $this->getParameterName(static::PAGE_PARAMETER);
    }

    public function getPerPageParameterName(): string
    {
        return $this->getParameterName(static::PER_PAGE_PARAMETER);
    }

    public function getSortParameterName(): string
    {
        return $this->getParameterName(static::SORT_PARAMETER);
    }

    public function getFiltrationParameterName(): string
    {
        return $this->getParameterName(static::FILTRATION_PARAMETER);
    }

    public function getPersonalizationParameterName(): string
    {
        return $this->getParameterName(static::PERSONALIZATION_PARAMETER);
    }

    public function getExportParameterName(): string
    {
        return $this->getParameterName(static::EXPORT_PARAMETER);
    }

    public function addEventListener(string $eventName, callable $listener, int $priority = 0): static
    {
        $this->dispatcher->addListener($eventName, $listener, $priority);

        return $this;
    }

    public function addEventSubscriber(EventSubscriberInterface $subscriber): static
    {
        $this->dispatcher->addSubscriber($subscriber);

        return $this;
    }

    public function getDataTable(): DataTableInterface
    {
        $dataTable = new DataTable(clone $this->query, $this->getDataTableConfig());

        if ($this->shouldPrependBatchCheckboxColumn()) {
            $this->prependBatchCheckboxColumn();
        }

        if ($this->shouldAppendActionsColumn()) {
            $this->appendActionsColumn();
        }

        if ($this->shouldAddSearchFilter()) {
            $this->addSearchFilter();
        }

        $this->resolveColumns();

        foreach ($this->columns as $column) {
            $dataTable->addColumn($column->getColumn());
        }

        $this->resolveFilters();

        foreach ($this->filters as $filter) {
            $dataTable->addFilter($filter->getFilter());
        }

        $this->resolveActions();

        foreach ($this->actions as $action) {
            $dataTable->addAction($action->getAction());
        }

        $this->resolveBatchActions();

        foreach ($this->batchActions as $batchAction) {
            $dataTable->addBatchAction($batchAction->getAction());
        }

        $this->resolveRowActions();

        foreach ($this->rowActions as $rowAction) {
            $dataTable->addRowAction($rowAction->getAction());
        }

        $this->resolveExporters();

        foreach ($this->exporters as $exporter) {
            $dataTable->addExporter($exporter->getExporter());
        }

        // TODO: Remove initialization logic from builder.
        //       Instead, add "initialized" flag to the data table itself to allow lazy initialization.
        $dataTable->initialize();

        return $dataTable;
    }

    public function getDataTableConfig(): DataTableConfigInterface
    {
        $config = clone $this;
        $config->locked = true;

        return $config;
    }

    private function resolveColumn(string $name): ColumnBuilderInterface
    {
        [$type, $options] = $this->unresolvedColumns[$name];

        unset($this->unresolvedColumns[$name]);

        return $this->columns[$name] = $this->getColumnFactory()->createNamedBuilder($name, $type, $options);
    }

    private function resolveColumns(): void
    {
        foreach (array_keys($this->unresolvedColumns) as $column) {
            $this->resolveColumn($column);
        }
    }

    private function resolveFilter(string $name): FilterBuilderInterface
    {
        [$type, $options] = $this->unresolvedFilters[$name];

        unset($this->unresolvedFilters[$name]);

        return $this->filters[$name] = $this->getFilterFactory()->createNamedBuilder($name, $type, $options);
    }

    private function resolveFilters(): void
    {
        foreach (array_keys($this->unresolvedFilters) as $filter) {
            $this->resolveFilter($filter);
        }
    }

    private function resolveAction(string $name): ActionBuilderInterface
    {
        [$type, $options] = $this->unresolvedActions[$name];

        unset($this->unresolvedActions[$name]);

        $action = $this->getActionFactory()->createNamedBuilder($name, $type, $options);
        $action->setContext(ActionContext::Global);

        return $this->actions[$name] = $action;
    }

    private function resolveActions(): void
    {
        foreach (array_keys($this->unresolvedActions) as $action) {
            $this->resolveAction($action);
        }
    }

    private function resolveBatchAction(string $name): ActionBuilderInterface
    {
        [$type, $options] = $this->unresolvedBatchActions[$name];

        unset($this->unresolvedBatchActions[$name]);

        $batchAction = $this->getActionFactory()->createNamedBuilder($name, $type, $options);
        $batchAction->setContext(ActionContext::Batch);

        return $this->batchActions[$name] = $batchAction;
    }

    private function resolveBatchActions(): void
    {
        foreach (array_keys($this->unresolvedBatchActions) as $batchAction) {
            $this->resolveBatchAction($batchAction);
        }
    }

    private function resolveRowAction(string $name): ActionBuilderInterface
    {
        [$type, $options] = $this->unresolvedRowActions[$name];

        unset($this->unresolvedRowActions[$name]);

        $rowAction = $this->getActionFactory()->createNamedBuilder($name, $type, $options);
        $rowAction->setContext(ActionContext::Row);

        return $this->rowActions[$name] = $rowAction;
    }

    private function resolveRowActions(): void
    {
        foreach (array_keys($this->unresolvedRowActions) as $rowAction) {
            $this->resolveRowAction($rowAction);
        }
    }

    private function resolveExporter(string $name): ExporterBuilderInterface
    {
        [$type, $options] = $this->unresolvedExporters[$name];

        unset($this->unresolvedExporters[$name]);

        return $this->exporters[$name] = $this->getExporterFactory()->createNamedBuilder($name, $type, $options);
    }

    private function resolveExporters(): void
    {
        foreach (array_keys($this->unresolvedExporters) as $exporter) {
            $this->resolveExporter($exporter);
        }
    }

    private function getParameterName(string $prefix): string
    {
        return implode('_', array_filter([$prefix, $this->name]));
    }

    private function shouldPrependBatchCheckboxColumn(): bool
    {
        return $this->isAutoAddingBatchCheckboxColumn()
            && !empty($this->batchActions)
            && !$this->hasColumn(self::BATCH_CHECKBOX_COLUMN_NAME);
    }

    private function shouldAppendActionsColumn(): bool
    {
        return $this->isAutoAddingActionsColumn()
            && !empty($this->rowActions)
            && !$this->hasColumn(self::ACTIONS_COLUMN_NAME);
    }

    private function shouldAddSearchFilter(): bool
    {
        return $this->isAutoAddingSearchFilter()
            && null !== $this->getSearchHandler()
            && !$this->hasFilter(self::SEARCH_FILTER_NAME);
    }

    private function prependBatchCheckboxColumn(): void
    {
        $this->addColumn(self::BATCH_CHECKBOX_COLUMN_NAME, CheckboxColumnType::class);

        // TODO: Remove this logic after adding the column "priority" option.
        $this->columns = [
            self::BATCH_CHECKBOX_COLUMN_NAME => $this->getColumn(self::BATCH_CHECKBOX_COLUMN_NAME),
            ...$this->columns,
        ];
    }

    private function appendActionsColumn(): void
    {
        $this->addColumn(self::ACTIONS_COLUMN_NAME, ActionsColumnType::class, [
            'actions' => $this->getRowActions(),
        ]);
    }

    private function addSearchFilter(): void
    {
        $this->addFilter(self::SEARCH_FILTER_NAME, SearchFilterType::class, [
            'handler' => $this->getSearchHandler(),
        ]);
    }
}
