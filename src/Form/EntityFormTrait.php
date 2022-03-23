<?php

/*
 * This file is part of the Kimai time-tracking app.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Form;

use App\Form\Type\BillableType;
use App\Form\Type\BudgetType;
use App\Form\Type\DurationType;
use App\Form\Type\MetaFieldsCollectionType;
use App\Form\Type\YesNoType;
use Symfony\Component\Form\Extension\Core\Type\MoneyType;
use Symfony\Component\Form\FormBuilderInterface;

trait EntityFormTrait
{
    use ColorTrait;

    public function addCommonFields(FormBuilderInterface $builder, array $options): void
    {
        $this->addColor($builder);

        if ($options['include_budget']) {
            $builder
                ->add('budget', MoneyType::class, [
                    'empty_data' => '0.00',
                    'label' => 'label.budget',
                    'required' => false,
                    'currency' => $options['currency'],
                ])
                ->add('timeBudget', DurationType::class, [
                    'empty_data' => 0,
                    'label' => 'label.timeBudget',
                    'icon' => 'clock',
                    'required' => false,
                ])
                ->add('budgetType', BudgetType::class)
            ;
        }

        $builder->add('metaFields', MetaFieldsCollectionType::class);

        $builder
            ->add('visible', YesNoType::class, [
                'label' => 'label.visible',
            ])
            ->add('billable', BillableType::class)
        ;
    }
}
