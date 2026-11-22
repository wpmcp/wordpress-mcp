<?php

declare(strict_types=1);

namespace WPMCP\Modules\Gamma\Tools;

// No registry: ownership comes from the WPMCP\Modules\<Id>\ convention.
final class Gamma_Tool
{
    public function own(\WPMCP\Modules\Gamma\Value\Own $own): \WPMCP\Modules\Delta\Value\Thing
    {
        return new \WPMCP\Modules\Delta\Value\Thing($own);
    }
}
