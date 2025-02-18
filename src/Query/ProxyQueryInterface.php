<?php

declare(strict_types=1);

namespace Kreyu\Bundle\DataTableBundle\Query;

use Kreyu\Bundle\DataTableBundle\Pagination\PaginationData;
use Kreyu\Bundle\DataTableBundle\Pagination\PaginationInterface;
use Kreyu\Bundle\DataTableBundle\Sorting\SortingData;

interface ProxyQueryInterface
{
    public function sort(SortingData $sortingData): void;

    public function paginate(PaginationData $paginationData): void;

    public function getPagination(): PaginationInterface;

    public function getItems(): iterable;
}
