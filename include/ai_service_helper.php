<?php
/**
 * AI Service Client Helper for SETTribe AMS
 * Connects PHP backend to FaceVision AI engine (127.0.0.1:5001)
 */

if (!function_exists('enroll_user_face_ai')) {
    /**
     * Notify AI Service to enroll/update an employee's face from their profile photo
     * 
     * @param string $userId
     * @param string $fullName
     * @param string $photoFilename (basename in profile-photos/)
     * @param string $role
     * @param string $expectedIn
     * @return array ['success' => bool, 'face_detected' => bool, 'message' => string]
     */
    function enroll_user_face_ai($userId, $fullName, $photoFilename, $role = '', $expectedIn = '09:30:00') {
        $aiBaseUrl = getenv('AI_SERVICE_URL') ?: ($_ENV['AI_SERVICE_URL'] ?? 'http://127.0.0.1:5001');
        $aiUrl = rtrim($aiBaseUrl, '/') . '/api/enroll_face';
        
        $payload = json_encode([
            'user_id' => strval($userId),
            'name' => strval($fullName),
            'photo_filename' => basename($photoFilename),
            'role' => strval($role),
            'expected_in' => strval($expectedIn)
        ]);

        $ch = curl_init($aiUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_TIMEOUT, 12);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            return [
                'success' => false,
                'face_detected' => false,
                'message' => 'AI Service is currently offline. Profile saved, but face enrollment is pending.'
            ];
        }

        $resData = json_decode($response, true);
        return is_array($resData) ? $resData : [
            'success' => ($httpCode >= 200 && $httpCode < 300),
            'face_detected' => ($httpCode >= 200 && $httpCode < 300),
            'message' => 'Face enrollment processed.'
        ];
    }
}
