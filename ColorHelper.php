<?php

namespace Unlooped\Helper;

use Exception;
use Mexitek\PHPColors\Color;

class ColorHelper
{

    /**
     * @throws Exception
     */
    public static function hsl2Hex(int $h, float $s, float $l): string
    {
        $h %= 360;
        return '#' . Color::hslToHex([
            'H' => $h,
            'S' => $s,
            'L' => $l,
        ]);
    }

}
