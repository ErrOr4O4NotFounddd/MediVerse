<?php
/**
 * Geocoding API Proxy
 * - Reverse: Converts latitude/longitude to address (lat & lng params)
 * - Forward: Converts address text to latitude/longitude (address param)
 * Uses OpenStreetMap Nominatim
 */

header('Content-Type: application/json');

$options = [
    'http' => [
        'header' => "User-Agent: MediVerse/2.0 (https://mediverse.local)\r\n",
        'timeout' => 10
    ]
];
$context = stream_context_create($options);

// --- Forward Geocoding (address → lat/lng) ---
if (isset($_GET['address']) && !empty(trim($_GET['address']))) {
    $address = trim($_GET['address']);
    $encoded = urlencode($address);
    $url = "https://nominatim.openstreetmap.org/search?format=json&q={$encoded}&countrycodes=bd&limit=1&accept-language=bn";
    
    $response = @file_get_contents($url, false, $context);
    
    if ($response !== false) {
        $data = json_decode($response, true);
        if (!empty($data) && isset($data[0]['lat']) && isset($data[0]['lon'])) {
            echo json_encode([
                'success' => true,
                'lat' => floatval($data[0]['lat']),
                'lng' => floatval($data[0]['lon']),
                'display_name' => $data[0]['display_name'] ?? $address
            ]);
        } else {
            echo json_encode(['success' => false, 'error' => 'Address not found']);
        }
    } else {
        echo json_encode(['success' => false, 'error' => 'API request failed']);
    }
    exit;
}

// --- Reverse Geocoding (lat/lng → address) ---
$lat = isset($_GET['lat']) ? floatval($_GET['lat']) : 0;
$lng = isset($_GET['lng']) ? floatval($_GET['lng']) : 0;

if (!$lat || !$lng) {
    echo json_encode(['success' => false, 'error' => 'Invalid coordinates']);
    exit;
}

// OpenStreetMap Nominatim API
$url = "https://nominatim.openstreetmap.org/reverse?format=json&lat={$lat}&lon={$lng}&accept-language=bn&addressdetails=1";

$response = @file_get_contents($url, false, $context);

if ($response === false) {
    // Fallback: Try English if Bengali fails
    $url_en = "https://nominatim.openstreetmap.org/reverse?format=json&lat={$lat}&lon={$lng}&addressdetails=1";
    $response = @file_get_contents($url_en, false, $context);
}

if ($response !== false) {
    $data = json_decode($response, true);
    
    if (isset($data['display_name'])) {
        // Format address nicely
        $address = $data['display_name'];
        
        // If address details available, create a shorter version
        if (isset($data['address'])) {
            $parts = [];
            $addr = $data['address'];
            
            // Build address from parts
            if (!empty($addr['house_number'])) $parts[] = 'বাড়ি ' . $addr['house_number'];
            if (!empty($addr['road'])) $parts[] = $addr['road'];
            if (!empty($addr['neighbourhood'])) $parts[] = $addr['neighbourhood'];
            if (!empty($addr['suburb'])) $parts[] = $addr['suburb'];
            if (!empty($addr['city_district'])) $parts[] = $addr['city_district'];
            if (!empty($addr['city'])) $parts[] = $addr['city'];
            if (!empty($addr['state'])) $parts[] = $addr['state'];
            
            if (!empty($parts)) {
                $address = implode(', ', $parts);
            }
        }
        
        echo json_encode([
            'success' => true,
            'address' => $address,
            'full_address' => $data['display_name']
        ]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Address not found']);
    }
} else {
    echo json_encode(['success' => false, 'error' => 'API request failed']);
}
