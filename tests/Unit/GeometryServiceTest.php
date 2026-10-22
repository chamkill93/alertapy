<?php

namespace Tests\Unit;

use App\Services\GeometryService;
use PHPUnit\Framework\TestCase;

class GeometryServiceTest extends TestCase
{
    public function test_core_geometry_operations_are_deterministic(): void
    {
        $geo = new GeometryService; $line = [[-57.6,-25.3],[-57.5,-25.3],[-57.4,-25.25]];
        $this->assertEqualsWithDelta(1000, $geo->haversine(-25.3,-57.6,-25.291,-57.6), 20);
        $this->assertGreaterThan(15000, $geo->polylineLength($line));
        $this->assertSame(['south'=>-25.3,'west'=>-57.6,'north'=>-25.25,'east'=>-57.4], $geo->bbox($line));
        $this->assertTrue($geo->pointInPolygon(-25.3,-57.5,[[-57.6,-25.4],[-57.4,-25.4],[-57.4,-25.2],[-57.6,-25.2],[-57.6,-25.4]]));
        $closest = $geo->closestPointOnLine(-25.29,-57.55,$line); $this->assertLessThan(1500, $closest['distance_meters']);
        $middle = $geo->interpolateAlongLine($line, $geo->polylineLength($line)/2); $this->assertGreaterThan(-57.56, $middle['longitude']); $this->assertLessThan(-57.44, $middle['longitude']);
    }
}
