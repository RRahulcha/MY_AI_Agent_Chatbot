<?php
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/loadenv.php';

// Load environment variables
loadEnv(__DIR__ . '/../.env');

use Aws\S3\S3Client;
use Aws\Exception\AwsException;

function getR2Client()
{
    $accessKey = getenv('R2_ACCESS_KEY_ID') ?: ($_ENV['R2_ACCESS_KEY_ID'] ?? '');
    $secretKey = getenv('R2_SECRET_ACCESS_KEY') ?: ($_ENV['R2_SECRET_ACCESS_KEY'] ?? '');
    $endpoint = getenv('R2_ENDPOINT') ?: ($_ENV['R2_ENDPOINT'] ?? '');

    $credentials = new Aws\Credentials\Credentials($accessKey, $secretKey);

    $options = [
        'region' => 'auto',
        'endpoint' => $endpoint,
        'version' => 'latest',
        'credentials' => $credentials
    ];

    return new S3Client($options);
}

function getR2BucketName()
{
    return getenv('R2_BUCKET_NAME') ?: ($_ENV['R2_BUCKET_NAME'] ?? 'dev-ams-settribe');
}

function getR2PublicUrl()
{
    return getenv('R2_PUBLIC_URL') ?: ($_ENV['R2_PUBLIC_URL'] ?? '');
}

function uploadSelfieToR2($fileData, $userId, $punchType, $firstName = '', $lastName = '')
{
    if (empty($fileData))
        return null;
    $client = getR2Client();
    $bucket = getR2BucketName();

    $prefix = ($punchType === 'in') ? "PI_" : "PO_";
    $fileName = $prefix . time() . "_" . rand(1000, 9999) . ".jpg";

    $folder = "selfies/" . $userId;

    $objectKey = $folder . "/" . $fileName;

    try {
        $client->putObject([
            'Bucket' => $bucket,
            'Key' => $objectKey,
            'Body' => $fileData,
            'ContentType' => 'image/jpeg'
        ]);
        return $objectKey;
    } catch (AwsException $e) {
        error_log("R2 Upload failed: " . $e->getMessage());
        throw $e;
    }
}




/**
 * Get the total number of files in the R2 bucket (or within a specific prefix/folder).
 *
 * @param string $prefix Optional folder/prefix path (e.g. '123' or '123/')
 * @return int Total file count, or 0 on failure
 */
function getR2FileCount($prefix = '')
{
    $client = getR2Client();
    $bucket = getR2BucketName();

    $params = [
        'Bucket' => $bucket,
    ];

    if (!empty($prefix)) {
        // Ensure trailing slash for folder prefixes if not already present
        $params['Prefix'] = rtrim($prefix, '/') . '/';
    }

    $count = 0;

    try {
        // Use AWS SDK Paginator to handle buckets with more than 1,000 objects
        $results = $client->getPaginator('ListObjectsV2', $params);

        foreach ($results as $result) {
            if (!empty($result['Contents'])) {
                $count += count($result['Contents']);
            }
        }

        return $count;
    } catch (AwsException $e) {
        error_log("R2 File Count failed: " . $e->getMessage());
        return 0;
    }
}

/**
 * Get the correct URL for a selfie image (Local or R2).
 *
 * @param string $imageName The name/path of the image from the database
 * @param string $basePath The local base path (e.g., '../uploads/punchIn/')
 * @return string The complete URL to the image
 */
function getSelfieUrl($imageName, $basePath)
{
    if (empty($imageName)) return '';
    if (strpos($imageName, 'http') === 0) return $imageName;
    
    // R2 images are prefixed with 'selfies/'
    if (strpos($imageName, 'selfies/') === 0) {
        return rtrim(getR2PublicUrl(), '/') . '/' . ltrim($imageName, '/');
    }
    
    // Otherwise, it is a local image
    return rtrim($basePath, '/') . '/' . ltrim($imageName, '/');
}

?>