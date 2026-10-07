<?php
declare(strict_types=1);

namespace Panth\SaleFilter\Model;

class ActiveSaleFilter
{
    private ?array $allowedIds = null;

    private int $bypass = 0;

    public function setAllowedIds(array $ids): void
    {
        $this->allowedIds = array_values(array_map('intval', $ids));
    }

    public function getAllowedIds(): ?array
    {
        return $this->bypass > 0 ? null : $this->allowedIds;
    }

    public function runWithoutRestriction(callable $callback): mixed
    {
        $this->bypass++;
        try {
            return $callback();
        } finally {
            $this->bypass--;
        }
    }
}
