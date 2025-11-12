<?php
class HotelModels {

    private $perPage = 6;
    public function __construct() {
        // Constructor code here, if needed
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
            error_log("Hotel info error for {$hotelCode}: " . $e->getMessage());
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
                        foreach ($xml->Main->Pictures->Picture as $picture) {
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
                    $breakdownArray = [];
                    
                    // Extract all price breakdowns
                    foreach ($xml->Main->Room->PriceBreakdown as $breakdown) {
                        $breakdownArray[] = [
                            'from_date' => (string)$breakdown->FromDate,
                            'to_date' => (string)$breakdown->ToDate,
                            'price' => (float)$breakdown->Price,
                            'currency' => (string)$breakdown->Currency
                        ];
                    }
                    
                    $finalResult = [
                        'success' => true,
                        'price_breakdown' => $breakdownArray,
                        'hotel_name' => (string)$xml->Main->HotelName,
                        'room_type' => (string)$xml->Main->Room->RoomType
                    ];
                } else {
                    $finalResult = ['error' => 'Unable to parse XML response'];
                }
            }

            return json_encode($finalResult);
        } else {
            return json_encode(['error' => 'Unable to fetch price breakdown']);
        }

        
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
        error_log("Price Breakdown :" . print_r($response, true));
        return $response;
    }




}

?>