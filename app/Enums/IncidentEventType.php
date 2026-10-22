<?php

namespace App\Enums;

enum IncidentEventType: string
{
    case Detected = 'detected';
    case Confirmed = 'confirmed';
    case Corroborated = 'corroborated';
    case Updated = 'updated';
    case RoadClosed = 'road_closed';
    case RoadReopened = 'road_reopened';
    case ServiceInterrupted = 'service_interrupted';
    case ServiceRestored = 'service_restored';
    case Controlled = 'controlled';
    case Extinguished = 'extinguished';
    case Resolved = 'resolved';
    case Expired = 'expired';
    case Discarded = 'discarded';
    case LocationUpdated = 'location_updated';
    case SeverityChanged = 'severity_changed';
}
