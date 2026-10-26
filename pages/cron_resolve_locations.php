<?php
/**
 * CRON JOB: Resolve Pending Locations Asynchronously
 * 
 * Set this file to run via cPanel Cron Jobs every 5 minutes:
 * Example Cron Command: 
 * /usr/local/bin/php /home/nmrmlatur/ams.settribe.com/pages/cron_resolve_locations.php
 */

error_reporting(E_ALL); 
ini_set('display_errors', 1);

// Include database configuration
require_once(__DIR__ . '/../include/db_config.php');

// We use ip-api.com batch endpoint (allows 15 requests per min, up to 100 IPs per request)
// We will process up to 100 unique IPs per cron execution to stay safely under limits.
$query = mysqli_query($con, "SELECT DISTINCT ip FROM visitors WHERE location = 'Pending' LIMIT 100");

if (!$query || mysqli_num_rows($query) === 0) {
    echo "No pending IPs to resolve.\n";
    exit;
}

$ipsToResolve = [];
while ($row = mysqli_fetch_assoc($query)) {
    // Ignore localhost and invalid IPs
    if ($row['ip'] !== '127.0.0.1' && $row['ip'] !== '::1' && filter_var($row['ip'], FILTER_VALIDATE_IP)) {
        $ipsToResolve[] = ["query" => $row['ip'], "fields" => "status,message,country,regionName,city,query"];
    } else {
        // If it's localhost, just update to Local
        mysqli_query($con, "UPDATE visitors SET location = 'Localhost' WHERE ip = '" . mysqli_real_escape_string($con, $row['ip']) . "'");
    }
}

if (empty($ipsToResolve)) {
    echo "All pending IPs were local.\n";
    exit;
}

// Prepare JSON payload for the batch API
$jsonPayload = json_encode($ipsToResolve);

// Make the POST request to ip-api batch endpoint
$ch = curl_init('http://ip-api.com/batch');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonPayload);
curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json'));
curl_setopt($ch, CURLOPT_TIMEOUT, 30); // 30 sec timeout
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($response !== false && $httpCode === 200) {
    $results = json_decode($response, true);
    
    if (is_array($results)) {
        $successCount = 0;
        foreach ($results as $data) {
            $ip = $data['query'] ?? '';
            if (empty($ip)) continue;
            
            $location = 'Unknown';
            if (isset($data['status']) && $data['status'] === 'success' && !empty($data['city'])) {
                $location = $data['city'] . ', ' . $data['regionName'] . ', ' . $data['country'];
            } elseif (isset($data['message'])) {
                $location = 'API Error: ' . $data['message'];
            }
            
            // Update the DB
            $safeIp = mysqli_real_escape_string($con, $ip);
            $safeLocation = mysqli_real_escape_string($con, $location);
            
            mysqli_query($con, "UPDATE visitors SET location = '$safeLocation' WHERE ip = '$safeIp' AND location = 'Pending'");
            $successCount++;
        }
        echo "Successfully resolved $successCount IPs.\n";
    } else {
        echo "Failed to decode JSON response from IP-API.\n";
    }
} else {
    echo "Failed to fetch data from IP-API. HTTP Code: $httpCode\n";
}
?>
