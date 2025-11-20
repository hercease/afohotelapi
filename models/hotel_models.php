<?php

use PHPMailer\PHPMailer\PHPMailer; 
use PHPMailer\PHPMailer\Exception as PHPMailerException;
class HotelModels {

    private $perPage = 6;
    private $email_template;
    public function __construct() {
        $filePath = 'models/email_template.html';
        if (!file_exists($filePath) || !is_readable($filePath)) {
            throw new Exception("The path to the email template is incorrect: $filePath");
        }
        $this->email_template = file_get_contents($filePath);
    }

    public function calculateNights($checkIn, $checkOut) {
        $checkIn = new DateTime($checkIn);
        $checkOut = new DateTime($checkOut);
        return $checkIn->diff($checkOut)->days;
    }

    public function buildXmlRequest($params) {
        $error = $this->ValidateParams($params, ['checkIn', 'checkOut'])['errors'];
        if (!empty($error)) {
            return json_encode(["status" => "error", "message" => $error[0]]);
        }
        
        $params = $this->ValidateParams($params, ['checkIn', 'checkOut'])['params'];
        $roomsXml = $this->buildRoomsXml($params);
        $nights = $this->calculateNights($params['checkIn'], $params['checkOut']);
        $currency = $params['currency'] ?? 'USD';
        $nationality = $params['nationality'] ?? 'NG';
        $destination = $params['destination'] ?? '75';
        $roomBasis = $params['roomBasis'] ?? '';
        $minPrice = $params['minPrice'] ?? 0;
        $maxPrice = $params['maxPrice'] ?? 1000000;
        $starLevels = $params['starLevels'] ?? '';

        // Build filter XML sections
        $filterXml = $this->buildFilterXml($roomBasis, $starLevels, $minPrice, $maxPrice);

        return '<Root>
                <Header>
                    <Agency>' . AGENCY_ID . '</Agency>
                    <User>' . USERNAME . '</User>
                    <Password>' . PASSWORD . '</Password>
                    <Operation>HOTEL_SEARCH_REQUEST</Operation>
                    <OperationType>Request</OperationType>
                </Header>
                <Main Version="2.4" ResponseFormat="JSON" IncludeGeo="false" HotelFacilities="true" RoomFacilities="true" Currency="' . $currency . '">
                    <MaximumWaitTime>20</MaximumWaitTime>
                    <Nationality>' . $nationality . '</Nationality>
                    <CityCode>' . $destination . '</CityCode>
                    <ArrivalDate>' . $params['checkIn'] . '</ArrivalDate>
                    <Nights>' . $nights . '</Nights>
                    <Rooms>' . $roomsXml . '</Rooms>
                    ' . $filterXml . '
                </Main>
        </Root>';
    }

    /**
     * Build filter XML sections
     */
    private function buildFilterXml($roomBasis, $starLevels, $minPrice, $maxPrice) {
        $filterXml = '';

        // Room Basis Filter
        if (!empty($roomBasis)) {
            $roomBasisArray = $this->convertToArray($roomBasis);
            if (!empty($roomBasisArray)) {
                $filterXml .= '<FilterRoomBasises>' . PHP_EOL;
                foreach ($roomBasisArray as $basis) {
                    $filterXml .= '    <FilterRoomBasis>' . trim($basis) . '</FilterRoomBasis>' . PHP_EOL;
                }
                $filterXml .= '</FilterRoomBasises>' . PHP_EOL;
            }
        }

        // Star Level Filter (highest only)
        if (!empty($starLevels)) {
            $starLevelsArray = $this->convertToArray($starLevels);
            if (!empty($starLevelsArray)) {
                $highestStarLevel = max($starLevelsArray);
                $filterXml .= '<Stars>' . $highestStarLevel . '</Stars>' . PHP_EOL;
            }
        }

        // Price Range Filter (if you want to add it)
        if ($minPrice > 0) {
            $filterXml .= '    <FilterPriceMin>' . $minPrice . '</FilterPriceMin>' . PHP_EOL;
            $filterXml .= '   <FilterPriceMax>' . $maxPrice . '</FilterPriceMax>' . PHP_EOL;
        }

        return $filterXml;
    }

    /**
     * Convert comma-separated string to array
     */
    private function convertToArray($commaSeparatedString) {
        if (empty($commaSeparatedString)) {
            return [];
        }
        
        if (is_array($commaSeparatedString)) {
            return array_filter(array_map('trim', $commaSeparatedString));
        }
        
        $array = array_map('trim', explode(',', $commaSeparatedString));
        return array_filter($array);
    }

    public function ProcessRequest($xmlRequest, $page) {

        $client = new SoapClient(WSDL, [
            'soap_version' => SOAP_1_2,
            'trace' => 1,
            'exceptions' => true,
            'cache_wsdl' => WSDL_CACHE_NONE,
            'location' => ENDPOINT
        ]);

        $params = [
            'requestType' => '11',
            'xmlRequest' => $xmlRequest
        ];

        $response = $client->MakeRequest($params);
        error_log("Response :" . print_r($response, true));
        $resp = $this->processWithBatchInfo($response, $page);
        return json_encode($resp);
        //$responseArray = json_decode(json_encode($response), true);
        //return $responseArray['MakeRequestResult'] ?? null;

    }

    
    /**
     * Build XML string for rooms based on $params
     * 
     * @param array $params - Parameters containing total rooms, adults and children for each room
     * @return string - XML string for rooms
     */
    public function buildRoomsXml($params) {
        $roomsXml = '';
       
        $totalRooms = (int)$params['totalRooms'];
        
        for ($i = 1; $i <= $totalRooms; $i++) {
            $adults = isset($params["adult{$i}"]) ? (int)$params["adult{$i}"] : 1;
            $children = isset($params["children{$i}"]) ? (int)$params["children{$i}"] : 0;
            
            $roomXml = '<Room Adults="' . $adults . '" RoomCount="1" ChildCount="' . $children . '"';
            
            if ($children > 0) {
                $roomXml .= '>';
                // Handle children ages
                if (isset($params["childrenAges{$i}"])) {
                    $childrenAges = is_array($params["childrenAges{$i}"]) ? 
                        $params["childrenAges{$i}"] : 
                        explode(',', $params["childrenAges{$i}"]);
                    
                    foreach ($childrenAges as $age) {
                        $roomXml .= '<ChildAge>' . trim($age) . '</ChildAge>';
                    }
                }
                $roomXml .= '</Room>';
            } else {
                $roomXml .= '/>';
            }
            
            $roomsXml .= $roomXml;
        }
        
        return $roomsXml;
    }

    public function ValidateParams($params, $requiredFields) {
        $errors = [];
        // Sanitize all params first
        $params = $this->sanitizeInput($params);

        // Check required fields
        foreach ($requiredFields as $field) {
            if (!isset($params[$field]) || $params[$field] === '' || $params[$field] === null) {
                $errors[] = "Missing or empty parameter: $field";
            }
        }

        return [
            'errors' => $errors,
            'params' => $params
        ];
    }

    public function processWithBatchInfo($searchResults, $page) {
        $responseArray = $this->processResponse($searchResults)['hotels'];
        //error_log("Response :" . print_r($responseArray, true));
        //$resultsArray = $responseArray['MakeRequestResult'];

        //error_log("Result Array : " . print_r($responseArray, true));

        if(!isset($responseArray) || empty($responseArray)) {
            return ['error' => 'No hotels found'];
        }

        //error_log("Total Hotels Found: " . count($responseArray));

        $allHotels = $responseArray;
        
        // Paginate first
        $paginated = $this->paginateResults($allHotels, $page);
        
        // Extract hotel codes from current page 
        $hotelCodes = array_map(function($hotel) {
            return $hotel['HotelCode'];
        }, $paginated['data']);
        
        // Fetch all hotel info in batch (if supported) or sequentially
        $hotelsInfo = $this->fetchBatchHotelInfo($hotelCodes);
        
        // Merge hotel info with hotel data
        $enhancedHotels = [];
        foreach ($paginated['data'] as $hotel) { 
            $hotelCode = $hotel['HotelCode'];
            $hotel['hotelInfo'] = $hotelsInfo[$hotelCode] ?? null;
            $enhancedHotels[] = $hotel;
        }
        
        return [
            'hotels' => $enhancedHotels,
            'pagination' => $paginated['pagination']
        ];
    }
    
    private function paginateResults($data, $page) {
        $total = count($data);
        $totalPages = ceil($total / $this->perPage);
        $page = max(1, min($page, $totalPages));
        $offset = ($page - 1) * $this->perPage;
        
        return [
            'data' => array_slice($data, $offset, $this->perPage),
            'pagination' => [
                'current_page' => $page,
                'per_page' => $this->perPage,
                'total' => $total,
                'total_pages' => $totalPages,
                'from' => $offset + 1,
                'to' => min($offset + $this->perPage, $total)
            ]
        ];
    }
    
    private function fetchBatchHotelInfo($hotelCodes) {
       
        $hotelsInfo = [];

        //error_log("Fetching hotel info for hotels: " . implode(", ", $hotelCodes));
        
        foreach ($hotelCodes as $hotelCode) {
            
            $hotelsInfo[$hotelCode] = $this->fetchSingleHotelInfo($hotelCode);
        }
        
        return $hotelsInfo;
    }

    private function fetchSingleHotelInfo($hotelCode) {
        // Existing SOAP call to get hotel info
        //error_log("Fetching hotel info for hotel code: " . $hotelCode);
        try {
            $client = new SoapClient(WSDL, [
                'soap_version' => SOAP_1_2,
                'trace' => 1,
                'exceptions' => true,
                'location' => ENDPOINT,
                'cache_wsdl' => WSDL_CACHE_NONE,
            ]);

            $xmlRequest = '<Root>
                <Header>
                    <Agency>' . AGENCY_ID . '</Agency>
                    <User>' . USERNAME . '</User>
                    <Password>' . PASSWORD . '</Password>
                    <Operation>HOTEL_INFO_REQUEST</Operation>
                    <OperationType>Request</OperationType>
                </Header>
                <Main Version="2.4">
                    <InfoHotelId>' . $hotelCode . '</InfoHotelId>
                    <InfoLanguage>en</InfoLanguage>
                </Main>
            </Root>';

            
            $response = $client->MakeRequest([
                'requestType' => '61',
                'xmlRequest' => $xmlRequest
            ]);

            

            $resp = $this->convertXmlTojson($response);
            //error_log("Hotel Info Check : " . print_r($resp, true));
            return $resp;


            //error_log("Hotel Info Response Object: " . print_r($response, true));
            
            /*if (is_object($response) && property_exists($response, 'MakeRequestResult')) {
                $resp = json_decode(json_encode($response), true);
                return $resp['MakeRequestResult'] ?? null;
            }*/
            
            //return null;
        } catch (Exception $e) {
            //error_log("Hotel info error for {$hotelCode}: " . $e->getMessage());
            return null;
        }
    }

    public function sanitizeInput($data) {
        if (is_array($data)) {
            // Loop through each element of the array and sanitize recursively
            foreach ($data as $key => $value) {
                $data[$key] = $this->sanitizeInput($value);
            }

        } else {
            // If it's not an array, sanitize the string
            $data = trim($data); // Remove unnecessary spaces
            $data = stripslashes($data); // Remove backslashes
            $data = htmlspecialchars($data); // Convert special characters to HTML entities
        }
        return $data;
    }

     public function processResponse($response) {
        $result = [
            'hotels' => [],
            'stats' => [],
            'success' => false,
            'error' => null
        ];
        
        try {
            // Extract the actual result
            $jsonData = $this->extractJsonData($response);
            
            if ($jsonData === null) {
                $result['error'] = 'Invalid response format';
                return $result;
            }
            
            // Extract hotels
            $result['hotels'] = $jsonData['Hotels'] ?? [];
            
            // Extract statistics
            $result['stats'] = [
                'total_hotels' => $jsonData['Header']['Stats']['HotelQty'] ?? 0,
                'total_results' => $jsonData['Header']['Stats']['ResultsQty'] ?? 0,
                'extracted_hotels' => count($result['hotels'])
            ];
            
            $result['success'] = true;
            
        } catch (Exception $e) {
            $result['error'] = $e->getMessage();
        }
        
        return $result;
    }
    
    private function extractJsonData($response) {
        // If it's already an array
        if (is_array($response) && isset($response['Hotels'])) {
            return $response;
        }
        
        // If it's a JSON string
        if (is_string($response)) {
            $data = json_decode($response, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                return $data;
            }
        }
        
        // If it's a SOAP response object
        if (is_object($response) && property_exists($response, 'MakeRequestResult')) {
            $result = $response->MakeRequestResult;
            if (is_string($result)) {
                $data = json_decode($result, true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    return $data;
                }
            }
        }
        
        return null;
    }

    public function convertXmlTojson($response){
        $finalResult = [];
    
        if (is_object($response) && property_exists($response, 'MakeRequestResult')) {
            $result = $response->MakeRequestResult;
            
            if (is_string($result)) {
                // Try JSON first
                $jsonData = json_decode($result, true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    $finalResult = $jsonData;
                } else {
                    // Convert XML to JSON
                    $xml = simplexml_load_string($result);
                    if ($xml !== false) {
                        $finalResult = [
                            'hotel_name' => (string)$xml->Main->HotelName,
                            'hotel_id' => (int)$xml->Main->HotelId,
                            'address' => (string)$xml->Main->Address,
                            'city_code' => (int)$xml->Main->CityCode,
                            'coordinates' => [
                                'longitude' => (float)$xml->Main->GeoCodes->Longitude,
                                'latitude' => (float)$xml->Main->GeoCodes->Latitude
                            ],
                            'category' => (int)$xml->Main->Category,
                            'description' => (string)$xml->Main->Description,
                            'facilities' => array_filter(explode('<BR />', (string)$xml->Main->HotelFacilities)),
                            'images' => []
                        ];
                        
                        // Add images
                        foreach ($xml->Main->Pictures->Picture ?? [] as $picture) {
                            $finalResult['images'][] = [
                                'url' => (string)$picture,
                                'description' => (string)$picture['Description']
                            ];
                        }
                    } else {
                        $finalResult = ['error' => 'Unable to parse response', 'raw' => $result];
                    }
                }
            }
        }

        //header('Content-Type: application/json');
        return $finalResult;
    }

    public function processBookingEvaluation($hotelCode, $checkIn, $hotelSearchCode) {
       
        $evaluationXML = '<Root>
                                <Header>
                                    <Agency>' . AGENCY_ID . '</Agency>
                                    <User>' . USERNAME . '</User>
                                    <Password>' . PASSWORD . '</Password>
                                    <Operation>BOOKING_VALUATION_REQUEST</Operation>
                                    <OperationType>Request</OperationType>
                                </Header>
                                <Main Version="2.0">
                                    <HotelSearchCode>' . $hotelSearchCode . '</HotelSearchCode>
                                    <ArrivalDate>' . $checkIn . '</ArrivalDate>
                                </Main>
                        </Root>';

        $response = $this->fetchBookingEvaluation($evaluationXML);
        $finalResult = [];
    
        if (is_object($response) && property_exists($response, 'MakeRequestResult')) {
            $result = $response->MakeRequestResult;
            
            if (is_string($result)) {
                // Try JSON first
                $jsonData = json_decode($result, true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    $finalResult = $jsonData;
                } else {
                    // Convert XML to JSON
                    $xml = simplexml_load_string($result);
                    if ($xml !== false) {
                        $finalResult = [
                            'cancellationDate' => (string)$xml->Main->CancellationDeadline,
                            'description' => (string)$xml->Main->Description,
                            'remarks' => array_filter(explode('<BR />', (string)$xml->Main->Remarks)),
                        ];
                        
                    } else {
                        $finalResult = ['error' => 'Unable to parse response', 'raw' => $result];
                    }
                }
            }
        }

        return json_encode($finalResult);
        
    }

   public function processPriceBreakDown($hotelSearchCode) {
        $breakdownXML = '<Root>
            <Header>
                <Agency>' . AGENCY_ID . '</Agency>
                <User>' . USERNAME . '</User>
                <Password>' . PASSWORD . '</Password>
                <Operation>PRICE_BREAKDOWN_REQUEST</Operation>
                <OperationType>Request</OperationType>
            </Header>
            <Main>
                <HotelSearchCode>' . $hotelSearchCode . '</HotelSearchCode>
            </Main>
        </Root>';

        $response = $this->fetchPriceBreakDown($breakdownXML);
        $finalResult = [];

        if (is_object($response) && property_exists($response, 'MakeRequestResult')) {
            $result = $response->MakeRequestResult;
            
            if (is_string($result)) {
                $xml = simplexml_load_string($result);
                if ($xml !== false) {
                    // Check if the response is successful
                    if (isset($xml->Main->Error)) {
                        return json_encode([
                            'success' => false,
                            'error' => (string)$xml->Main->Error
                        ]);
                    }

                    $rooms = [];
                    
                    // Process each room in the response
                    foreach ($xml->Main->Room as $room) {
                        $roomType = (string)$room->RoomType;
                        $children = (int)$room->Children;
                        $cots = (int)$room->Cots;
                        
                        $priceBreakdowns = [];
                        
                        // Extract price breakdowns for this room
                        foreach ($room->PriceBreakdown as $breakdown) {
                            $priceBreakdowns[] = [
                                'from_date' => (string)$breakdown->FromDate,
                                'to_date' => (string)$breakdown->ToDate,
                                'price' => (float)$breakdown->Price,
                                'currency' => (string)$breakdown->Currency
                            ];
                        }
                        
                        $rooms[] = [
                            'room_type' => $roomType,
                            'children' => $children,
                            'cots' => $cots,
                            'price_breakdown' => $priceBreakdowns
                        ];
                    }
                    
                    $finalResult = [
                        'success' => true,
                        'hotel_name' => (string)$xml->Main->HotelName,
                        'hotel_search_code' => $hotelSearchCode,
                        'rooms' => $rooms,
                        'total_rooms' => count($rooms)
                    ];
                } else {
                    $finalResult = [
                        'success' => false,
                        'error' => 'Unable to parse XML response'
                    ];
                }
            } else {
                $finalResult = [
                    'success' => false,
                    'error' => 'Invalid response format'
                ];
            }
        } else {
            $finalResult = [
                'success' => false,
                'error' => 'Unable to fetch price breakdown'
            ];
        }

        error_log(json_encode($finalResult));

        return json_encode($finalResult, JSON_PRETTY_PRINT);
    }

    public function processHotelBooking() {
        // Get the form data
        $hotelSearchCode = $_POST['hotelSearchCode'] ?? '';
        $hotelCode = $_POST['hotelCode'] ?? '';
        $checkInDate = $_POST['checkInDate'] ?? '';
        $nights = $_POST['nights'] ?? 1;
        $totalRooms = $_POST['totalRooms'] ?? 1;
        $guestLeaderEmail = $_POST['guestLeaderEmail'] ?? '';
        
        // Generate agent reference
        $agentReference = 'REF_' . time() . '_' . rand(1000, 9999);
        
        // Build the XML
        $xml = new SimpleXMLElement('<?xml version="1.0" encoding="UTF-8"?><Root></Root>');
        
        // Header section
        $header = $xml->addChild('Header');
        $header->addChild('Agency', AGENCY_ID);
        $header->addChild('User', USERNAME);
        $header->addChild('Password', PASSWORD);
        $header->addChild('Operation', 'BOOKING_INSERT_REQUEST');
        $header->addChild('OperationType', 'Request');
        
        // Main section with version
        $main = $xml->addChild('Main');
        $main->addAttribute('Version', '2.3');
        
        $main->addChild('AgentReference', $agentReference);
        $main->addChild('HotelSearchCode', $hotelSearchCode);
        $main->addChild('ArrivalDate', $checkInDate);
        $main->addChild('Nights', $nights);
        $main->addChild('NoAlternativeHotel', '1');
        
        // Leader section
        $leader = $main->addChild('Leader');
        $leader->addAttribute('LeaderPersonID', '1');
        
        // Rooms section
        $rooms = $main->addChild('Rooms');
        
        // Group rooms by configuration (adults + children count)
        $roomConfigurations = [];
        
        for ($roomIndex = 1; $roomIndex <= $totalRooms; $roomIndex++) {
            // Count adults and children for this room
            $adultCount = 0;
            $childrenCount = 0;
            $extraBedCount = 0;
            
            // Count adults in this room
            foreach ($_POST as $key => $value) {
                if (strpos($key, "room{$roomIndex}_adult") === 0 && strpos($key, '_firstName') !== false) {
                    $adultCount++;
                }
            }
            
            // Count children in this room
            foreach ($_POST as $key => $value) {
                if (strpos($key, "room{$roomIndex}_child") === 0 && strpos($key, '_firstName') !== false) {
                    $childrenCount++;
                }
            }
            
            // Check for extra bed in this room
            $extraBedRequested = isset($_POST['extraBed']) && $_POST['extraBed'] === 'true';
            if ($childrenCount > 0) {
                $extraBedCount = 1; // Assuming one extra bed per room max
            }
            
            $configKey = "A{$adultCount}_C{$childrenCount}_E{$extraBedCount}";
            
            if (!isset($roomConfigurations[$configKey])) {
                $roomConfigurations[$configKey] = [
                    'adults' => $adultCount,
                    'children' => $childrenCount,
                    'extraBed' => $extraBedCount,
                    'roomIds' => []
                ];
            }
            
            $roomConfigurations[$configKey]['roomIds'][] = $roomIndex;
        }
        
        // Build RoomType elements for each configuration
        $roomIdCounter = 1;
        
        foreach ($roomConfigurations as $configKey => $config) {
            $roomType = $rooms->addChild('RoomType');
            $roomType->addAttribute('Adults', $config['adults']);
            
            if ($config['children'] > 0) {
                $roomType->addAttribute('Children', $config['children']);
            }
            
            // Add each room in this configuration
            foreach ($config['roomIds'] as $roomIndex) {
                $room = $roomType->addChild('Room');
                $room->addAttribute('RoomID', $roomIdCounter);
                $roomIdCounter++;
                
                // Reset PersonID counter for each room (starts from 1 for each room)
                $personIdCounter = 1;
                
                // Add adults for this room
                for ($adultIndex = 1; $adultIndex <= $config['adults']; $adultIndex++) {
                    $title = $_POST["room{$roomIndex}_adult{$adultIndex}_title"] ?? 'MR.';
                    $firstName = $_POST["room{$roomIndex}_adult{$adultIndex}_firstName"] ?? '';
                    $lastName = $_POST["room{$roomIndex}_adult{$adultIndex}_lastName"] ?? '';
                    
                    $title = $this->formatTitle($title);
                    
                    $person = $room->addChild('PersonName');
                    $person->addAttribute('PersonID', $personIdCounter);
                    $person->addAttribute('Title', $title);
                    $person->addAttribute('FirstName', strtoupper($firstName));
                    $person->addAttribute('LastName', strtoupper($lastName));
                    
                    $personIdCounter++;
                }
                
                // Add children for this room
                for ($childIndex = 1; $childIndex <= $config['children']; $childIndex++) {
                    $title = $_POST["room{$roomIndex}_child{$childIndex}_title"] ?? 'CHD';
                    $firstName = $_POST["room{$roomIndex}_child{$childIndex}_firstName"] ?? '';
                    $lastName = $_POST["room{$roomIndex}_child{$childIndex}_lastName"] ?? '';
                    $age = $_POST["room{$roomIndex}_child{$childIndex}_age"] ?? '';
                    
                    $title = $this->formatTitle($title);
                    
                    // Check if this child should be in ExtraBed (first child when extra bed is requested)
                    $extraBed = $room->addChild('ExtraBed');
                    $extraBed->addAttribute('PersonID', $personIdCounter);
                    $extraBed->addAttribute('FirstName', strtoupper($firstName));
                    $extraBed->addAttribute('LastName', strtoupper($lastName));
                    $extraBed->addAttribute('ChildAge', $age);
                        
                    
                    
                    $personIdCounter++;
                }
            }
        }
        
        // Preferences section
        $preferences = $main->addChild('Preferences');
        
        // Late arrival
        $lateArrivalHours = $_POST['lateArrivalHours'] ?? '';
        $lateArrivalMinutes = $_POST['lateArrivalMinutes'] ?? '';
        if ($lateArrivalHours !== '' && $lateArrivalMinutes !== '') {
            $lateArrivalTime = sprintf('%02d:%02d', $lateArrivalHours, $lateArrivalMinutes);
            $preferences->addChild('LateArrival', $lateArrivalTime);
        }
        
        // Room preferences
        if (isset($_POST['adjoiningRooms']) && $_POST['adjoiningRooms'] === 'true') {
            $preferences->addChild('AdjoiningRooms', '1');
        }
        
        if (isset($_POST['connectingRooms']) && $_POST['connectingRooms'] === 'true') {
            $preferences->addChild('ConnectingRooms', '1');
        }
        
        if (isset($_POST['nonSmoking']) && $_POST['nonSmoking'] === 'true') {
            $preferences->addChild('NonSmoking', '1');
        }
        
        if (isset($_POST['honeymoon']) && $_POST['honeymoon'] === 'true') {
            $preferences->addChild('Honeymoon', '1');
        }
        
        // Remark section
        $notes = $_POST['notes'] ?? '';
        if ($notes) {
            $main->addChild('Remark', htmlspecialchars($notes));
        }
        
        // Convert XML to string for logging
        $xmlString = $xml->asXML();
        
        // Log the XML (for debugging)
       // error_log("Booking XML: " . $xmlString);
        
        // Send the XML to the external API
        $response = $this->sendBookingRequest($xmlString);

        error_log("Booking Response: " . print_r($response, true));
        
        return $this->processBookingResponse($response, $guestLeaderEmail);
    }

    public function fetchBookingDetails(){

        $xml = new SimpleXMLElement('<?xml version="1.0" encoding="UTF-8"?><Root></Root>');
        // Header section
        $header = $xml->addChild('Header');
        $header->addChild('Agency', AGENCY_ID);
        $header->addChild('User', USERNAME);
        $header->addChild('Password', PASSWORD);
        $header->addChild('Operation', 'BOOKING_SEARCH_REQUEST');
        $header->addChild('OperationType', 'Request');

        // Main section
        $main = $xml->addChild('Main');
        $main->addChild('GoBookingCode', $_POST['bookingCode'] ?? '');

        $xmlRequest = $xml->asXML();

        $client = new SoapClient(WSDL, [
            'soap_version' => SOAP_1_2,
            'trace' => 1,
            'exceptions' => true,
            'location' => ENDPOINT
        ]);

        $params = [
            'requestType' => '4',
            'xmlRequest' => $xmlRequest
        ];

        $response = $client->MakeRequest($params);

        $finalResult = [];
    
        if (is_object($response) && property_exists($response, 'MakeRequestResult')) {
        $result = $response->MakeRequestResult;
        
        if (is_string($result)) {
            // Try JSON first
            $jsonData = json_decode($result, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $finalResult = $jsonData;
            } else {
                // Convert XML to JSON
                $xml = simplexml_load_string($result);
                if ($xml !== false) {
                    $rooms = [];
                    
                    // Parse multiple rooms
                    if (isset($xml->Main->Rooms->RoomType)) {
                        foreach ($xml->Main->Rooms->RoomType as $roomType) {
                            $roomData = [
                                'type' => (string)$roomType['Type'],
                                'adults_count' => (int)$roomType['Adults'],
                                'rooms' => []
                            ];
                            
                            // Handle multiple rooms within the same room type
                            foreach ($roomType->Room as $room) {
                                $adults = [];
                                $children = [];
                                
                                // Parse adult guests
                                foreach ($room->PersonName as $person) {
                                    $adult = [
                                        'person_id' => (string)$person['PersonID'],
                                        'first_name' => (string)$person->FirstName,
                                        'last_name' => (string)$person->LastName,
                                        'title' => (string)$person->Title
                                    ];
                                    $adults[] = $adult;
                                }
                                
                                // Parse children (extra beds)
                                foreach ($room->ExtraBed as $extraBed) {
                                    $child = [
                                        'person_id' => (string)$extraBed['PersonID'],
                                        'child_age' => (string)$extraBed['ChildAge'],
                                        'first_name' => (string)$extraBed->FirstName,
                                        'last_name' => (string)$extraBed->LastName,
                                        'type' => 'extra_bed'
                                    ];
                                    $children[] = $child;
                                }
                                
                                $roomInfo = [
                                    'room_id' => (string)$room['RoomID'],
                                    'category' => (string)$room['Category'],
                                    'adults' => $adults,
                                    'children' => $children,
                                    'total_adults' => count($adults),
                                    'total_children' => count($children)
                                ];
                                
                                $roomData['rooms'][] = $roomInfo;
                            }
                            
                            $rooms[] = $roomData;
                        }
                    }

                    //error_log("Hotel Id: " . (string)$xml->Main->HotelId);
                    $fetch_hotel_details = $this->fetchSingleHotelInfo((string)$xml->Main->HotelId);
                    //error_log("fetch_hotel_details: " . print_r($fetch_hotel_details, true));
                    
                    $finalResult = [
                        'booking_code' => (string)$xml->Main->GoBookingCode,
                        'booking_reference' => (string)$xml->Main->GoReference,
                        'client_booking_code' => (string)$xml->Main->ClientBookingCode,
                        'booking_status' => (string)$xml->Main->BookingStatus,
                        'total_price' => (string)$xml->Main->TotalPrice,
                        'currency' => (string)$xml->Main->Currency,
                        'check_in' => (string)$xml->Main->ArrivalDate,
                        'check_out' => date('Y-m-d', strtotime((string)$xml->Main->ArrivalDate . ' + ' . (int)$xml->Main->Nights . ' days')),
                        'nights' => (int)$xml->Main->Nights,
                        'cancellation_deadline' => (string)$xml->Main->CancellationDeadline,
                        'hotel_name' => (string)$xml->Main->HotelName,
                        'hotel_id' => (string)$xml->Main->HotelId,
                        'city_code' => (string)$xml->Main->CityCode,
                        'room_basis' => (string)$xml->Main->RoomBasis,
                        'address' => $fetch_hotel_details['address'] ?? '',
                        'leader' => [
                            'name' => (string)$xml->Main->Leader,
                            'person_id' => (string)$xml->Main->Leader['LeaderPersonID'] ?? null
                        ],
                        'rooms' => $rooms,
                        'remarks' => (string)$xml->Main->Remark,
                        'total_rooms' => count($rooms[0]['rooms'] ?? []),
                        'summary' => [
                            'total_adults' => array_sum(array_map(function($roomType) {
                                return array_sum(array_map(function($room) {
                                    return $room['total_adults'];
                                }, $roomType['rooms']));
                            }, $rooms)),
                            'total_children' => array_sum(array_map(function($roomType) {
                                return array_sum(array_map(function($room) {
                                    return $room['total_children'];
                                }, $roomType['rooms']));
                            }, $rooms))
                        ]
                    ];
                    
                } else {
                    $finalResult = ['error' => 'Unable to parse response', 'raw' => $result];
                }
            }
        }
    }

    error_log("Response :" . print_r(json_encode($finalResult), true));
    return json_encode($finalResult);
    }

    private function formatTitle($title) {
        $titleMap = [
            'mr' => 'MR.',
            'mrs' => 'MRS.',
            'ms' => 'MS.',
            'dr' => 'DR.',
            'child' => 'CHD',
            'master' => 'MSTR',
            'miss' => 'MISS'
        ];
        
        return $titleMap[strtolower($title)] ?? 'MR.';
    }


    public function fetchBookingEvaluation($xmlRequest) {
        $client = new SoapClient(WSDL, [
            'soap_version' => SOAP_1_2,
            'trace' => 1,
            'exceptions' => true,
            'location' => ENDPOINT
        ]);

        $params = [
            'requestType' => '9',
            'xmlRequest' => $xmlRequest
        ];

        $response = $client->MakeRequest($params);
        return $response;
    }

    public function fetchPriceBreakDown($xmlRequest) {
        $client = new SoapClient(WSDL, [
            'soap_version' => SOAP_1_2,
            'trace' => 1,
            'exceptions' => true,
            'location' => ENDPOINT
        ]);

        $params = [
            'requestType' => '14',
            'xmlRequest' => $xmlRequest
        ];

        $response = $client->MakeRequest($params);
        //error_log("Price Breakdown :" . print_r($response, true));
        return $response;
    }

    public function sendBookingRequest($xmlRequest) {
        $client = new SoapClient(WSDL, [
            'soap_version' => SOAP_1_2,
            'trace' => 1,
            'exceptions' => true,
            'location' => ENDPOINT
        ]);

        $params = [
            'requestType' => '2',
            'xmlRequest' => $xmlRequest
        ];

        $response = $client->MakeRequest($params);
        return $response;
    }

    private function processBookingResponse($response, $email) {
        if (is_object($response) && property_exists($response, 'MakeRequestResult')) {
            $result = $response->MakeRequestResult;

            try {
                $xml = simplexml_load_string($result);
                
                if ($xml === false) {
                    return json_encode([
                        'success' => false,
                        'error' => 'Invalid response from booking service'
                    ]);
                }

                $rooms = [];
                $finalResult = [];
                
                // Parse multiple rooms
                if (isset($xml->Main->Rooms->RoomType)) {
                    foreach ($xml->Main->Rooms->RoomType as $roomType) {
                        $roomData = [
                            'type' => (string)$roomType['Type'] ?? '',
                            'adults_count' => (int)$roomType['Adults'],
                            'rooms' => []
                        ];
                        
                        // Handle multiple rooms within the same room type
                        foreach ($roomType->Room as $room) {
                            $adults = [];
                            $children = [];
                            
                            // Parse adult guests - USING ATTRIBUTES
                            foreach ($room->PersonName as $person) {
                                $adult = [
                                    'person_id' => (string)$person['PersonID'],
                                    'first_name' => (string)$person['FirstName'], // Access attribute, not child element
                                    'last_name' => (string)$person['LastName'],   // Access attribute, not child element
                                    'title' => (string)$person['Title']           // Access attribute, not child element
                                ];
                                $adults[] = $adult;
                            }
                            
                            // Parse children (extra beds) - USING ATTRIBUTES
                            foreach ($room->ExtraBed as $extraBed) {
                                $child = [
                                    'person_id' => (string)$extraBed['PersonID'],
                                    'child_age' => (string)$extraBed['ChildAge'],
                                    'first_name' => (string)$extraBed['FirstName'], // Access attribute, not child element
                                    'last_name' => (string)$extraBed['LastName'],   // Access attribute, not child element
                                    'type' => 'extra_bed'
                                ];
                                $children[] = $child;
                            }
                            
                            $roomInfo = [
                                'room_id' => (string)$room['RoomID'],
                                'category' => (string)$room['Category'],
                                'adults' => $adults,
                                'children' => $children,
                                'total_adults' => count($adults),
                                'total_children' => count($children)
                            ];
                            
                            $roomData['rooms'][] = $roomInfo;
                        }
                        
                        $rooms[] = $roomData;
                    }
                }

                // Fetch hotel details
                $fetch_hotel_details = $this->fetchSingleHotelInfo((string)$xml->Main->HotelId);
                
                $finalResult = [
                    'booking_code' => (string)$xml->Main->GoBookingCode,
                    'booking_reference' => (string)$xml->Main->GoReference,
                    'client_booking_code' => (string)$xml->Main->ClientBookingCode,
                    'booking_status' => (string)$xml->Main->BookingStatus,
                    'total_price' => (string)$xml->Main->TotalPrice,
                    'currency' => (string)$xml->Main->Currency,
                    'check_in' => (string)$xml->Main->ArrivalDate,
                    'check_out' => date('Y-m-d', strtotime((string)$xml->Main->ArrivalDate . ' + ' . (int)$xml->Main->Nights . ' days')),
                    'nights' => (int)$xml->Main->Nights,
                    'cancellation_deadline' => (string)$xml->Main->CancellationDeadline,
                    'hotel_name' => (string)$xml->Main->HotelName,
                    'hotel_id' => (string)$xml->Main->HotelId,
                    'city_code' => (string)$xml->Main->CityCode ?? '', // Added default
                    'room_basis' => (string)$xml->Main->RoomBasis,
                    'address' => $fetch_hotel_details['address'] ?? '',
                    'leader' => [
                        'name' => (string)$xml->Main->Leader,
                        'person_id' => (string)$xml->Main->Leader['LeaderPersonID'] ?? null
                    ],
                    'rooms' => $rooms,
                    'remarks' => (string)$xml->Main->Remark,
                    'total_rooms' => count($rooms),
                    'summary' => [
                        'total_adults' => array_sum(array_map(function($roomType) {
                            return array_sum(array_map(function($room) {
                                return $room['total_adults'];
                            }, $roomType['rooms']));
                        }, $rooms)),
                        'total_children' => array_sum(array_map(function($roomType) {
                            return array_sum(array_map(function($room) {
                                return $room['total_children'];
                            }, $roomType['rooms']));
                        }, $rooms))
                    ]
                ];

                error_log("Final Result: " . print_r($finalResult, true));
                
                // Rest of your status checking code remains the same...
                if (isset($xml->Main->BookingStatus)) {
                    $statusCode = (string)$xml->Main->BookingStatus;
                    
                    $statusMap = [
                        'RQ' => 'Requested',
                        'C' => 'Confirmed',
                        'RX' => 'Requested Cancellation',
                        'XP' => 'Cancelled with Penalty Charges',
                        'RJ' => 'Rejected',
                        'VCH' => 'Voucher Issued',
                        'VRQ' => 'Voucher Requested'
                    ];
                    
                    $statusMessage = $statusMap[$statusCode] ?? 'Unknown Status';
                    
                    $successStatuses = ['C', 'VCH'];
                    $pendingStatuses = ['RQ', 'VRQ'];
                    $cancelledStatuses = ['RX', 'XP'];
                    $failedStatuses = ['RJ'];
                    
                    if (in_array($statusCode, $successStatuses)) {
                        $this->processEmailSending($finalResult, $email);
                        return json_encode([
                            'success' => true,
                            'booking_reference' => (string)($xml->Main->GoBookingCode ?? ''),
                            'status_code' => $statusCode,
                            'status' => $statusMessage,
                            'message' => $this->getSuccessMessage($statusCode)
                        ]);
                    } elseif (in_array($statusCode, $pendingStatuses)) {
                        $this->processEmailSending($finalResult, $email);
                        return json_encode([
                            'success' => true,
                            'booking_reference' => (string)($xml->Main->GoBookingCode ?? ''),
                            'status_code' => $statusCode,
                            'status' => $statusMessage,
                            'message' => $this->getPendingMessage($statusCode),
                            'pending' => true
                        ]);
                    } elseif (in_array($statusCode, $cancelledStatuses)) {
                        return json_encode([
                            'success' => false,
                            'status_code' => $statusCode,
                            'status' => $statusMessage,
                            'error' => $this->getCancelledMessage($statusCode),
                            'cancelled' => true
                        ]);
                    } elseif (in_array($statusCode, $failedStatuses)) {
                        return json_encode([
                            'success' => false,
                            'status_code' => $statusCode,
                            'status' => $statusMessage,
                            'error' => $this->getFailedMessage($statusCode)
                        ]);
                    } else {
                        return json_encode([
                            'success' => false,
                            'status_code' => $statusCode,
                            'status' => $statusMessage,
                            'error' => 'Unknown booking status: ' . $statusCode
                        ]);
                    }
                } else {
                    return json_encode([
                        'success' => false,
                        'error' => 'Unexpected response format - BookingStatus not found'
                    ]);
                }
                
            } catch (Exception $e) {
                return json_encode([
                    'success' => false,
                    'error' => 'Failed to process booking response: ' . $e->getMessage()
                ]);
            }
        }
    }

    /**
     * Get success message based on status code
     */
    private function getSuccessMessage($statusCode) {
        $messages = [
            'C' => 'Booking confirmed successfully! Your reservation has been confirmed.',
            'VCH' => 'Voucher issued successfully! Your booking voucher has been generated.'
        ];
        
        return $messages[$statusCode] ?? 'Booking processed successfully.';
    }

    /**
     * Get pending message based on status code
     */
    private function getPendingMessage($statusCode) {
        $messages = [
            'RQ' => 'Booking request submitted successfully. Your booking is pending confirmation from the hotel.',
            'VRQ' => 'Voucher request submitted successfully. Your voucher is being processed.'
        ];
        
        return $messages[$statusCode] ?? 'Your booking request is being processed.';
    }

    /**
     * Get cancelled message based on status code
     */
    private function getCancelledMessage($statusCode) {
        $messages = [
            'RX' => 'Cancellation request has been submitted. Please check your email for cancellation details.',
            'XP' => 'Booking has been cancelled with penalty charges. Please contact customer service for more information.'
        ];
        
        return $messages[$statusCode] ?? 'Booking cancellation processed.';
    }

    /**
     * Get failed message based on status code
     */
    private function getFailedMessage($statusCode) {
        $messages = [
            'RJ' => 'Booking was rejected by the hotel. Please try alternative dates or contact customer service.'
        ];
        
        return $messages[$statusCode] ?? 'Booking request failed.';
    }

    public function processEmailSending($response, $email){
        $emailContent = $this->generateBookingEmailTemplate($response);
        $this->sendmail($email, $email, $emailContent, 'Booking Confirmation');
        error_log("Email sent to: " . $email);
    }

    public function generateBookingEmailTemplate($bookingData) {
		
        // Extract data with defaults (ensure $bookingData is already an array)
        $bookingCode = htmlspecialchars($bookingData['booking_code'] ?? '');
        $bookingReference = htmlspecialchars($bookingData['booking_reference'] ?? '');
        $clientBookingCode = htmlspecialchars($bookingData['client_booking_code'] ?? '');
        $totalPrice = htmlspecialchars($bookingData['total_price'] ?? '');
        $currency = htmlspecialchars($bookingData['currency'] ?? '');
        $checkIn = htmlspecialchars($bookingData['check_in'] ?? '');
        $checkOut = htmlspecialchars($bookingData['check_out'] ?? '');
        $nights = htmlspecialchars($bookingData['nights'] ?? '');
        $cancellationDeadline = htmlspecialchars($bookingData['cancellation_deadline'] ?? '');
        $hotelName = htmlspecialchars($bookingData['hotel_name'] ?? '');
        $address = htmlspecialchars($bookingData['address'] ?? '');
        $roomBasis = htmlspecialchars($bookingData['room_basis'] ?? '');
        $remarks = $bookingData['remarks'] ?? '';
        $totalRooms = htmlspecialchars($bookingData['total_rooms'] ?? '');
        $totalAdults = htmlspecialchars($bookingData['summary']['total_adults'] ?? '');
        $totalChildren = htmlspecialchars($bookingData['summary']['total_children'] ?? '');
        $rooms = $bookingData['rooms'] ?? [];

        // Brand color
        $brand = '#3644fd';

        ob_start();
        ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width">
    <title>Booking Confirmation - <?php echo $bookingCode; ?></title>
    </head>
    <body style="margin:0;padding:0;background-color:#f2f4f8;font-family:Arial,Helvetica,sans-serif;-webkit-text-size-adjust:100%;-ms-text-size-adjust:100%;">

    <!-- Outer wrapper -->
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;background-color:#f2f4f8;width:100%;">
        <tr>
        <td align="center" style="padding:20px;">
            <!-- Centered container (max-width 600) -->
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;max-width:600px;width:100%;background-color:#ffffff;border-radius:10px;overflow:hidden;box-shadow:0 2px 6px rgba(0,0,0,0.06);">
            
            <!-- Header -->
            <tr>
                <td style="background-color:<?php echo $brand; ?>;padding:22px 18px;text-align:center;color:#ffffff;">
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;">
                    <tr>
                    <td style="text-align:center;padding-bottom:8px;">
                        <!-- Logo placeholder -->
                        <div style="display:inline-block;width:140px;height:44px;background-color:rgba(255,255,255,0.12);border-radius:6px;border:1px dashed rgba(255,255,255,0.2);line-height:44px;color:rgba(255,255,255,0.9);font-weight:600;">
                            YOUR LOGO
                        </div>
                    </td>
                    </tr>
                    <tr>
                    <td style="text-align:center;padding-top:8px;">
                        <span style="display:inline-block;background-color:#00a86b;color:#ffffff;padding:8px 18px;border-radius:20px;font-weight:600;font-size:13px;">
                            Booking Confirmed
                        </span>
                    </td>
                    </tr>
                    <tr>
                    <td style="padding-top:12px;text-align:center;font-size:14px;color:rgba(255,255,255,0.95);">
                        Code: <?php echo $bookingCode; ?>
                    </td>
                    </tr>
                    <tr>
                    <td style="padding-top:10px;text-align:center;font-size:20px;font-weight:700;line-height:1.2;color:#ffffff;">
                        <?php echo $hotelName; ?>
                    </td>
                    </tr>
                </table>
                </td>
            </tr>

            <!-- Body -->
            <tr>
                <td style="padding:18px;">
                <!-- Booking Overview (light card) -->
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;">
                    <tr>
                    <td style="background:#f8f9fc;border:1px solid #e6e9f2;border-radius:8px;padding:14px;">
                        <div style="font-weight:600;color:#2f3540;font-size:15px;margin-bottom:8px;">Booking Overview</div>
                        <div style="font-size:13px;color:#55606a;margin-bottom:10px;"><strong>Address:</strong> <?php echo $address ?: 'Address not available'; ?></div>

                        <!-- Overview grid using table -->
                        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;">
                        <tr>
                            <td style="width:20%;padding:6px;vertical-align:top;text-align:center;">
                            <div style="font-weight:600;color:#222;font-size:14px;"><?php echo $checkIn; ?></div>
                            <div style="font-size:11px;color:#7b8690;margin-top:3px;">Check-in</div>
                            </td>
                            <td style="width:20%;padding:6px;vertical-align:top;text-align:center;">
                            <div style="font-weight:600;color:#222;font-size:14px;"><?php echo $checkOut; ?></div>
                            <div style="font-size:11px;color:#7b8690;margin-top:3px;">Check-out</div>
                            </td>
                            <td style="width:20%;padding:6px;vertical-align:top;text-align:center;">
                            <div style="font-weight:600;color:#222;font-size:14px;"><?php echo $nights; ?> night<?php echo ($nights == 1 ? '' : 's'); ?></div>
                            <div style="font-size:11px;color:#7b8690;margin-top:3px;">Duration</div>
                            </td>
                            <td style="width:20%;padding:6px;vertical-align:top;text-align:center;">
                            <div style="font-weight:600;color:#222;font-size:14px;"><?php echo $totalAdults; ?></div>
                            <div style="font-size:11px;color:#7b8690;margin-top:3px;">Adults</div>
                            </td>
                            <td style="width:20%;padding:6px;vertical-align:top;text-align:center;">
                            <div style="font-weight:600;color:#222;font-size:14px;"><?php echo $totalRooms; ?></div>
                            <div style="font-size:11px;color:#7b8690;margin-top:3px;">Rooms</div>
                            </td>
                        </tr>
                        </table>
                    </td>
                    </tr>
                </table>

                <!-- Spacer -->
                <div style="height:14px;"></div>

                <!-- Room Details -->
				<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;">
					<tr>
					<td style="padding-bottom:6px;font-weight:600;color:#2f3540;font-size:15px;border-bottom:1px solid #eef2fb;padding-bottom:10px;">
						Room Details (<?php echo $totalRooms; ?>)
					</td>
					</tr>
					<tr>
					<td style="padding-top:12px;">
						<?php if (!empty($rooms)): ?>
						<?php 
						$roomCounter = 1;
						foreach ($rooms as $roomTypeIndex => $roomType): ?>
							<?php foreach ($roomType['rooms'] as $roomIndex => $room): ?>
								<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;margin-bottom:12px;">
								<tr>
									<td style="background:#fafbff;border:1px solid #e8ecff;border-radius:8px;padding:12px;">
									<!-- Room header -->
									<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;">
										<tr>
										<td style="vertical-align:top;">
											<div style="font-weight:600;color:#1f2430;font-size:14px;">
											Room <?php echo $roomCounter; ?>: <?php echo htmlspecialchars($room['category'] ?? ''); ?>
											</div>
											<div style="color:#6b7280;font-size:12px;margin-top:6px;">
											Adults: <?php echo $room['total_adults']; ?>
											<?php if (!empty($room['total_children'])): ?>
												| Children: <?php echo $room['total_children']; ?>
											<?php endif; ?>
											| Basis: <?php echo $roomBasis; ?>
											</div>
										</td>
										</tr>
										<tr>
										<td style="padding-top:10px;">
											<!-- Guests as stacked rows for best email behaviour -->
											<?php
											$guestLines = [];
											
											// Add adults for this room
											if (!empty($room['adults'])) {
												foreach ($room['adults'] as $adultIndex => $adult) {
													$leader = ($roomCounter === 1 && $adultIndex === 0) ? ' <span style="display:inline-block;background:#00a86b;color:#ffffff;padding:2px 6px;border-radius:4px;font-size:11px;margin-left:6px;">Leader</span>' : '';
													$guestLines[] = '<div style="padding:8px 10px;background:#ffffff;border:1px solid #eef2f7;border-radius:6px;margin-bottom:8px;font-size:13px;color:#222;">' . htmlspecialchars($adult['title']) . ' ' . htmlspecialchars($adult['first_name']) . ' ' . htmlspecialchars($adult['last_name']) . $leader . '<div style="font-size:12px;color:#6b7280;margin-top:4px;">Adult</div></div>';
												}
											}
											
											// Add children for this room
											if (!empty($room['children'])) {
												foreach ($room['children'] as $child) {
													$guestLines[] = '<div style="padding:8px 10px;background:#ffffff;border:1px solid #eef2f7;border-radius:6px;margin-bottom:8px;font-size:13px;color:#222;">' . htmlspecialchars($child['first_name']) . ' ' . htmlspecialchars($child['last_name']) . '<div style="font-size:12px;color:#6b7280;margin-top:4px;">Child, Age: ' . htmlspecialchars($child['child_age']) . '</div></div>';
												}
											}
											
											// Output guest items
											if (!empty($guestLines)) {
												foreach ($guestLines as $g) {
													echo $g;
												}
											} else {
												echo '<div style="color:#6b7280;font-size:13px;">No guest information provided.</div>';
											}
											?>
										</td>
										</tr>
									</table>
									</td>
								</tr>
								</table>
								<?php $roomCounter++; ?>
							<?php endforeach; ?>
						<?php endforeach; ?>
						<?php else: ?>
						<div style="padding:12px;background:#ffffff;border:1px solid #e8ecff;border-radius:8px;color:#6b7280;text-align:center;">No room information available.</div>
						<?php endif; ?>
					</td>
					</tr>
				</table>

                <!-- Pricing -->
                <div style="height:6px;"></div>
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;">
                    <tr>
                    <td style="font-weight:600;color:#2f3540;font-size:15px;padding-bottom:10px;border-bottom:1px solid #eef2fb;">Pricing</td>
                    </tr>
                    <tr>
                    <td style="padding-top:10px;">
                        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;font-size:13px;">
                        <tr>
                            <td style="padding:8px 0;color:#6b7280;">Room Rate (<?php echo $nights; ?> night<?php echo ($nights == 1 ? '' : 's'); ?>)</td>
                            <td style="padding:8px 0;text-align:right;font-weight:600;color:#111;"><?php echo $currency; ?> <?php echo $totalPrice; ?></td>
                        </tr>
                        <tr>
                            <td style="padding:8px 0;color:#6b7280;">Number of Adults</td>
                            <td style="padding:8px 0;text-align:right;font-weight:600;color:#111;"><?php echo $totalAdults; ?></td>
                        </tr>
                        <?php if ($totalChildren > 0): ?>
                        <tr>
                            <td style="padding:8px 0;color:#6b7280;">Number of Children</td>
                            <td style="padding:8px 0;text-align:right;font-weight:600;color:#111;"><?php echo $totalChildren; ?></td>
                        </tr>
                        <?php endif; ?>
                        <tr>
                            <td style="padding:8px 0;color:#6b7280;">Number of Rooms</td>
                            <td style="padding:8px 0;text-align:right;font-weight:600;color:#111;"><?php echo $totalRooms; ?></td>
                        </tr>
                        <tr>
                            <td style="padding-top:12px;border-top:2px solid #eef2fb;font-weight:700;font-size:15px;color:#111;">Total</td>
                            <td style="padding-top:12px;border-top:2px solid #eef2fb;text-align:right;font-weight:800;font-size:16px;color:<?php echo $brand; ?>;"><?php echo $currency; ?> <?php echo $totalPrice; ?></td>
                        </tr>
                        </table>
                    </td>
                    </tr>
                </table>

                <!-- Important Info -->
                <?php if (!empty($remarks)): ?>
                    <div style="height:12px;"></div>
                    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;">
                    <tr>
                        <td style="font-weight:600;color:#2f3540;font-size:15px;padding-bottom:10px;border-bottom:1px solid #eef2fb;">Important Info</td>
                    </tr>
                    <tr>
                        <td style="padding-top:12px;">
                        <div style="background:#fff8e6;padding:12px;border-radius:8px;border-left:4px solid #ffc107;">
                            <div style="font-size:12px;color:#6b7280;font-weight:700;text-transform:uppercase;margin-bottom:8px;">Policies & Remarks</div>
                            <div style="font-size:13px;color:#222;line-height:1.5;">
                                <?php 
                                    // Convert BR tags to proper line breaks and allow basic HTML
                                    $cleanRemarks = strip_tags($remarks, '<b><strong><i><em><ul><ol><li>');
                                    $cleanRemarks = str_replace(['<br>', '<br />', '<br/>'], "\n", $cleanRemarks);
                                    $cleanRemarks = nl2br($cleanRemarks);
                                    echo $cleanRemarks;
                                ?>
                            </div>
                        </div>
                        </td>
                    </tr>
                    </table>
                <?php endif; ?>

                <!-- References -->
                <div style="height:12px;"></div>
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;">
                    <tr>
                    <td style="font-weight:600;color:#2f3540;font-size:15px;padding-bottom:10px;border-bottom:1px solid #eef2fb;">References</td>
                    </tr>
                    <tr>
                    <td style="padding-top:12px;">
                        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;text-align:center;">
                        <tr>
                            <td style="width:33.33%;padding:6px;">
                            <div style="font-size:11px;color:#6b7280;margin-bottom:6px;">Booking Code</div>
                            <div style="font-weight:700;color:#111;word-break:break-all;"><?php echo $bookingCode; ?></div>
                            </td>
                            <td style="width:33.33%;padding:6px;">
                            <div style="font-size:11px;color:#6b7280;margin-bottom:6px;">Reference No.</div>
                            <div style="font-weight:700;color:#111;word-break:break-all;"><?php echo $bookingReference; ?></div>
                            </td>
                            <td style="width:33.33%;padding:6px;">
                            <div style="font-size:11px;color:#6b7280;margin-bottom:6px;">Client Ref.</div>
                            <div style="font-weight:700;color:#111;word-break:break-all;"><?php echo $clientBookingCode; ?></div>
                            </td>
                        </tr>
                        </table>
                    </td>
                    </tr>
                </table>

                </td>
            </tr>

            <!-- Footer -->
            <tr>
                <td style="background:#f8f9fc;border-top:1px solid #eef2fb;padding:16px;text-align:center;font-size:12px;color:#6b7280;">
                <div style="margin-bottom:8px;">Need help? <a href="mailto:support@yourcompany.com" style="color:<?php echo $brand; ?>;text-decoration:none;font-weight:600;">support@yourcompany.com</a></div>
                <div style="color:#98a0b2;line-height:1.4;font-size:12px;">
                    © <?php echo date('Y'); ?> Afotravels Limited. All rights reserved.<br>
                    This is an automated email — please do not reply.<br>
                    <strong style="color:#333;">Cancellation Deadline: <?php echo $cancellationDeadline; ?></strong>
                </div>
                </td>
            </tr>

            </table>
            <!-- End container -->
        </td>
        </tr>
    </table>
    </body>
    </html>
        <?php
        return ob_get_clean();
    }


    public function sendmail($email,$name,$body,$subject){

        require_once 'PHPMailer/src/Exception.php';
        require_once 'PHPMailer/src/PHPMailer.php';
        require_once 'PHPMailer/src/SMTP.php';

        $mail = new PHPMailer(true);
        
        try {

            $mail->SMTPDebug = 2; // Set to 2 for detailed connection info
            $mail->Debugoutput = function($str, $level) {
                error_log("PHPMailer Debug (Level $level): $str");
            };
            
            $mail->isSMTP();                           
            $mail->Host       = SMTP_HOST;      
            $mail->SMTPAuth   = true;
            $mail->SMTPKeepAlive = true; //SMTP connection will not close after each email sent, reduces SMTP overhead	
            $mail->Username   = SMTP_USERNAME;    
            $mail->Password   = SMTP_PASSWORD;             
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;   
            $mail->Port       = 465;               
    
            //Recipients
            $mail->setFrom(SMTP_FROM_EMAIL, 'Afohotels'); // Sender's email and name
            $mail->addAddress("$email", "");
            
            $mail->isHTML(true); 
            $mail->Subject = $subject;
            $mail->Body    = $body;
    
            $mail->send();
            $mail->clearAddresses();
            //return true;

            error_log('Email sent successfully');
            
        } catch (Exception $e){
            return "Message could not be sent. Mailer Error: {$mail->ErrorInfo}";
        }
    }

// Usage example:
// $emailContent = generateBookingConfirmationEmail($bookingData);
// Then send the email using your preferred method (PHPMailer, etc.)





}

?>