<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

final class Geo
{
    /**
     * Build a PostGIS geography point expression from latitude & longitude.
     */
    public static function pointSql(float $lat, float $lng): string
    {
        return sprintf('SRID=4326;POINT(%.8F %.8F)', $lng, $lat);
    }

    /**
     * Extract [lat, lng] from a geography point column value via PostGIS.
     *
     * @return array{lat: float, lng: float}|null
     */
    public static function extractLatLng(string $table, string $id): ?array
    {
        /** @var object{lat: float|string|null, lng: float|string|null}|null $row */
        $row = DB::selectOne(
            "SELECT ST_Y(location::geometry) AS lat, ST_X(location::geometry) AS lng FROM {$table} WHERE id = ? AND location IS NOT NULL",
            [$id],
        );

        if ($row === null || $row->lat === null || $row->lng === null) {
            return null;
        }

        return [
            'lat' => (float) $row->lat,
            'lng' => (float) $row->lng,
        ];
    }
}
