<?php

namespace App\Http\Controllers;

use App\Support\Geofence;
use Illuminate\Http\Request;
use Illuminate\View\View;

class GeofenceController extends Controller
{
    public function restricted(Request $request): View
    {
        $attemptedLatitude = $request->session()->pull('geofence_attempted_latitude');
        $attemptedLongitude = $request->session()->pull('geofence_attempted_longitude');

        if (!is_numeric($attemptedLatitude)) {
            $attemptedLatitude = $request->query('lat');
        }

        if (!is_numeric($attemptedLongitude)) {
            $attemptedLongitude = $request->query('lng');
        }

        return view('auth.geofence-restricted', [
            'geofenceMap' => Geofence::mapPayload(),
            'attemptedLatitude' => is_numeric($attemptedLatitude) ? (float) $attemptedLatitude : null,
            'attemptedLongitude' => is_numeric($attemptedLongitude) ? (float) $attemptedLongitude : null,
        ]);
    }
}

