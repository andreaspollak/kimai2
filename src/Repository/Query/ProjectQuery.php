<?php

/*
 * This file is part of the Kimai time-tracking app.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Repository\Query;

use App\Entity\Customer;

/**
 * Can be used for advanced queries with the: ProjectRepository
 */
class ProjectQuery extends BaseQuery implements VisibilityInterface
{
    use VisibilityTrait;

    public const PROJECT_ORDER_ALLOWED = [
        'id', 'name', 'comment', 'customer', 'orderNumber', 'orderDate', 'project_start', 'project_end', 'budget', 'timeBudget', 'visible'
    ];

    /**
     * @var array<Customer|int>
     */
    private $customers = [];
    /**
     * @var \DateTime|null
     */
    private $projectStart;
    /**
     * @var \DateTime|null
     */
    private $projectEnd;

    public function __construct()
    {
        $this->setDefaults([
            'orderBy' => 'name',
            'customers' => [],
            'projectStart' => null,
            'projectEnd' => null,
            'visibility' => VisibilityInterface::SHOW_VISIBLE,
        ]);
    }

    /**
     * @param Customer|int $customer
     * @return $this
     */
    public function addCustomer(Customer|int $customer)
    {
        $this->customers[] = $customer;

        return $this;
    }

    public function setCustomers(array $customers): self
    {
        $this->customers = $customers;

        return $this;
    }

    public function getCustomers(): array
    {
        return $this->customers;
    }

    public function hasCustomers(): bool
    {
        return !empty($this->customers);
    }

    public function getProjectStart(): ?\DateTime
    {
        return $this->projectStart;
    }

    public function setProjectStart(?\DateTime $projectStart): ProjectQuery
    {
        $this->projectStart = $projectStart;

        return $this;
    }

    public function getProjectEnd(): ?\DateTime
    {
        return $this->projectEnd;
    }

    public function setProjectEnd(?\DateTime $projectEnd): ProjectQuery
    {
        $this->projectEnd = $projectEnd;

        return $this;
    }
}
