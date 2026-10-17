<?php

namespace Dgtlss\Clearance\Tests\Fixtures;

use Illuminate\Routing\Controller;

class MissingActionController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth');
    }
}
