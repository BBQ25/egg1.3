<?php

namespace Tests\Unit;

use App\Support\EggTrayFormatter;
use PHPUnit\Framework\TestCase;

class EggTrayFormatterTest extends TestCase
{
    public function test_it_formats_tray_equivalents_from_egg_counts(): void
    {
        $this->assertSame('1 tray', EggTrayFormatter::trayLabel(30));
        $this->assertSame('1/2 tray', EggTrayFormatter::trayLabel(15));
        $this->assertSame('1 tray + 1/2 tray', EggTrayFormatter::trayLabel(45));
        $this->assertSame('2 trays + 14 eggs', EggTrayFormatter::trayLabel(74));
    }
}
