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
}
