<?php

namespace App\Enums;

enum IngestionType: string
{
    case Api = 'api';
    case Rss = 'rss';
    case Json = 'json';
    case Xml = 'xml';
    case Html = 'html';
    case Pdf = 'pdf';
    case Csv = 'csv';
    case GeoJson = 'geojson';
    case Kml = 'kml';
    case ManualConfiguration = 'manual_configuration';
}
