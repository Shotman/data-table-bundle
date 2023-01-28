<?php

declare(strict_types=1);

namespace Kreyu\Bundle\DataTableBundle\Filter;

use Kreyu\Bundle\DataTableBundle\DataTableView;
use Kreyu\Bundle\DataTableBundle\Filter\Type\ResolvedFilterTypeInterface;
use Kreyu\Bundle\DataTableBundle\Query\ProxyQueryInterface;

class Filter implements FilterInterface
{
    public function __construct(
        private string $name,
        private ResolvedFilterTypeInterface $type,
        private array $options = [],
    ) {
    }

    public function apply(ProxyQueryInterface $query, FilterData $data): void
    {
        $this->type->apply($query, $data, $this);
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getFormName(): string
    {
        return str_replace('.', '__', $this->getName());
    }

    public function getFormOptions(): array
    {
        return [
            'field_type' => $this->options['field_type'],
            'field_options' => $this->options['field_options'],
            'operator_type' => $this->options['operator_type'],
            'operator_options' => $this->options['operator_options'],
            'label' => $this->options['label'],
        ];
    }

    public function getQueryPath(): string
    {
        return $this->options['query_path'] ?? $this->name;
    }

    public function getType(): ResolvedFilterTypeInterface
    {
        return $this->type;
    }

    public function getOptions(): array
    {
        return $this->options;
    }

    public function createView(DataTableView $parent = null): FilterView
    {
        $view = $this->type->createView($this, $parent);

        $this->type->buildView($view, $this, $this->options);

        return $view;
    }
}
