<?php

namespace App\Support\Audit;

use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\Actions\LogActivityAction as BaseLogActivityAction;

class LogActivityAction extends BaseLogActivityAction
{
    protected function beforeActivityLogged(Model $activity): void
    {
        parent::beforeActivityLogged($activity);

        foreach (app(AuditContext::class)->toArray() as $column => $value) {
            $activity->{$column} ??= $value;
        }
    }
}
