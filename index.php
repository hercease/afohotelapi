<?php

$origin = $_SERVER['HTTP_ORIGIN'] ?? '*';
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");
header("Access-Control-Allow-Credentials: true");
// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once 'config/config.php';
require_once 'models/hotel_models.php';
require_once 'controllers/hotel_controllers.php';
require_once 'controllers/db_controller.php';

$db = (new Database())->connect();
$HotelModels = new HotelModels($db);
$HotelControllers = new HotelControllers($db);

// Simple routing based on the 'action' query parameter
// Handle routing
$baseDir = '/afohotelapi';  // Base directory where your app is located
$url = str_replace($baseDir, '', parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));
$action = ltrim($url, '/'); // Get action from URL

//error_log("Requested URL: " . print_r($_POST, true));

// Route the request to the appropriate controller method
switch ($action) {
    case 'searchHotels':
        $response = $HotelControllers->searchHotels();
        echo $response;
        break;
    default:
        $response = ["status" => "error", "message" => "Invalid action"];
        echo json_encode($response);
        break;
}
