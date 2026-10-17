<?php

namespace Dgtlss\Clearance\Tests\Fixtures\Resources;

use Filament\Pages\Page;
use Illuminate\Support\Facades\Gate;

class ReviewPage extends Page
{
    public static function canAccess(): bool
    {
        return Gate::allows('review-orders');
    }
}
