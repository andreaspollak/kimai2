<?php

/*
 * This file is part of the Kimai time-tracking app.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Validator\Constraints;

use App\Activity\ActivityStatisticService;
use App\Configuration\SystemConfiguration;
use App\Customer\CustomerStatisticService;
use App\Entity\Timesheet;
use App\Model\BudgetStatisticModel;
use App\Project\ProjectStatisticService;
use App\Repository\TimesheetRepository;
use App\Timesheet\RateServiceInterface;
use App\Utils\Duration;
use App\Utils\LocaleHelper;
use DateTime;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;

final class TimesheetBudgetUsedValidator extends ConstraintValidator
{
    private $customerStatisticService;
    private $projectStatisticService;
    private $activityStatisticService;
    private $timesheetRepository;
    private $rateService;
    private $configuration;

    public function __construct(SystemConfiguration $configuration, CustomerStatisticService $customerStatisticService, ProjectStatisticService $projectStatisticService, ActivityStatisticService $activityStatisticService, TimesheetRepository $timesheetRepository, RateServiceInterface $rateService)
    {
        $this->configuration = $configuration;
        $this->customerStatisticService = $customerStatisticService;
        $this->projectStatisticService = $projectStatisticService;
        $this->activityStatisticService = $activityStatisticService;
        $this->timesheetRepository = $timesheetRepository;
        $this->rateService = $rateService;
    }

    /**
     * @param Timesheet $timesheet
     * @param Constraint $constraint
     */
    public function validate($timesheet, Constraint $constraint)
    {
        if (!($constraint instanceof TimesheetBudgetUsed)) {
            throw new UnexpectedTypeException($constraint, TimesheetBudgetUsed::class);
        }

        if (!\is_object($timesheet) || !($timesheet instanceof Timesheet)) {
            throw new UnexpectedTypeException($timesheet, Timesheet::class);
        }

        if ($this->configuration->isTimesheetAllowOverbookingBudget()) {
            return;
        }

        // we can only work with stopped entries
        if (null === $timesheet->getEnd() || null === $timesheet->getUser()) {
            return;
        }

        // budgets need only be calculated for billable records
        if (!$timesheet->isBillable()) {
            return;
        }

        $duration = $timesheet->getDuration();
        if (null === $duration || 0 === $duration) {
            $duration = $timesheet->getEnd()->getTimestamp() - $timesheet->getBegin()->getTimestamp();
        }

        // this validator needs a project to calculate the rates
        if ($timesheet->getProject() === null) {
            return;
        }

        $timeRate = $this->rateService->calculate($timesheet);
        $rate = $timeRate->getRate();

        $activityDuration = $duration;
        $activityRate = $rate;
        $projectDuration = $duration;
        $projectRate = $rate;
        $customerDuration = $duration;
        $customerRate = $rate;

        if ($timesheet->getId() !== null) {
            $rawData = $this->timesheetRepository->getRawData($timesheet);

            // if an existing entry was updated, but "duration", "rate" and "billable" were not changed:
            // do not validate! this could for example happen when export flag is changed OR if "prevent overbooking"
            // config was recently activated and this is an old entry
            if ($duration === $rawData['duration'] && $rate === $rawData['rate'] && $timesheet->isBillable() === $rawData['billable'] && $timesheet->getBegin()->format('Y.m.d') === $rawData['begin']->format('Y.m.d')) {
                return;
            }

            // the duration of an existing entry could be increased or lowered
            $activityId = (int) $rawData['activity'];
            $projectId = (int) $rawData['project'];
            $customerId = (int) $rawData['customer'];

            // only subtract the previously logged data in case the record was billable
            // if it wasn't billable, then its values are not included in the statistic models used later on
            if ($rawData['billable']) {
                if (null !== $timesheet->getActivity() && $activityId === $timesheet->getActivity()->getId()) {
                    $activityDuration -= $rawData['duration'];
                    $activityRate -= $rawData['rate'];
                }

                if (null !== $timesheet->getProject()) {
                    if ($projectId === $timesheet->getProject()->getId()) {
                        $projectDuration -= $rawData['duration'];
                        $projectRate -= $rawData['rate'];
                    }

                    if ($customerId === $timesheet->getProject()->getCustomer()->getId()) {
                        $customerDuration -= $rawData['duration'];
                        $customerRate -= $rawData['rate'];
                    }
                }
            }
        }

        $now = new DateTime('now', $timesheet->getBegin()->getTimezone());
        $recordDate = $timesheet->getBegin();

        if (null !== ($activity = $timesheet->getActivity()) && $activity->hasBudgets()) {
            $dateTime = $activity->isMonthlyBudget() ? $recordDate : $now;
            $stat = $this->activityStatisticService->getBudgetStatisticModel($activity, $dateTime);
            $this->checkBudgets($constraint, $stat, $timesheet, $activityDuration, $activityRate, 'activity');
        }

        if (null !== ($project = $timesheet->getProject())) {
            if ($project->hasBudgets()) {
                $dateTime = $project->isMonthlyBudget() ? $recordDate : $now;
                $stat = $this->projectStatisticService->getBudgetStatisticModel($project, $dateTime);
                $this->checkBudgets($constraint, $stat, $timesheet, $projectDuration, $projectRate, 'project');
            }
            if (null !== ($customer = $project->getCustomer()) && $customer->hasBudgets()) {
                $dateTime = $customer->isMonthlyBudget() ? $recordDate : $now;
                $stat = $this->customerStatisticService->getBudgetStatisticModel($customer, $dateTime);
                $this->checkBudgets($constraint, $stat, $timesheet, $customerDuration, $customerRate, 'customer');
            }
        }
    }

    private function checkBudgets(TimesheetBudgetUsed $constraint, BudgetStatisticModel $stat, Timesheet $timesheet, int $duration, float $rate, string $field): bool
    {
        $fullRate = ($stat->getBudgetSpent() + $rate);

        if ($stat->hasBudget() && $fullRate > $stat->getBudget()) {
            $this->addBudgetViolation($constraint, $timesheet, $field, $stat->getBudget(), $stat->getBudgetSpent());

            return true;
        }

        $fullDuration = ($stat->getTimeBudgetSpent() + $duration);

        if ($stat->hasTimeBudget() && $fullDuration > $stat->getTimeBudget()) {
            $this->addTimeBudgetViolation($constraint, $field, $stat->getTimeBudget(), $stat->getTimeBudgetSpent());

            return true;
        }

        return false;
    }

    private function addBudgetViolation(TimesheetBudgetUsed $constraint, Timesheet $timesheet, string $field, float $budget, float $rate)
    {
        // using the locale of the assigned user is not the best solution, but allows to be independent of the request stack
        $helper = new LocaleHelper($timesheet->getUser()->getLanguage());
        $currency = $timesheet->getProject()->getCustomer()->getCurrency();

        $free = $budget - $rate;
        $free = max($free, 0);

        $this->context->buildViolation($constraint->messageRate)
            ->atPath($field)
            ->setTranslationDomain('validators')
            ->setParameters([
                '%used%' => $helper->money($rate, $currency),
                '%budget%' => $helper->money($budget, $currency),
                '%free%' => $helper->money($free, $currency)
            ])
            ->addViolation()
        ;
    }

    private function addTimeBudgetViolation(TimesheetBudgetUsed $constraint, string $field, int $budget, int $duration)
    {
        $durationFormat = new Duration();

        $free = $budget - $duration;
        $free = max($free, 0);

        $this->context->buildViolation($constraint->messageTime)
            ->atPath($field)
            ->setTranslationDomain('validators')
            ->setParameters([
                '%used%' => $durationFormat->format($duration),
                '%budget%' => $durationFormat->format($budget),
                '%free%' => $durationFormat->format($free)
            ])
            ->addViolation()
        ;
    }
}
