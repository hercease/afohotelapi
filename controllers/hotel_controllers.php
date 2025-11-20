<?php
class HotelControllers{

    private $hotelModels;
    public function __construct() {
        // Constructor code here, if needed
        $this->hotelModels = new HotelModels();
    }

    public function searchHotels() {
        $xmlRequest = $this->hotelModels->buildXmlRequest($_POST);
        return $this->hotelModels->ProcessRequest($xmlRequest, isset($_POST['page']) ? (int)$_POST['page'] : 1);
    }

    public function bookingEvaluation() {
        $hotelCode = $_POST['hotelCode'] ?? '';
        $checkIn = $_POST['checkIn'] ?? '';
        $hotelSearchCode = $_POST['hotelSearchCode'] ?? '';
        return $this->hotelModels->processBookingEvaluation($hotelCode, $checkIn, $hotelSearchCode);
        //error_log("Response: " . print_r($response, true));
    }

    public function priceBreakDown() {
        $hotelSearchCode = $_POST['hotelSearchCode'] ?? '';
        return $this->hotelModels->processPriceBreakDown($hotelSearchCode);
    }


    public function hotelBooking(){
          $requiredFields = ['hotelSearchCode', 'checkInDate', 'guestLeaderEmail'];
    
            foreach ($requiredFields as $field) {
                if (empty($_POST[$field])) {
                    return json_encode([
                        'success' => false,
                        'error' => "Missing required field: $field"
                    ]);
                }
            }
            
            // Validate terms acceptance
            if (!isset($_POST['termsAccepted']) || $_POST['termsAccepted'] !== 'true') {
                return json_encode([
                    'success' => false,
                    'error' => 'You must accept the terms and conditions'
                ]);
            }
        return $this->hotelModels->processHotelBooking();
    }


    public function bookingDetails() {
        $requiredFields = ['bookingCode'];
    
            foreach ($requiredFields as $field) {
                if (empty($_POST[$field])) {
                    return json_encode([
                        'success' => false,
                        'error' => "Missing required field: $field"
                    ]);
                }
            }
        return $this->hotelModels->fetchBookingDetails();
    }
}
