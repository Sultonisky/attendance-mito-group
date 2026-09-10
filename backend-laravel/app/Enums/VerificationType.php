<?php

namespace App\Enums;

/**
 * Type of verification fact attached to an attendance event/record.
 *
 * FastAPI supplies AI facts (face), PostGIS supplies geofence facts; Laravel
 * decides what those facts mean for the business process.
 */
enum VerificationType: string
{
    case Face = 'face';

    case Geofence = 'geofence';

    case Gps = 'gps';
}
